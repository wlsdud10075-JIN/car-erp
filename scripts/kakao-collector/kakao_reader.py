"""
카톡 읽기 — 실무자 PC 용 창 프로그램 (docs/design/kakao-automation.md §1 #17 · jin 11-10 «설치 파일 하나로»)

첫 실행: 회사 고르기 + ERP 아이디·비밀번호 → 수집기 토큰을 받아 저장(비밀번호는 저장하지 않는다).
평소: 열려 있는 카톡 방이 체크박스로 뜬다 → 읽을 방 체크 → [읽기]. 체크 상태는 ERP 와 같이 맞춘다.
카톡에는 아무것도 쓰지 않는다. 읽는 동안(방당 수 초~수십 초) 키보드·마우스에서 손을 뗀다.

exe 만들기(개발 PC): pyinstaller --onefile --windowed --name KakaoReader kakao_reader.py   (collector.py 와 같은 폴더에서)
"""
from __future__ import annotations

import json
import threading
import tkinter as tk
from datetime import date, datetime, timedelta
from pathlib import Path
from tkinter import messagebox, ttk

import requests

import collector as C

APP_DIR = Path.home() / "KakaoReader"
APP_DIR.mkdir(exist_ok=True)
CONFIG = APP_DIR / "config.json"
STATE = APP_DIR / "state.json"
C.CONFIG, C.STATE = CONFIG, STATE   # collector 의 상태 파일도 사용자 폴더로

COMPANIES = {
    "싼카 (heymancar.com)": "https://heymancar.com",
    "헤이맨 (heysellcar.com)": "https://heysellcar.com",
    "카라바 (karaba-erp.com)": "https://karaba-erp.com",
    "개발 PC (127.0.0.1:8001)": "http://127.0.0.1:8001",
}


def load(path: Path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else default
    except Exception:
        return default


def save(path: Path, data) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


class App(tk.Tk):
    def __init__(self) -> None:
        super().__init__()
        self.title("카톡 읽기 — 내 카톡 할 일")
        self.geometry("520x560")
        self.minsize(460, 420)
        self.cfg = load(CONFIG, {})
        self.vars: dict[str, tk.BooleanVar] = {}
        self.busy = False
        if self.cfg.get("token"):
            self.build_main()
        else:
            self.build_login()

    # ───────── 로그인 ─────────
    def build_login(self) -> None:
        for w in self.winfo_children():
            w.destroy()
        f = ttk.Frame(self, padding=24)
        f.pack(fill="both", expand=True)
        ttk.Label(f, text="처음 한 번만: ERP 로그인", font=("Malgun Gothic", 13, "bold")).pack(anchor="w", pady=(0, 12))
        ttk.Label(f, text="회사").pack(anchor="w")
        self.company = ttk.Combobox(f, values=list(COMPANIES), state="readonly")
        self.company.current(0)
        self.company.pack(fill="x", pady=(0, 8))
        ttk.Label(f, text="ERP 아이디(이메일)").pack(anchor="w")
        self.email = ttk.Entry(f)
        self.email.pack(fill="x", pady=(0, 8))
        ttk.Label(f, text="비밀번호").pack(anchor="w")
        self.password = ttk.Entry(f, show="•")
        self.password.pack(fill="x", pady=(0, 12))
        self.login_btn = ttk.Button(f, text="연결", command=self.do_login)
        self.login_btn.pack(anchor="e")
        self.login_msg = ttk.Label(f, text="비밀번호는 저장하지 않습니다. 이 PC 전용 연결 열쇠만 받아 둡니다.", foreground="#666", wraplength=440)
        self.login_msg.pack(anchor="w", pady=12)
        self.email.focus()
        self.bind("<Return>", lambda _e: self.do_login())

    def do_login(self) -> None:
        url = COMPANIES[self.company.get()]
        email, pw = self.email.get().strip(), self.password.get()
        if not email or not pw:
            self.login_msg.config(text="아이디와 비밀번호를 넣어 주세요.", foreground="#b00")
            return
        self.login_btn.config(state="disabled")
        try:
            r = requests.post(url + "/api/kakao/login", json={"email": email, "password": pw, "device": C.device_name()}, timeout=30, headers={"Accept": "application/json"})
            body = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
            if r.ok and body.get("token"):
                self.cfg = {"erp_url": url, "token": body["token"], "name": body.get("name", ""), "first_read_days": 7, "pageup_batch": 20, "max_pageups": 400, "click_fraction": [0.5, 0.35]}
                save(CONFIG, self.cfg)
                self.build_main()
                return
            self.login_msg.config(text=body.get("message") or f"연결 실패 (HTTP {r.status_code})", foreground="#b00")
        except requests.RequestException as e:
            self.login_msg.config(text=f"ERP 에 연결할 수 없습니다: {e}", foreground="#b00")
        finally:
            self.login_btn.config(state="normal")

    # ───────── 메인 ─────────
    def build_main(self) -> None:
        for w in self.winfo_children():
            w.destroy()
        self.unbind("<Return>")
        top = ttk.Frame(self, padding=(16, 12, 16, 4))
        top.pack(fill="x")
        ttk.Label(top, text=f"{self.cfg.get('name', '')} · {self.cfg.get('erp_url', '')}", foreground="#666").pack(side="left")
        ttk.Button(top, text="다른 계정", command=self.logout).pack(side="right")

        box = ttk.LabelFrame(self, text="열려 있는 카톡 방 — 읽을 방을 체크하세요", padding=8)
        box.pack(fill="both", expand=True, padx=16, pady=8)
        self.rooms_frame = ttk.Frame(box)
        self.rooms_frame.pack(fill="both", expand=True)
        self.hint = ttk.Label(box, text="", foreground="#666", wraplength=460)
        self.hint.pack(anchor="w", pady=(6, 0))

        btns = ttk.Frame(self, padding=(16, 0, 16, 8))
        btns.pack(fill="x")
        ttk.Button(btns, text="목록 새로고침", command=self.refresh_rooms).pack(side="left")
        self.read_btn = ttk.Button(btns, text="읽기", command=self.do_read)
        self.read_btn.pack(side="right")

        self.log = tk.Text(self, height=9, state="disabled", font=("Malgun Gothic", 9), wrap="word")
        self.log.pack(fill="both", expand=False, padx=16, pady=(0, 12))
        self.refresh_rooms()

    def logout(self) -> None:
        if messagebox.askyesno("다른 계정", "이 PC 의 연결을 끊고 다시 로그인할까요?"):
            self.cfg = {}
            if CONFIG.exists():
                CONFIG.unlink()
            self.build_login()

    def say(self, text: str) -> None:
        self.log.config(state="normal")
        self.log.insert("end", datetime.now().strftime("%H:%M ") + text + "\n")
        self.log.see("end")
        self.log.config(state="disabled")

    def api(self, method: str, path: str, **kw):
        kw.setdefault("timeout", 180)
        kw.setdefault("headers", {})
        kw["headers"].update({"Authorization": "Bearer " + self.cfg["token"], "Accept": "application/json"})
        return requests.request(method, self.cfg["erp_url"].rstrip("/") + path, **kw)

    def refresh_rooms(self) -> None:
        open_rooms = C.open_room_titles()
        for w in self.rooms_frame.winfo_children():
            w.destroy()
        if not open_rooms:
            ttk.Label(self.rooms_frame, text="열려 있는 카톡 방 창이 없습니다. 카톡에서 읽을 방을 열어 두고 「목록 새로고침」.", foreground="#b00", wraplength=440).pack(anchor="w")
            return
        selected: list[str] = []
        try:
            r = self.api("POST", "/api/kakao/rooms", json={"rooms": open_rooms})
            body = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
            if r.status_code in (401, 403):
                self.say(body.get("message", "연결이 끊겼습니다. 다시 로그인하세요."))
                self.cfg = {}
                if CONFIG.exists():
                    CONFIG.unlink()
                self.build_login()
                return
            selected = body.get("selected", [])
        except requests.RequestException as e:
            self.say(f"ERP 연결 실패: {e}")
        self.vars = {}
        for name in open_rooms:
            v = tk.BooleanVar(value=name in selected)
            ttk.Checkbutton(self.rooms_frame, text=name, variable=v).pack(anchor="w", pady=1)
            self.vars[name] = v
        self.hint.config(text=f"열린 방 {len(open_rooms)}개 · 체크 {len(selected)}개. 읽기를 누르면 체크한 방만 읽습니다.")

    def do_read(self) -> None:
        if self.busy:
            return
        chosen = [n for n, v in self.vars.items() if v.get()]
        if not chosen:
            self.say("체크한 방이 없습니다.")
            return
        self.busy = True
        self.read_btn.config(state="disabled")
        self.say(f"읽기 시작 — {len(chosen)}개 방. 끝날 때까지 키보드·마우스에서 손을 떼 주세요.")
        threading.Thread(target=self.read_worker, args=(chosen,), daemon=True).start()

    def read_worker(self, chosen: list[str]) -> None:
        try:
            try:
                self.api("POST", "/api/kakao/rooms/select", json={"selected": chosen})   # 체크 상태를 ERP 와 맞춘다
            except requests.RequestException as e:
                self.after(0, self.say, f"체크 저장 실패(계속 진행): {e}")
            state = load(STATE, {})
            for name in chosen:
                last = state.get(name)
                since = (datetime.fromisoformat(last).date() - timedelta(days=1)) if last else (date.today() - timedelta(days=int(self.cfg.get("first_read_days", 7))))
                info = C.read_room(self.cfg, name, since, dry=False)
                self.after(0, self.say, f"[{name}] {info.get('message') or info}")
                if info.get("ok"):
                    state[name] = datetime.now().isoformat(timespec="minutes")
                    save(STATE, state)
                    if info.get("status") and (info.get("server") or {}).get("needs_review") is None and "「나」" in (info.get("message") or ""):
                        self.after(0, self.say, "→ ERP 「내 카톡 할 일」에서 이 방의 「나」를 한 번 클릭해 주세요.")
            self.after(0, self.say, "끝. ERP 「내 카톡 할 일」을 새로고침하세요.")
        finally:
            self.busy = False
            self.after(0, lambda: self.read_btn.config(state="normal"))
            self.after(0, self.activate)

    def activate(self) -> None:
        try:
            self.lift()
            self.focus_force()
        except Exception:
            pass


if __name__ == "__main__":
    App().mainloop()
