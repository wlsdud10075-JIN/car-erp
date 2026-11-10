"""
카톡자동화 수집기 v1 (docs/design/kakao-automation.md §6 · §10-3)

내 PC 의 카카오톡 PC 창에서 체크한 방을 하나씩: 창 찾기 → 앞으로 가져오기 → 목록 빈 여백 클릭 → PageUp 으로 과거 풀기 →
Ctrl+A·C → 텍스트 파싱 → ERP `POST /api/kakao/ingest` 로 전송 → 클립보드 원복. 카톡에 아무것도 쓰지 않는다.

사용 (PowerShell):
  python collector.py windows                 # 카톡 창 목록(방 창이 열려 있는지 확인)
  python collector.py read [--dry] [--room 이름]   # 설정의 방을 전부 읽어 보냄 (--dry = 보내지 않고 건수만)
설정 = 같은 폴더 config.json (config.example.json 복사 — erp_url·token 만 필수). 상태 = state.json (방별 마지막 읽은 시각).
읽을 방 = **지금 열려 있는 카톡 방 창 전부** (config 의 rooms 는 그중 일부만 읽고 싶을 때만). 처음 보낸 방은 ERP 가 자동 등록한다.

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
DATE = re.compile(r"^-*\s*(\d{4})년 (\d{1,2})월 (\d{1,2})일 .요일\s*-*$")   # 창에서 복사하면 대시 없음(11-10 실측) — ERP KakaoExportParser 와 같은 규칙

VK_CONTROL, VK_A, VK_C, VK_PRIOR, VK_ESCAPE = 0x11, 0x41, 0x43, 0x21, 0x1B
KEYEVENTF_KEYUP = 0x0002


def device_name() -> str:
    """이 PC 이름 — 토큰에 기기 이름으로 남겨 어느 PC 인지 알 수 있게."""
    import socket

    try:
        return socket.gethostname()[:80]
    except Exception:
        return "PC"


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


def open_room_titles() -> list[str]:
    """지금 열려 있는 채팅방 창 제목들. 채팅방 창은 메인 창과 같은 클래스(EVA_Window_Dblclk)라 **제목으로만** 거른다
    (11-10 실측: 「■■ 수출팀」 방 창 = EVA_Window_Dblclk 382x640). 메인 「카카오톡」·제목 없는 보조 창만 뺀다."""
    return [w["title"] for w in kakao_windows() if w["title"].strip() and w["title"].strip() != "카카오톡"]


def find_room_window(name: str) -> dict | None:
    for win in kakao_windows():
        if win["title"] == name or win["title"].startswith(name):
            return win
    return None


VK_MENU = 0x12
k32 = ctypes.windll.kernel32


def focus(hwnd: int) -> bool:
    """방 창을 앞으로 — 그리고 **정말 앞에 왔는지 확인**한다.
    §11 실사고(jin 11-10): 창 없는 에이전트는 백그라운드 프로세스라 Windows 가 SetForegroundWindow 를 조용히 거부한다 → Ctrl+A·C 가
    맨 앞에 있던 브라우저(ERP 페이지)로 가서 웹페이지를 읽어 보냈다. exe 창 시절엔 사용자가 그 창의 [읽기]를 눌러 앞 권한이 있었다.
    세 번 시도: ①그냥 ②ALT 키 한 번(마지막 입력 프로세스가 앞 권한을 얻는 규칙) ③앞 창의 스레드에 입력을 붙여서(AttachThreadInput)."""
    u.ShowWindow(hwnd, 9)  # SW_RESTORE
    for attempt in range(3):
        if attempt == 1:
            u.keybd_event(VK_MENU, 0, 0, 0)
            u.keybd_event(VK_MENU, 0, KEYEVENTF_KEYUP, 0)
        if attempt == 2:
            fg_tid = u.GetWindowThreadProcessId(u.GetForegroundWindow(), None)
            my_tid = k32.GetCurrentThreadId()
            if fg_tid and fg_tid != my_tid:
                u.AttachThreadInput(my_tid, fg_tid, True)
        u.SetForegroundWindow(hwnd)
        u.BringWindowToTop(hwnd)
        if attempt == 2 and fg_tid and fg_tid != my_tid:
            u.AttachThreadInput(my_tid, fg_tid, False)
        time.sleep(0.4)
        if u.GetForegroundWindow() == hwnd:
            return True
    return False


def is_foreground(hwnd: int) -> bool:
    return u.GetForegroundWindow() == hwnd


def screen_locked() -> bool:
    """PC 가 잠겨 있나(잠금 화면·원격 세션 끊김) — 그 동안은 어떤 창도 앞으로 못 가져오고 키도 안 들어간다(11-10 실측: 맨 앞 창 = 「Windows 기본 잠금 화면」).
    판정 ①맨 앞 창의 프로세스가 LockApp.exe(Windows 10/11 잠금 화면은 Default 데스크톱 위에 뜬다 — 11-10 실측) ②입력 데스크톱이 Default 가 아님(비밀번호 입력 단계)."""
    try:
        h = u.GetForegroundWindow()
        pid = w.DWORD()
        u.GetWindowThreadProcessId(h, ctypes.byref(pid))
        ph = ctypes.windll.kernel32.OpenProcess(0x1000, False, pid.value)   # PROCESS_QUERY_LIMITED_INFORMATION
        if ph:
            buf = ctypes.create_unicode_buffer(260)
            n = w.DWORD(260)
            ok = ctypes.windll.kernel32.QueryFullProcessImageNameW(ph, 0, buf, ctypes.byref(n))
            ctypes.windll.kernel32.CloseHandle(ph)
            if ok and buf.value.lower().endswith("\\lockapp.exe"):
                return True
        desk = u.OpenInputDesktop(0, False, 0x0001)   # DESKTOP_READOBJECTS
        if not desk:
            return True
        name = ctypes.create_unicode_buffer(64)
        needed = w.DWORD()
        ok = u.GetUserObjectInformationW(desk, 2, name, ctypes.sizeof(name), ctypes.byref(needed))   # UOI_NAME
        u.CloseDesktop(desk)
        return not ok or name.value.lower() != "default"
    except Exception:
        return False


VK_V, VK_RETURN = 0x56, 0x0D
ROOM_SEARCH_ICON = (-109, 57)   # 방 창 돋보기 — 오른쪽 위에서 (가로 -109, 세로 +57) px (11-10 실측, 창 380 폭)
ROOM_SEARCH_CLOSE = (-23, 110)  # 열린 검색칸의 X (11-10 DPI 보정 캡처 실측: 물리 -29/+137 ÷ 1.25)


def child_windows(hwnd: int) -> list[tuple[str, str, tuple[int, int, int, int]]]:
    """방 창의 자식 창 (클래스, 제목, (x, y, w, h)) — 검색칸이 열려 있나 등을 보는 데 쓴다."""
    out: list[tuple[str, str, tuple[int, int, int, int]]] = []

    @ctypes.WINFUNCTYPE(ctypes.c_bool, w.HWND, w.LPARAM)
    def cb(c, _):
        cls = ctypes.create_unicode_buffer(128)
        u.GetClassNameW(c, cls, 128)
        t = ctypes.create_unicode_buffer(128)
        u.GetWindowTextW(c, t, 128)
        r = w.RECT()
        u.GetWindowRect(c, ctypes.byref(r))
        out.append((cls.value, t.value, (r.left, r.top, r.right - r.left, r.bottom - r.top)))
        return True

    u.EnumChildWindows(hwnd, cb, 0)
    return out
CLS_INPUT, CLS_SEARCH, CLS_LIST = "RICHEDIT50W", "Edit", "EVA_VH_ListControl_Dblclk"   # 방 창의 자식: 메시지 입력칸 / 대화 검색칸 / 대화 목록


def focused_child(hwnd: int) -> tuple[str, tuple[int, int, int, int]]:
    """그 창 안에서 지금 키 입력을 받는 자식 창의 (클래스, 사각형). 🔑 Enter·붙여넣기 전에 **반드시** 이걸로 어느 칸인지 확인한다 —
    11-10 실측: 메시지 입력칸 = RICHEDIT50W(아래), 대화 검색칸 = Edit(돋보기를 누르면 위에 176x22 로 생김), 목록 = EVA_VH_ListControl_Dblclk."""
    tid = u.GetWindowThreadProcessId(hwnd, None)
    my = k32.GetCurrentThreadId()
    u.AttachThreadInput(my, tid, True)
    f = u.GetFocus()
    u.AttachThreadInput(my, tid, False)
    cls = ctypes.create_unicode_buffer(128)
    u.GetClassNameW(f, cls, 128)
    r = w.RECT()
    u.GetWindowRect(f, ctypes.byref(r))
    return cls.value, (r.left, r.top, r.right - r.left, r.bottom - r.top)


def jump_to_message(name: str, snippet: str) -> dict:
    """방을 열고 그 방의 대화 검색으로 메시지 위치로 간다(jin 11-10 «돋보기 그림을 클릭해서 찾기»). 11-10 실측 OK(밥밥밥 「착한맛」 → 그 말풍선 선택).
    🚫 안전 규칙: Enter·붙여넣기는 **검색칸(Edit, 창 위쪽)에 포커스가 있을 때만**. 입력칸(RICHEDIT50W)이면 아무 키도 안 치고 중단 — 11-10 16:51 전송 사고 재발 방지."""
    win = open_room(name)
    if not win:
        return {"ok": False, "message": f"「{name}」 방을 카톡에서 찾지 못했습니다."}
    hwnd = win["hwnd"]
    if not focus(hwnd):
        return {"ok": False, "message": f"「{name}」 창을 앞으로 가져오지 못했습니다."}
    left, top, right, _ = win["rect"]

    def search_box_focused() -> bool:
        cls, rect = focused_child(hwnd)
        return cls == CLS_SEARCH and rect[3] > 0 and rect[1] < top + 120

    def search_box_open() -> bool:   # 검색칸(Edit)이 보이나 — 포커스와 무관
        for c in child_windows(hwnd):
            if c[0] == CLS_SEARCH and c[2][3] > 0 and c[2][1] < top + 120:
                return True
        return False

    if search_box_open():   # 지난번 검색어가 남아 있다(jin 11-10 «기존 내용 지우고 해야») → X 로 닫고 새로 연다
        click(right + ROOM_SEARCH_CLOSE[0], top + ROOM_SEARCH_CLOSE[1])
        time.sleep(0.5)
    click(right + ROOM_SEARCH_ICON[0], top + ROOM_SEARCH_ICON[1])   # 돋보기 → 빈 검색칸이 생기고 포커스가 거기로
    time.sleep(0.8)
    if not search_box_focused():
        return {"ok": True, "jumped": False, "message": "방은 열었지만 검색칸이 안 열려 위치 이동은 건너뜁니다(아무 키도 치지 않음)."}
    snippet = snippet.strip()[:40]
    if not snippet:
        return {"ok": True, "jumped": False, "message": "방을 열었습니다."}
    saved = pyperclip.paste()
    try:
        pyperclip.copy(snippet)
        time.sleep(0.2)
        key(VK_A, ctrl=True)   # 검색칸 안에서만(포커스 확인 뒤) — 남은 글이 있어도 덮어쓴다
        time.sleep(0.1)
        key(VK_V, ctrl=True)
        time.sleep(0.5)
        if not search_box_focused():   # 붙여넣기 뒤에도 검색칸이어야 Enter
            return {"ok": True, "jumped": False, "message": "검색칸을 잃어 위치 이동은 건너뜁니다."}
        key(VK_RETURN)
        time.sleep(0.8)
        return {"ok": True, "jumped": True, "message": f"「{name}」 에서 「{snippet}」 위치로 이동했습니다."}
    finally:
        try:
            pyperclip.copy(saved)
        except Exception:
            pass
MAIN_SEARCH_ICON = (-109, 55)   # 메인 창 「채팅」 탭 돋보기 — 오른쪽 위에서 (가로 -109, 세로 +55) px (11-10 캡처 실측, 창 392x816)
MAIN_SEARCH_CLEAR = (-29, 101)  # 검색칸의 X (필터 지우기)


def open_room(name: str, timeout: float = 4.0) -> dict | None:
    """닫혀 있는 방을 메인 창 검색으로 연다(jin 11-10 «열어 놔야 되는구나.. 찾아주는 것까지 하면») — 돋보기 → 이름 붙여넣기 → Enter → 방 창이 뜬다.
    ⚠️ 여기 Enter 는 「방 열기」일 뿐 전송이 아니다. 🚫 방 창 **안**에서는 Enter 를 절대 치지 말 것 — Ctrl+F 가 검색을 안 열고 붙여넣기가
    입력칸에 들어간 뒤 Enter 가 **실제 전송**이 됐다(11-10 16:51 수출팀 방 실사고). 그래서 메시지 위치 점프는 안 만든다."""
    win = find_room_window(name)
    if win:
        return win
    main = next((w for w in kakao_windows() if w["title"].strip() == "카카오톡"), None)
    if not main or not focus(main["hwnd"]):
        return None
    left, top, right, _ = main["rect"]
    saved = pyperclip.paste()
    try:
        click(right + MAIN_SEARCH_ICON[0], top + MAIN_SEARCH_ICON[1])
        time.sleep(0.5)
        pyperclip.copy(name)
        time.sleep(0.2)
        key(VK_V, ctrl=True)
        time.sleep(0.8)
        key(VK_RETURN)
        deadline = time.time() + timeout
        while time.time() < deadline:
            time.sleep(0.4)
            win = find_room_window(name)
            if win:
                break
        # 검색 필터 지우기(안 지우면 메인 창 목록이 그 이름으로 걸러진 채 남는다)
        if focus(main["hwnd"]):
            click(right + MAIN_SEARCH_CLEAR[0], top + MAIN_SEARCH_CLEAR[1])
            time.sleep(0.3)
        if win:
            focus(win["hwnd"])
        return win
    finally:
        try:
            pyperclip.copy(saved)
        except Exception:
            pass


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
    hwnd = win["hwnd"]
    try:
        if not focus(hwnd):
            return {"room": name, "ok": False, "message": f"「{name}」 창을 앞으로 가져오지 못했습니다. 그 방 창을 한 번 클릭한 뒤 다시 [지금 읽기]."}
        left, top, right, bottom = win["rect"]
        fx, fy = cfg.get("click_fraction", [0.5, 0.35])
        click(int(left + (right - left) * fx), int(top + (bottom - top) * fy))   # 목록 빈 여백(사진 위를 누르면 뷰어가 뜬다 → config 로 조정)
        time.sleep(0.3)
        # 🚫 여기서 무조건 ESC 를 치면 안 된다 — 카톡 PC 는 기본 설정이 「ESC 로 채팅창 닫기」라 방 창이 닫히고 맨 앞이 브라우저가 되어
        #    Ctrl+A·C 가 ERP 페이지를 읽어 보냈다(jin 11-10 실사고 «카톡방을 안 읽고 웹페이지를 읽는데?»). ESC 는 사진 뷰어가 떴을 때만.
        if not is_foreground(hwnd):
            if u.GetForegroundWindow() in {k["hwnd"] for k in kakao_windows()}:   # 카톡의 다른 창(사진 뷰어) → 닫고 방으로 복귀
                key(VK_ESCAPE)
                time.sleep(0.3)
                focus(hwnd)
            if not is_foreground(hwnd):   # 🚫 다른 창에 Ctrl+A·C 를 보내지 않는다(브라우저·엑셀 내용을 읽어 보내는 사고 방지)
                return {"room": name, "ok": False, "message": f"「{name}」 창이 앞에 있지 않아 중단했습니다. 읽는 동안 다른 창을 누르지 마세요."}
        if focused_child(hwnd)[0] != CLS_LIST:   # 검색칸·입력칸에 포커스면 Ctrl+A·C 가 그 칸만 복사한다 → 목록을 한 번 더 누르고, 그래도 아니면 중단
            click(int(left + (right - left) * fx), int(top + (bottom - top) * fy))
            time.sleep(0.3)
            if focused_child(hwnd)[0] != CLS_LIST:
                return {"room": name, "ok": False, "message": f"「{name}」 대화 목록에 포커스를 못 잡았습니다(검색칸이 열려 있으면 닫아 주세요)."}

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
            if not is_foreground(hwnd):
                return {"room": name, "ok": False, "message": f"「{name}」 읽는 도중 다른 창으로 넘어가 중단했습니다. 다시 [지금 읽기]."}
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
    ap.add_argument("cmd", choices=["setup", "windows", "read"])
    ap.add_argument("--dry", action="store_true", help="보내지 않고 복사·건수만")
    ap.add_argument("--room", help="이 방만")
    args = ap.parse_args()

    if args.cmd == "setup":
        cur = load_json(CONFIG, {}) or {}
        print("ERP 주소와 수집기 토큰을 넣습니다. 토큰은 ERP 「내 카톡 할 일」 → 「방·수집기 설정」 → 「수집기 연결」 → [토큰 발급] (한 번만 보임).")
        url = input(f"ERP 주소 [{cur.get('erp_url', 'https://heymancar.com')}]: ").strip() or cur.get("erp_url", "https://heymancar.com")
        tok = input("토큰 (kck_…): ").strip() or cur.get("token", "")
        if not tok.startswith("kck_"):
            print("토큰이 kck_ 로 시작해야 합니다. 다시 발급해 넣으세요.")
            return 2
        cur.update({"erp_url": url, "token": tok})
        cur.setdefault("first_read_days", 7); cur.setdefault("pageup_batch", 20); cur.setdefault("max_pageups", 400); cur.setdefault("click_fraction", [0.5, 0.35])
        CONFIG.write_text(json.dumps(cur, ensure_ascii=False, indent=2), encoding="utf-8")
        r = requests.get(url.rstrip("/") + "/api/kakao/rooms", headers={"Authorization": "Bearer " + tok, "Accept": "application/json"}, timeout=30)
        print(f"저장했습니다. ERP 연결 확인: HTTP {r.status_code} — {r.json().get('message', '') if r.headers.get('content-type','').startswith('application/json') else r.text[:120]}")
        return 0

    if args.cmd == "windows":
        for win in kakao_windows():
            print(f"{win['cls']:22} {win['rect'][2]-win['rect'][0]}x{win['rect'][3]-win['rect'][1]}  {win['title']}")
        return 0

    cfg = load_json(CONFIG, None)
    if not cfg:
        print("config.json 이 없습니다 — config.example.json 을 복사해 erp_url·token·rooms 를 채우세요.")
        return 2
    state = load_json(STATE, {})
    hdr = {"Authorization": "Bearer " + cfg["token"], "Accept": "application/json"}
    base = cfg["erp_url"].rstrip("/")
    # ① 지금 열려 있는 방 창을 ERP 에 보고 → ERP 가 방을 등록하고, 사용자가 화면에서 「읽기」로 체크한 방 목록을 돌려준다
    open_rooms = open_room_titles()
    if not open_rooms:
        print("열려 있는 카톡 방 창이 없습니다. 읽을 방을 카톡에서 창으로 열어 두고 다시 하세요. (python collector.py windows 로 확인)")
        return 2
    r = requests.post(base + "/api/kakao/rooms", headers=hdr, json={"rooms": open_rooms}, timeout=60)
    if not r.ok:
        print(f"ERP 응답 {r.status_code}: {r.json().get('message', r.text[:200]) if r.headers.get('content-type','').startswith('application/json') else r.text[:200]}")
        return 2
    selected = r.json().get("selected", [])
    rooms = [args.room] if args.room else [n for n in open_rooms if n in selected]
    print(f"열린 방 {len(open_rooms)}개 보고 → 체크된 방 {len(selected)}개")
    if not rooms:
        print("읽을 방이 없습니다. ERP 「내 카톡 할 일」 맨 위 「읽을 방」에서 읽을 방을 체크한 뒤 다시 read 하세요.")
        return 0
    print("읽을 방: " + " · ".join(rooms))

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
