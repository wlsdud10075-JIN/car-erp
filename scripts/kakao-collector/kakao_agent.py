"""
카톡 에이전트 — 실무자 PC 에 창 없이 떠 있는 로컬 서버 (docs/design/kakao-automation.md §11 · jin 11-10 «사용 화면은 ERP 하나»)

ERP 「내 카톡 할 일」 페이지의 브라우저 JS 가 http://127.0.0.1:47321 로 묻는다:
  GET  /ping              연결 상태(버전·PC 이름·ERP 연결 여부). 비밀값 없이도 된다 — 페이지가 「어느 PC 인가」를 알아내는 용도
  GET  /rooms             지금 열려 있는 카톡 방 창 제목
  POST /read {rooms:[…]}  그 방들을 읽어 ERP 로 보낸다(백그라운드, 한 번에 하나). 닫힌 방은 카톡 메인 창 검색으로 열고 읽는다
  POST /focus {room, snippet?}  방을 열고(닫혀 있으면 연다) snippet 이 있으면 그 메시지 위치로(검색칸에 포커스가 있을 때만 키를 친다)
  POST /rebind {token, local_secret, erp_url}  이 PC 의 에이전트를 지금 로그인한 계정으로 다시 묶는다(.bat 없이)
  GET  /progress          읽기 진행 줄·끝났는지
  POST /quit              에이전트 종료(재설치·업데이트 때 .bat 이 부른다)
/rooms·/read·/focus·/progress·/quit 은 설치 때 심은 local_secret 을 X-Kakao-Secret 헤더로 받아야 한다. 요청 Origin 은 설정된 ERP 출처만.

🏢 회사 여러 곳(jin 11-10 «한 PC 에서 heyman, ssancar 둘 다 쓰면?») — 설정은 **회사(ERP 출처)마다 하나**:
  %LOCALAPPDATA%/KakaoReader/config.<호스트>.json (설치 .bat·/rebind 가 쓴다: erp_url·token·local_secret) + 옛 config.json(한 회사 시절)도 읽는다.
  요청의 Origin 으로 어느 회사인지 가려 그 회사의 토큰으로 ERP 에 보낸다. 상태(방별 마지막 읽은 시각)도 호스트별 state.<호스트>.json.
읽기 엔진 = collector.py 그대로. 카톡에는 아무것도 쓰지 않는다. 읽는 동안 키보드·마우스에서 손을 뗀다.

exe 만들기(개발 PC, 이 폴더에서): pyinstaller --noconfirm --onefile --noconsole --name KakaoAgent kakao_agent.py
"""
from __future__ import annotations

import json
import logging
import os
import re
import sys
import threading
from datetime import date, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from logging.handlers import RotatingFileHandler
from pathlib import Path
from urllib.parse import urlsplit

import requests

import collector as C

VERSION = "1.1.1"   # 1.1.1 = 설치 .bat 이 비밀값 없이 /quit 호출(권한 수준 달라 taskkill 못 죽일 때) · 1.1.0 = 회사별 설정
PORT = 47321

APP_DIR = Path(os.environ.get("LOCALAPPDATA") or Path.home()) / "KakaoReader"
APP_DIR.mkdir(parents=True, exist_ok=True)
LEGACY_CONFIG = APP_DIR / "config.json"
LOG = APP_DIR / "agent.log"
DEFAULTS = {"first_read_days": 7, "pageup_batch": 20, "max_pageups": 400, "click_fraction": [0.5, 0.35]}

log = logging.getLogger("kakao-agent")
log.setLevel(logging.INFO)
_h = RotatingFileHandler(LOG, maxBytes=512_000, backupCount=2, encoding="utf-8")
_h.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
log.addHandler(_h)


def load(path: Path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else default
    except Exception:
        return default


def save(path: Path, data) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def origin_of(url: str) -> str:
    p = urlsplit(url)
    return f"{p.scheme}://{p.netloc}".lower() if p.scheme and p.netloc else ""


def host_slug(url: str) -> str:
    """설정 파일 이름용 — heymancar.com → heymancar.com, localhost:8001 → localhost_8001"""
    return re.sub(r"[^A-Za-z0-9.-]+", "_", urlsplit(url).netloc or "erp")


class Agent:
    """상태 하나 — 회사별 연결(bindings), 진행 중인 읽기."""

    def __init__(self) -> None:
        self.bindings: dict[str, dict] = {}   # origin → {erp_url, token, local_secret, host, erp_state, erp_message, …DEFAULTS}
        self.device = C.device_name()
        self.lock = threading.Lock()
        self.busy = False
        self.lines: list[str] = []
        self.done = True
        self.started_at: str | None = None
        self.return_to = 0
        self.reload()

    # ───── 설정 ─────
    def reload(self) -> None:
        found: dict[str, dict] = {}
        files = [LEGACY_CONFIG] + sorted(APP_DIR.glob("config.*.json"))
        for f in files:
            cfg = load(f, {})
            if not isinstance(cfg, dict) or not cfg.get("token") or not cfg.get("erp_url"):
                continue
            o = origin_of(cfg["erp_url"])
            if not o:
                continue
            b = {**DEFAULTS, **cfg, "host": host_slug(cfg["erp_url"]), "file": str(f), "erp_state": "unknown", "erp_message": ""}
            found[o] = b   # config.<host>.json 이 옛 config.json 보다 뒤에 와서 같은 회사면 덮는다
        self.bindings = found
        log.info("bindings: %s", ", ".join(found) or "(none)")

    def binding_for(self, origin: str) -> dict | None:
        o = (origin or "").lower()
        if o == "":
            return next(iter(self.bindings.values()), None)   # Origin 없음 = 같은 PC 의 비브라우저 호출(curl 진단) → 첫 회사
        norm = lambda s: s.replace("://localhost", "://127.0.0.1")   # 개발 PC — localhost 와 127.0.0.1 은 같은 곳
        for k, b in self.bindings.items():
            if norm(k) == norm(o):
                return b
        return None

    def bind(self, erp_url: str, token: str, secret: str) -> dict:
        """회사 하나를 (다시) 묶는다 — config.<host>.json 에 쓰고 다시 읽는다. 다른 회사는 그대로."""
        cfg = {**DEFAULTS, "erp_url": erp_url.rstrip("/"), "token": token, "local_secret": secret}
        save(APP_DIR / f"config.{host_slug(erp_url)}.json", cfg)
        self.reload()
        b = self.binding_for(origin_of(erp_url))
        if b:
            self.check_erp(b)
        return b or {}

    # ───── ERP ─────
    def api(self, b: dict, method: str, path: str, **kw):
        kw.setdefault("timeout", 180)
        kw.setdefault("headers", {})
        kw["headers"].update({"Authorization": "Bearer " + b["token"], "Accept": "application/json", "X-Kakao-Device": self.device})
        r = requests.request(method, b["erp_url"].rstrip("/") + path, **kw)
        if r.status_code in (401, 403):
            b["erp_state"] = "unauthorized"
            try:
                b["erp_message"] = r.json().get("message", "")
            except ValueError:
                b["erp_message"] = f"HTTP {r.status_code}"
        else:
            b["erp_state"], b["erp_message"] = "connected", ""
        return r

    def check_erp(self, b: dict | None = None) -> None:
        for binding in ([b] if b else list(self.bindings.values())):
            try:
                self.api(binding, "GET", "/api/kakao/rooms", timeout=15)
            except requests.RequestException as e:
                binding["erp_state"], binding["erp_message"] = "offline", str(e)[:200]

    # ───── 읽기 ─────
    def say(self, text: str) -> None:
        with self.lock:
            self.lines.append(datetime.now().strftime("%H:%M ") + text)
        log.info(text)

    def start_read(self, b: dict, rooms: list[str]) -> bool:
        with self.lock:
            if self.busy:
                return False
            self.busy, self.done, self.lines, self.started_at = True, False, [], datetime.now().isoformat(timespec="seconds")
            self.return_to = C.u.GetForegroundWindow()   # [지금 읽기]를 누른 창(ERP 브라우저) — 끝나면 다시 맨 앞으로(jin 11-10)
        threading.Thread(target=self.read_worker, args=(b, rooms), daemon=True).start()
        return True

    def read_worker(self, b: dict, rooms: list[str]) -> None:
        state_file = APP_DIR / f"state.{b['host']}.json"
        try:
            if C.screen_locked():   # 잠금 화면에선 창을 앞으로 못 가져온다 — 「방을 못 찾음」으로 오해하지 않게 먼저 알린다(11-10 실측)
                self.say("PC 가 잠겨 있어 읽을 수 없습니다. 잠금을 풀고(원격이면 다시 접속해서) 다시 [지금 읽기].")
                return
            self.say(f"읽기 시작 — {len(rooms)}개 방. 끝날 때까지 키보드·마우스에서 손을 떼 주세요.")
            # 체크 상태는 ERP 페이지가 정본(toggleRoom) — 여기서 rooms/select 를 부르지 않는다(닫힌 방이 해제된다)
            state = load(state_file, {}) or load(APP_DIR / "state.json", {})   # 옛 state.json(한 회사 시절)도 처음엔 이어 쓴다
            for name in rooms:
                last = state.get(name)
                since = (datetime.fromisoformat(last).date() - timedelta(days=1)) if last else (date.today() - timedelta(days=int(b.get("first_read_days", 7))))
                try:
                    if not C.find_room_window(name):   # 닫혀 있는 방은 메인 창 검색으로 열고 읽는다(처음 한 번만 사람이 열어 둔다)
                        self.say(f"[{name}] 닫혀 있어 카톡에서 엽니다…")
                        if not C.open_room(name):
                            self.say(f"[{name}] 카톡에서 방을 찾지 못해 건너뜁니다(방 이름이 바뀌었거나 나간 방).")
                            continue
                    info = C.read_room(b, name, since, dry=False)
                except Exception as e:   # 창이 사라졌거나 클립보드 실패 — 다음 방으로
                    info = {"ok": False, "message": f"읽기 실패: {e}"}
                self.say(f"[{name}] {info.get('message') or info}")
                if info.get("status") in (401, 403):
                    b["erp_state"] = "unauthorized"
                if info.get("ok"):
                    state[name] = datetime.now().isoformat(timespec="minutes")
                    save(state_file, state)
            self.say("끝.")
        finally:
            with self.lock:
                self.busy, self.done = False, True
            back = self.return_to
            if back and C.u.IsWindow(back):   # ERP 화면을 다시 맨 앞으로(카톡 창들이 앞에 나와 있다)
                try:
                    C.focus(back)
                except Exception:
                    pass


AGENT = Agent()


class Handler(BaseHTTPRequestHandler):
    server_version = "KakaoAgent/" + VERSION
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):   # 콘솔 없음 — 파일 로그만
        log.debug(fmt, *args)

    # ─── 공통 ───
    @property
    def binding(self) -> dict | None:
        return AGENT.binding_for(self.headers.get("Origin") or "")

    def origin_ok(self) -> bool:
        origin = (self.headers.get("Origin") or "").lower()
        return origin == "" or self.binding is not None

    def cors(self) -> None:
        origin = self.headers.get("Origin")
        if origin:
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
            self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Kakao-Secret")
            self.send_header("Access-Control-Allow-Private-Network", "true")
            self.send_header("Access-Control-Max-Age", "600")

    def reply(self, code: int, body: dict) -> None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.cors()
        self.end_headers()
        self.wfile.write(data)

    def deny(self, code: int = 403) -> None:
        self.send_response(code)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def secret_ok(self) -> bool:
        b = self.binding
        want = (b or {}).get("local_secret") or ""
        return want != "" and self.headers.get("X-Kakao-Secret", "") == want

    def body(self) -> dict:
        n = int(self.headers.get("Content-Length") or 0)
        if n <= 0:
            return {}
        try:
            return json.loads(self.rfile.read(n).decode("utf-8")) or {}
        except (ValueError, UnicodeDecodeError):
            return {}

    def guard(self) -> bool:
        """Origin → 비밀값 순서로 거른다. 통과하면 True."""
        if not self.origin_ok():
            self.deny(403)
            return False
        if not self.secret_ok():
            self.reply(401, {"ok": False, "message": "이 PC 의 에이전트와 ERP 설정이 다릅니다. [이 PC 를 내 계정으로 연결] 또는 설정 탭에서 다시 연결하세요."})
            return False
        return True

    # ─── 라우팅 ───
    def do_OPTIONS(self) -> None:
        if not self.origin_ok():
            return self.deny(403)
        self.send_response(204)
        self.send_header("Content-Length", "0")
        self.cors()
        self.end_headers()

    def do_GET(self) -> None:
        path = self.path.split("?", 1)[0]
        if path == "/ping":
            if not self.origin_ok():
                return self.deny(403)
            b = self.binding
            return self.reply(200, {
                "ok": True, "version": VERSION, "device": AGENT.device, "erp_url": (b or {}).get("erp_url", ""),
                "erp_state": (b or {}).get("erp_state", "unconfigured"), "erp_message": (b or {}).get("erp_message", ""),
                "busy": AGENT.busy, "companies": [v["erp_url"] for v in AGENT.bindings.values()],
            })
        if not self.guard():
            return
        if path == "/rooms":
            try:
                rooms = C.open_room_titles()
            except Exception as e:
                return self.reply(500, {"ok": False, "message": f"카톡 창을 읽지 못했습니다: {e}"})
            return self.reply(200, {"ok": True, "rooms": rooms, "kakao_running": bool(C.kakao_pids())})
        if path == "/progress":
            with AGENT.lock:
                return self.reply(200, {"ok": True, "busy": AGENT.busy, "done": AGENT.done, "lines": list(AGENT.lines), "started_at": AGENT.started_at, "erp_state": (self.binding or {}).get("erp_state", "")})
        self.reply(404, {"ok": False, "message": "no such path"})

    def do_POST(self) -> None:
        path = self.path.split("?", 1)[0]
        if path == "/rebind":   # 비밀값 없이 — 새 회사를 더하는 것도 허용(같은 PC 의 ERP 페이지가 보낸 것 = .bat 재실행과 같은 효과). 출처 ≠ erp_url 이면 거부(아래)
            return self.do_POST_rebind()
        if path == "/quit" and not (self.headers.get("Origin") or ""):   # 같은 PC 의 비브라우저 호출(설치 .bat 의 curl)은 비밀값 없이 종료 가능 —
            self.reply(200, {"ok": True, "message": "bye"})                 # 권한 수준이 달라 taskkill 이 못 죽이는 경우(11-10 실측 «curl (23) write error») 대비. 브라우저 호출엔 Origin 이 늘 있어 안 열린다
            threading.Thread(target=self.server.shutdown, daemon=True).start()
            return
        if not self.guard():
            return
        b = self.binding
        if path == "/read":
            rooms = [str(r).strip() for r in (self.body().get("rooms") or []) if str(r).strip()]
            if not rooms:
                return self.reply(422, {"ok": False, "message": "읽을 방이 없습니다."})
            if not AGENT.start_read(b, rooms):
                return self.reply(409, {"ok": False, "message": "이미 읽는 중입니다."})
            return self.reply(202, {"ok": True, "started": True, "rooms": rooms})
        if path == "/focus":   # 「이 글 열기」 — 열려 있으면 앞으로, 닫혀 있으면 메인 창 검색으로 연다; snippet 이 있으면 그 메시지 위치로
            body = self.body()   # ⚠️ 본문은 한 번만 읽는다(두 번 읽으면 두 번째 read 가 영원히 기다린다)
            name = str(body.get("room") or "").strip()
            if not name:
                return self.reply(422, {"ok": False, "message": "방 이름이 없습니다."})
            if AGENT.busy:
                return self.reply(409, {"ok": False, "message": "읽는 중입니다. 끝난 뒤 다시 누르세요."})
            if C.screen_locked():
                return self.reply(409, {"ok": False, "message": "PC 가 잠겨 있어 카톡 창을 열 수 없습니다. 잠금을 풀고 다시 누르세요."})
            snippet = str(body.get("snippet") or "").strip()
            try:
                if snippet:
                    info = C.jump_to_message(name, snippet)
                    return self.reply(200 if info.get("ok") else 404, info)
                win = C.open_room(name)
            except Exception as e:
                return self.reply(500, {"ok": False, "message": f"카톡 창을 열지 못했습니다: {e}"})
            if not win:
                return self.reply(404, {"ok": False, "message": f"「{name}」 방을 카톡에서 찾지 못했습니다. 카톡이 로그인돼 있고 방 이름이 같은지 보세요."})
            return self.reply(200, {"ok": C.is_foreground(win["hwnd"]), "room": win["title"]})
        if path == "/quit":
            self.reply(200, {"ok": True, "message": "bye"})
            threading.Thread(target=self.server.shutdown, daemon=True).start()
            return
        self.reply(404, {"ok": False, "message": "no such path"})

    def do_POST_rebind(self) -> None:
        """이 PC 의 에이전트를 지금 ERP 에 로그인한 계정으로 (다시) 묶는다 — 회사마다 따로(config.<host>.json). 다른 회사 연결은 그대로."""
        b = self.body()
        token, secret, erp = str(b.get("token") or ""), str(b.get("local_secret") or ""), str(b.get("erp_url") or "")
        origin = (self.headers.get("Origin") or "").lower()
        if origin and erp and origin_of(erp).replace("://localhost", "://127.0.0.1") != origin.replace("://localhost", "://127.0.0.1"):
            return self.reply(403, {"ok": False, "message": "요청한 페이지의 출처와 erp_url 이 다릅니다."})   # 다른 사이트가 남의 회사 토큰을 심지 못하게
        if not token.startswith("kck_") or len(secret) < 16 or not erp:
            return self.reply(422, {"ok": False, "message": "token·local_secret·erp_url 이 필요합니다."})
        nb = AGENT.bind(erp, token, secret)
        log.info("rebound %s (device=%s) erp_state=%s", erp, AGENT.device, nb.get("erp_state"))
        self.reply(200, {"ok": nb.get("erp_state") == "connected", "erp_state": nb.get("erp_state"), "erp_message": nb.get("erp_message", ""), "device": AGENT.device, "version": VERSION, "companies": [v["erp_url"] for v in AGENT.bindings.values()]})


class Server(ThreadingHTTPServer):
    allow_reuse_address = False   # Windows 는 SO_REUSEADDR 이면 같은 포트에 두 번째가 그냥 붙는다 → 단일 인스턴스 판정이 안 된다
    daemon_threads = True


def main() -> int:
    try:
        srv = Server(("127.0.0.1", PORT), Handler)
    except OSError:
        log.info("port %s busy — another agent is running, exit", PORT)   # 시작프로그램 + 이미 떠 있음 → 조용히 종료
        return 0
    log.info("agent %s up on 127.0.0.1:%s (device=%s, companies=%s)", VERSION, PORT, AGENT.device, list(AGENT.bindings))
    threading.Thread(target=AGENT.check_erp, daemon=True).start()
    try:
        srv.serve_forever()
    finally:
        srv.server_close()
        log.info("agent down")
    return 0


if __name__ == "__main__":
    sys.exit(main())
