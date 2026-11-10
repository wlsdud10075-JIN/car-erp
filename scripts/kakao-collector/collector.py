"""
카톡자동화 수집기 v1 (docs/design/kakao-automation.md §6 · §10-3)

내 PC 의 카카오톡 PC 창에서 체크한 방을 하나씩: 창 찾기 → 앞으로 가져오기 → 목록 빈 여백 클릭 → PageUp 으로 과거 풀기 →
Ctrl+A·C → 텍스트 파싱 → ERP `POST /api/kakao/ingest` 로 전송 → 클립보드 원복. 카톡에 아무것도 쓰지 않는다.

사용 (PowerShell):
  python collector.py windows                 # 카톡 창 목록(방 창이 열려 있는지 확인)
  python collector.py read [--dry] [--room 이름]   # 설정의 방을 전부 읽어 보냄 (--dry = 보내지 않고 건수만)
설정 = 같은 폴더 config.json (config.example.json 복사). 상태 = state.json (방별 마지막 읽은 시각).

⚠️ 읽는 동안(방당 수 초~수십 초) 키보드·마우스에서 손을 뗀다. 방 창은 미리 열어 둔다(v1 은 목록에서 방을 자동으로 열지 않는다).
의존: Python 3.10+ · pyperclip · requests (pip install pyperclip requests). 나머지는 표준 ctypes.
"""
from __future__ import annotations

import argparse
import ctypes
import ctypes.wintypes as w
import json
import re
import subprocess
import sys
import time
from datetime import date, datetime, timedelta
from pathlib import Path

import pyperclip
import requests

HERE = Path(__file__).resolve().parent
CONFIG = HERE / "config.json"
STATE = HERE / "state.json"

u = ctypes.windll.user32

HEAD = re.compile(r"^\[(.+?)\] \[(오전|오후) (\d{1,2}):(\d{2})\] (.*)$")
DATE = re.compile(r"^-+ (\d{4})년 (\d{1,2})월 (\d{1,2})일 .요일 -+$")

VK_CONTROL, VK_A, VK_C, VK_PRIOR, VK_ESCAPE = 0x11, 0x41, 0x43, 0x21, 0x1B
KEYEVENTF_KEYUP = 0x0002


# ─────────── 창 ───────────
def kakao_pids() -> list[int]:
    out = subprocess.run(["tasklist", "/FI", "IMAGENAME eq KakaoTalk.exe", "/FO", "CSV"], capture_output=True, text=True).stdout
    return [int(line.split('","')[1]) for line in out.splitlines() if line.startswith('"KakaoTalk.exe"')]


def kakao_windows() -> list[dict]:
    pids = set(kakao_pids())
    found: list[dict] = []

    @ctypes.WINFUNCTYPE(ctypes.c_bool, w.HWND, w.LPARAM)
    def cb(h, _):
        pid = w.DWORD()
        u.GetWindowThreadProcessId(h, ctypes.byref(pid))
        if pid.value in pids and u.IsWindowVisible(h):
            cls = ctypes.create_unicode_buffer(256)
            u.GetClassNameW(h, cls, 256)
            title = ctypes.create_unicode_buffer(512)
            u.GetWindowTextW(h, title, 512)
            r = w.RECT()
            u.GetWindowRect(h, ctypes.byref(r))
            if title.value:
                found.append({"hwnd": h, "cls": cls.value, "title": title.value, "rect": (r.left, r.top, r.right, r.bottom)})
        return True

    u.EnumWindows(cb, 0)
    return found


def find_room_window(name: str) -> dict | None:
    for win in kakao_windows():
        if win["title"] == name or win["title"].startswith(name):
            return win
    return None


def focus(hwnd: int) -> None:
    u.ShowWindow(hwnd, 9)  # SW_RESTORE
    u.SetForegroundWindow(hwnd)
    time.sleep(0.4)


def click(x: int, y: int) -> None:
    u.SetCursorPos(x, y)
    u.mouse_event(0x0002, 0, 0, 0, 0)  # LEFTDOWN
    u.mouse_event(0x0004, 0, 0, 0, 0)  # LEFTUP
    time.sleep(0.15)


def key(vk: int, ctrl: bool = False) -> None:
    if ctrl:
        u.keybd_event(VK_CONTROL, 0, 0, 0)
    u.keybd_event(vk, 0, 0, 0)
    u.keybd_event(vk, 0, KEYEVENTF_KEYUP, 0)
    if ctrl:
        u.keybd_event(VK_CONTROL, 0, KEYEVENTF_KEYUP, 0)
    time.sleep(0.05)


def copy_all() -> str:
    pyperclip.copy("")
    key(VK_A, ctrl=True)
    time.sleep(0.2)
    key(VK_C, ctrl=True)
    time.sleep(0.4)
    return pyperclip.paste() or ""


# ─────────── 파싱(건수·범위만 — 분류는 ERP) ───────────
def summarize(text: str) -> tuple[int, date | None]:
    """메시지 수와 가장 이른 날짜(구분선 기준)."""
    count, first = 0, None
    cur = None
    for line in text.splitlines():
        m = DATE.match(line.rstrip())
        if m:
            cur = date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
            if first is None or cur < first:
                first = cur
            continue
        if HEAD.match(line.rstrip()):
            count += 1
    return count, first


# ─────────── 한 방 읽기 ───────────
def read_room(cfg: dict, name: str, since: date, dry: bool) -> dict:
    win = find_room_window(name)
    if not win:
        return {"room": name, "ok": False, "message": f"「{name}」 창을 못 찾았습니다. 카톡에서 그 방을 열어 두세요."}

    saved_clip = pyperclip.paste()
    try:
        focus(win["hwnd"])
        left, top, right, bottom = win["rect"]
        fx, fy = cfg.get("click_fraction", [0.5, 0.35])
        click(int(left + (right - left) * fx), int(top + (bottom - top) * fy))   # 목록 빈 여백(사진 위를 누르면 뷰어가 뜬다 → config 로 조정)
        key(VK_ESCAPE)

        batch = int(cfg.get("pageup_batch", 20))
        max_pageups = int(cfg.get("max_pageups", 400))
        done = 0
        text = copy_all()
        count, first = summarize(text)
        while done < max_pageups and (first is None or first > since):
            for _ in range(batch):
                key(VK_PRIOR)
            done += batch
            time.sleep(0.3)
            text = copy_all()
            new_count, first = summarize(text)
            if new_count == count:   # 더 안 풀린다 = 맨 위
                break
            count = new_count

        info = {"room": name, "ok": True, "messages": count, "earliest": str(first) if first else None, "pageups": done, "chars": len(text)}
        if dry or count == 0:
            info["message"] = f"{count}건 복사(가장 이른 {first}) — 전송 안 함" if dry else "복사된 메시지가 없습니다"
            return info

        res = requests.post(
            cfg["erp_url"].rstrip("/") + "/api/kakao/ingest",
            headers={"Authorization": "Bearer " + cfg["token"], "Accept": "application/json"},
            json={"room": name, "text": text, "fallback_date": None},
            timeout=180,
        )
        try:
            body = res.json()
        except ValueError:
            body = {"message": res.text[:200]}
        info["status"] = res.status_code
        info["message"] = body.get("message", "")
        info["ok"] = res.ok
        info["server"] = {k: body.get(k) for k in ("read_id", "total", "new_count", "needs_review", "cost_krw") if k in body}
        return info
    finally:
        try:
            pyperclip.copy(saved_clip)   # 사용자 클립보드 원복
        except Exception:
            pass


def load_json(path: Path, default):
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8")   # PowerShell 기본 콘솔(cp949)에서 한글 출력이 깨지지 않게
    except Exception:
        pass
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["windows", "read"])
    ap.add_argument("--dry", action="store_true", help="보내지 않고 복사·건수만")
    ap.add_argument("--room", help="이 방만")
    args = ap.parse_args()

    if args.cmd == "windows":
        for win in kakao_windows():
            print(f"{win['cls']:22} {win['rect'][2]-win['rect'][0]}x{win['rect'][3]-win['rect'][1]}  {win['title']}")
        return 0

    cfg = load_json(CONFIG, None)
    if not cfg:
        print("config.json 이 없습니다 — config.example.json 을 복사해 erp_url·token·rooms 를 채우세요.")
        return 2
    state = load_json(STATE, {})
    rooms = [args.room] if args.room else cfg.get("rooms", [])
    if not rooms:
        print("읽을 방이 없습니다(config.json rooms).")
        return 2

    first_days = int(cfg.get("first_read_days", 7))
    for name in rooms:
        last = state.get(name)
        since = (datetime.fromisoformat(last).date() - timedelta(days=1)) if last else (date.today() - timedelta(days=first_days))
        print(f"== {name} (읽을 범위: {since} 이후)")
        info = read_room(cfg, name, since, args.dry)
        print("   " + json.dumps(info, ensure_ascii=False))
        if info.get("ok") and not args.dry:
            state[name] = datetime.now().isoformat(timespec="minutes")
            STATE.write_text(json.dumps(state, ensure_ascii=False, indent=1), encoding="utf-8")
        time.sleep(0.5)
    return 0


if __name__ == "__main__":
    sys.exit(main())
