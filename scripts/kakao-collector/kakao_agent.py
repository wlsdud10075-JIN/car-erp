"""
카톡 에이전트 — 실무자 PC 에 창 없이 떠 있는 로컬 서버 (docs/design/kakao-automation.md §11 · jin 11-10 «사용 화면은 ERP 하나»)

ERP 「내 카톡 할 일」 페이지의 브라우저 JS 가 http://127.0.0.1:47321 로 묻는다:
  GET  /ping              연결 상태(버전·PC 이름·ERP 연결 여부). 비밀값 없이도 된다 — 페이지가 「어느 PC 인가」를 알아내는 용도
  GET  /rooms             지금 열려 있는 카톡 방 창 제목
  POST /read {rooms:[…]}  그 방들을 읽어 ERP 로 보낸다(백그라운드, 한 번에 하나)
  GET  /progress          읽기 진행 줄·끝났는지
  POST /quit              에이전트 종료(재설치·업데이트 때 .bat 이 부른다)
/rooms·/read·/progress·/quit 은 설치 때 심은 local_secret 을 X-Kakao-Secret 헤더로 받아야 한다. 요청 Origin 은 config 의 ERP 출처만 허용.

설정 = %LOCALAPPDATA%/KakaoReader/config.json (설치 .bat 이 쓴다: erp_url·token·local_secret). 상태 = 같은 폴더 state.json(방별 마지막 읽은 시각).
읽기 엔진 = collector.py 그대로. 카톡에는 아무것도 쓰지 않는다. 읽는 동안 키보드·마우스에서 손을 뗀다.

exe 만들기(개발 PC, 이 폴더에서): pyinstaller --noconfirm --onefile --noconsole --name KakaoAgent kakao_agent.py
"""
from __future__ import annotations

import json
import logging
import os
import sys
import threading
from datetime import date, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from logging.handlers import RotatingFileHandler
from pathlib import Path
from urllib.parse import urlsplit

import requests

import collector as C

VERSION = "1.0.0"
PORT = 47321

APP_DIR = Path(os.environ.get("LOCALAPPDATA") or Path.home()) / "KakaoReader"
APP_DIR.mkdir(parents=True, exist_ok=True)
CONFIG = APP_DIR / "config.json"
STATE = APP_DIR / "state.json"
LOG = APP_DIR / "agent.log"
C.CONFIG, C.STATE = CONFIG, STATE

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


class Agent:
    """상태 하나 — 설정, ERP 연결 상태, 진행 중인 읽기."""

    def __init__(self) -> None:
        self.cfg: dict = load(CONFIG, {})
        self.device = C.device_name()
        self.erp_state = "unconfigured" if not self.cfg.get("token") else "unknown"   # connected | unauthorized | offline | unconfigured
        self.erp_message = ""
        self.lock = threading.Lock()
        self.busy = False
        self.lines: list[str] = []
        self.done = True
        self.started_at: str | None = None

    # ───── ERP ─────
    @property
    def origin(self) -> str:
        p = urlsplit(self.cfg.get("erp_url", ""))
        return f"{p.scheme}://{p.netloc}".lower() if p.scheme and p.netloc else ""

    def api(self, method: str, path: str, **kw):
        kw.setdefault("timeout", 180)
        kw.setdefault("headers", {})
        kw["headers"].update({"Authorization": "Bearer " + self.cfg["token"], "Accept": "application/json", "X-Kakao-Device": self.device})
        r = requests.request(method, self.cfg["erp_url"].rstrip("/") + path, **kw)
        if r.status_code in (401, 403):
            self.erp_state = "unauthorized"
            try:
                self.erp_message = r.json().get("message", "")
            except ValueError:
                self.erp_message = f"HTTP {r.status_code}"
        else:
            self.erp_state = "connected"
            self.erp_message = ""
        return r

    def check_erp(self) -> None:
        if not self.cfg.get("token"):
            self.erp_state = "unconfigured"
            return
        try:
            self.api("GET", "/api/kakao/rooms", timeout=15)
        except requests.RequestException as e:
            self.erp_state = "offline"
            self.erp_message = str(e)[:200]

    # ───── 읽기 ─────
    def say(self, text: str) -> None:
        with self.lock:
            self.lines.append(datetime.now().strftime("%H:%M ") + text)
        log.info(text)

    def start_read(self, rooms: list[str]) -> bool:
        with self.lock:
            if self.busy:
                return False
            self.busy, self.done, self.lines, self.started_at = True, False, [], datetime.now().isoformat(timespec="seconds")
        threading.Thread(target=self.read_worker, args=(rooms,), daemon=True).start()
        return True

    def read_worker(self, rooms: list[str]) -> None:
        try:
            if C.screen_locked():   # 잠금 화면에선 창을 앞으로 못 가져온다 — 「방을 못 찾음」으로 오해하지 않게 먼저 알린다(11-10 실측)
                self.say("PC 가 잠겨 있어 읽을 수 없습니다. 잠금을 풀고(원격이면 다시 접속해서) 다시 [지금 읽기].")
                return
            self.say(f"읽기 시작 — {len(rooms)}개 방. 끝날 때까지 키보드·마우스에서 손을 떼 주세요.")
            # 체크 상태는 ERP 페이지가 정본(toggleRoom) — 여기서 rooms/select 를 부르면 「체크됐지만 지금 닫힌 방」이 해제된다(§11, exe 창 시절 잔재 제거)
            state = load(STATE, {})
            for name in rooms:
                last = state.get(name)
                since = (datetime.fromisoformat(last).date() - timedelta(days=1)) if last else (date.today() - timedelta(days=int(self.cfg.get("first_read_days", 7))))
                try:
                    # 닫혀 있는 방은 메인 창 검색으로 열고 읽는다(jin 11-10 «체크돼 있으면 그 방을 찾아서 열어주고 지금 읽기») — 처음 한 번만 사람이 열어 둔다(목록에 뜨게)
                    if not C.find_room_window(name):
                        self.say(f"[{name}] 닫혀 있어 카톡에서 엽니다…")
                        if not C.open_room(name):
                            self.say(f"[{name}] 카톡에서 방을 찾지 못해 건너뜁니다(방 이름이 바뀌었거나 나간 방).")
                            continue
                    info = C.read_room(self.cfg, name, since, dry=False)
                except Exception as e:   # 창이 사라졌거나 클립보드 실패 — 다음 방으로
                    info = {"ok": False, "message": f"읽기 실패: {e}"}
                self.say(f"[{name}] {info.get('message') or info}")
                if info.get("status") in (401, 403):
                    self.erp_state = "unauthorized"
                if info.get("ok"):
                    state[name] = datetime.now().isoformat(timespec="minutes")
                    save(STATE, state)
            self.say("끝.")
        finally:
            with self.lock:
                self.busy, self.done = False, True


AGENT = Agent()


class Handler(BaseHTTPRequestHandler):
    server_version = "KakaoAgent/" + VERSION
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):   # 콘솔 없음 — 파일 로그만
        log.debug(fmt, *args)

    # ─── 공통 ───
    def origin_ok(self) -> bool:
        origin = (self.headers.get("Origin") or "").lower()
        if origin == "":   # Origin 없음 = 같은 PC 의 비브라우저 호출(curl 진단)
            return True
        want = AGENT.origin
        # 개발 PC 전용 — localhost 와 127.0.0.1 은 같은 곳으로 본다(ERP 를 어느 주소로 열었든). 운영은 https 도메인이라 해당 없음
        norm = lambda o: o.replace("://localhost", "://127.0.0.1")
        return want != "" and norm(origin) == norm(want)

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

    def secret_ok(self) -> bool:
        want = AGENT.cfg.get("local_secret") or ""
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
            self.send_response(403)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return False
        if not self.secret_ok():
            self.reply(401, {"ok": False, "message": "이 PC 의 에이전트와 ERP 설정이 다릅니다. 설정 탭에서 다시 연결하세요."})
            return False
        return True

    # ─── 라우팅 ───
    def do_OPTIONS(self) -> None:
        if not self.origin_ok():
            self.send_response(403)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        self.send_response(204)
        self.send_header("Content-Length", "0")
        self.cors()
        self.end_headers()

    def do_GET(self) -> None:
        path = self.path.split("?", 1)[0]
        if path == "/ping":
            if not self.origin_ok():
                self.send_response(403)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            return self.reply(200, {
                "ok": True, "version": VERSION, "device": AGENT.device, "erp_url": AGENT.cfg.get("erp_url", ""),
                "erp_state": AGENT.erp_state, "erp_message": AGENT.erp_message, "busy": AGENT.busy,
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
                return self.reply(200, {"ok": True, "busy": AGENT.busy, "done": AGENT.done, "lines": list(AGENT.lines), "started_at": AGENT.started_at, "erp_state": AGENT.erp_state})
        self.reply(404, {"ok": False, "message": "no such path"})

    def do_POST(self) -> None:
        path = self.path.split("?", 1)[0]
        if path == "/rebind":   # 비밀값 없이 — Origin 만 본다(위 docstring)
            if not self.origin_ok():
                self.send_response(403)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            return self.do_POST_rebind()
        if not self.guard():
            return
        if path == "/read":
            rooms = [str(r).strip() for r in (self.body().get("rooms") or []) if str(r).strip()]
            if not rooms:
                return self.reply(422, {"ok": False, "message": "읽을 방이 없습니다."})
            if AGENT.erp_state == "unconfigured":
                return self.reply(409, {"ok": False, "message": "이 PC 가 ERP 와 연결돼 있지 않습니다. 설정 탭에서 연결하세요."})
            if not AGENT.start_read(rooms):
                return self.reply(409, {"ok": False, "message": "이미 읽는 중입니다."})
            return self.reply(202, {"ok": True, "started": True, "rooms": rooms})
        if path == "/focus":   # 「카톡에서 이 방 열기」(§6 [⋯] 보기) — 열려 있으면 앞으로, 닫혀 있으면 메인 창 검색으로 연다(collector.open_room)
            b = self.body()   # ⚠️ 본문은 한 번만 읽는다 — 두 번 읽으면 두 번째 read 가 영원히 기다린다(11-10 /focus 60초 멈춤의 원인)
            name = str(b.get("room") or "").strip()
            if not name:
                return self.reply(422, {"ok": False, "message": "방 이름이 없습니다."})
            if AGENT.busy:
                return self.reply(409, {"ok": False, "message": "읽는 중입니다. 끝난 뒤 다시 누르세요."})
            if C.screen_locked():
                return self.reply(409, {"ok": False, "message": "PC 가 잠겨 있어 카톡 창을 열 수 없습니다. 잠금을 풀고 다시 누르세요."})
            snippet = str(b.get("snippet") or "").strip()
            try:
                if snippet:   # 그 메시지 위치까지 — 검색칸(Edit)에 포커스가 있을 때만 키를 친다(collector.jump_to_message)
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
        """이 PC 의 에이전트를 지금 ERP 에 로그인한 계정으로 다시 묶는다(.bat 없이) — 다른 계정으로 바꿀 때·토큰이 폐기됐을 때·개발 PC 의 사용자 전환.
        비밀값 없이 받는다(이미 떠 있는 에이전트를 같은 ERP 출처의 페이지가 가져가는 것 = .bat 을 다시 받아 실행하는 것과 같은 효과). Origin 은 config 의 ERP 출처만."""
        b = self.body()
        token, secret, erp = str(b.get("token") or ""), str(b.get("local_secret") or ""), str(b.get("erp_url") or AGENT.cfg.get("erp_url") or "")
        if not token.startswith("kck_") or len(secret) < 16 or not erp:
            return self.reply(422, {"ok": False, "message": "token·local_secret·erp_url 이 필요합니다."})
        AGENT.cfg.update({"erp_url": erp, "token": token, "local_secret": secret})
        save(CONFIG, AGENT.cfg)
        AGENT.erp_state, AGENT.erp_message = "unknown", ""
        AGENT.check_erp()
        log.info("rebound to %s (device=%s) erp_state=%s", erp, AGENT.device, AGENT.erp_state)
        self.reply(200, {"ok": AGENT.erp_state == "connected", "erp_state": AGENT.erp_state, "erp_message": AGENT.erp_message, "device": AGENT.device, "version": VERSION})


class Server(ThreadingHTTPServer):
    allow_reuse_address = False   # Windows 는 SO_REUSEADDR 이면 같은 포트에 두 번째가 그냥 붙는다 → 단일 인스턴스 판정이 안 된다
    daemon_threads = True


def main() -> int:
    try:
        srv = Server(("127.0.0.1", PORT), Handler)
    except OSError:
        log.info("port %s busy — another agent is running, exit", PORT)   # 시작프로그램 + 이미 떠 있음 → 조용히 종료
        return 0
    log.info("agent %s up on 127.0.0.1:%s (device=%s, erp=%s)", VERSION, PORT, AGENT.device, AGENT.cfg.get("erp_url", "-"))
    threading.Thread(target=AGENT.check_erp, daemon=True).start()
    try:
        srv.serve_forever()
    finally:
        srv.server_close()
        log.info("agent down")
    return 0


if __name__ == "__main__":
    sys.exit(main())
