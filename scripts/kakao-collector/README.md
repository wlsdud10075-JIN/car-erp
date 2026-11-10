# 카톡자동화 수집기 (내 PC 용)

## 실무자에게 주는 것 = `KakaoReader.exe` 하나 (`dist/`, 개발 PC 에서 빌드)
1. 더블클릭 → 「처음 한 번만: ERP 로그인」 창 — 회사 고르고 ERP 아이디·비밀번호 → [연결]. 비밀번호는 저장하지 않고 이 PC 전용 연결 열쇠만 사용자 폴더 `KakaoReader\config.json` 에 남는다.
2. 카톡에서 읽을 방을 창으로 열어 둔다 → 프로그램의 [목록 새로고침] → 열린 방이 체크박스로 뜬다 → 읽을 방 체크 → [읽기]. 읽는 동안 손을 뗀다.
3. 처음 읽은 방은 ERP 「내 카톡 할 일」에 「「나」를 골라 주세요」 카드로 뜬다 → 내 이름 클릭(같은 회사 사람은 체크). 그 뒤는 [읽기]만.
빌드(개발 PC): `pip install pyinstaller` → 이 폴더에서 `pyinstaller --noconfirm --onefile --windowed --name KakaoReader kakao_reader.py` → `dist/KakaoReader.exe`(약 19MB). 소스 = `kakao_reader.py`(창) + `collector.py`(읽기 엔진).

---
아래는 명령줄 버전(개발·진단용).

ERP 「내 카톡 할 일」에 카톡 방 내용을 보내는 작은 프로그램. 기획 = `docs/design/kakao-automation.md` §6·§10-3.
카톡 PC 창에서 **전체선택·복사(Ctrl+A·C)** 한 텍스트를 ERP 로 보낼 뿐이고, 카톡에 글을 쓰거나 보내지 않는다.

## 설치 (1회)
1. Python 3.10 이상, `pip install pyperclip requests`
2. 이 폴더를 내 PC 아무 곳에 복사(예: `C:\kakao-collector`).
3. `python collector.py setup` — ERP 주소와 토큰을 물어 `config.json` 에 저장하고 연결을 확인한다.
   - 토큰 = ERP 「내 카톡 할 일」 → 「방·수집기 설정」 → 「수집기 연결」 → [토큰 발급] (발급 때 한 번만 보인다)

## 사용
1. 카톡에서 읽을 방을 **창으로 열어 둔다**(여러 개 가능). `python collector.py windows` 로 창이 보이는지 확인.
2. `python collector.py read` — 열린 방 목록을 ERP 에 보고한다. 처음엔 「ERP 에서 읽을 방을 체크하세요」가 찍힌다.
3. ERP 「내 카톡 할 일」 맨 위 「읽을 방」에서 읽을 방을 **체크**한다.
4. 다시 `python collector.py read` — 체크한 방만 읽어 보낸다. 처음 읽은 방은 「「나」를 골라 주세요」 카드로 뜨니 **내 이름을 클릭**(같은 회사 사람은 체크) → 쌓인 메시지가 바로 분류된다.
5. 그 뒤로는 `read` 만 반복한다.
읽는 동안 키보드·마우스에서 손을 뗀다. 끝나면 클립보드는 원래대로 돌려 놓는다. 먼저 `--dry` 로 복사만 해 봐도 된다(전송 안 함).

처음은 최근 7일(`first_read_days`), 그 뒤는 마지막 읽은 시각 하루 전부터(`state.json`). PageUp 은 그 날짜가 보일 때까지만(최대 `max_pageups`).

## 함정 (2026-11-09 실측)
- 목록 **가운데**를 누르면 사진 뷰어가 열려 포커스를 뺏긴다 → 빈 여백을 눌러야 한다. 위치는 `click_fraction` [가로비, 세로비] 로 조정.
- 메인 창 Ctrl+F 검색은 안 열린다 → 방은 목록 더블클릭으로 연다(v1 은 사람이).
- 복사 뒤 클립보드에 대화가 남는다 → 수집기가 원복한다.
- ERP 가 422 「상한」을 돌려주면 범위가 너무 넓은 것 — `first_read_days` 를 줄이거나 ERP 기능설정 상한을 올린다.

## 다음 단계(미구현)
트레이 상주 + [지금 읽기] 버튼 · 방 자동 열기 · exe 패키징(PyInstaller) · 미룸 시각 알림.
