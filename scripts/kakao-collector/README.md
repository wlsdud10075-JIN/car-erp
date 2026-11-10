# 카톡자동화 — 실무자 PC 쪽 (에이전트)

기획 = `docs/design/kakao-automation.md` §11 「사용 화면은 ERP 하나」(jin 2026-11-10). 실무자가 보는 화면은 ERP 「내 카톡 할 일」 하나다.

## 실무자가 하는 것
1. ERP 「내 카톡 할 일」 → 「방·수집기 설정」 → **[이 PC 연결하기]** → `KakaoAgent-setup.bat` 이 내려온다(PC 마다 한 번).
2. 그 파일 더블클릭 → Windows 가 묻는 창에서 [실행] → 검은 창이 잠깐 떴다 닫힌다. 끝. (파일은 스스로 지워진다.)
   이후 PC 를 켤 때마다 에이전트가 창 없이 자동 시작된다(시작프로그램 바로가기 `KakaoAgent.lnk`).
3. 처음 한 번: 카톡에서 읽을 방을 창으로 열어 둔다 → ERP 페이지를 열면 「읽을 방」에 그 방이 **열림** 배지로 뜬다 → 체크. 그 뒤로는 체크만 유지되면 **[지금 읽기]**가 닫힌 방도 카톡에서 찾아 열고 읽는다(`open_room`). 읽는 동안(방당 수 초~수십 초) 키보드·마우스에서 손을 뗀다. 「나」·「우리 회사」는 ERP 명부로 자동(이름이 다른 사람만 한 칸 고름).

토큰·비밀번호를 어디에 적을 일은 없다. 카톡에는 아무것도 쓰지 않는다.

## 파일
| 파일 | 역할 |
|---|---|
| `kakao_agent.py` | 창 없는 로컬 서버 `http://127.0.0.1:47321` — `/ping` `/rooms` `/read` `/progress` `/quit`. ERP 페이지 JS 만 부를 수 있다(Origin = 그 회사 ERP + `X-Kakao-Secret`) |
| `collector.py` | 읽기 엔진(카톡 창 찾기 → PageUp → Ctrl+A·C → `POST /api/kakao/ingest`) + 명령줄 진단(`windows` / `read --dry`) |
| ERP `app/Http/Controllers/KakaoInstallerController.php` | 설치 `.bat` 생성(토큰·`local_secret`·회사 주소 포함, ASCII·CRLF) |

PC 쪽 파일 = `%LOCALAPPDATA%\KakaoReader\` : `KakaoAgent.exe` · `config.json`(erp_url·token·local_secret) · `state.json`(방별 마지막 읽은 시각) · `agent.log`.

## exe 빌드 → 배포 (개발 PC)
```powershell
cd scripts\kakao-collector
pip install pyinstaller pyperclip requests
pyinstaller --noconfirm --onefile --noconsole --name KakaoAgent kakao_agent.py    # dist\KakaoAgent.exe (약 19MB)
```
- ERP 는 `public/downloads/KakaoAgent.exe` 를 정적으로 내려 준다(git 밖 — `.gitignore`). **운영 3사 서버에 올리는 것은 배포 절차의 한 줄**: `scp dist/KakaoAgent.exe ubuntu@{서버}:/var/www/car-erp/public/downloads/`.
- 버전을 올리면 `kakao_agent.py` 의 `VERSION` 과 ERP `.env KAKAO_AGENT_VERSION`(기본 `config/services.php`)을 같이 올린다. 페이지가 `/ping` 의 version 과 대조해 다르면 「다시 연결」을 안내하고, `.bat` 을 다시 받아 실행하면 exe 가 교체된다.
- 서명 없는 exe 라 첫 실행에 SmartScreen 이 뜰 수 있다 → `.bat` 이 `Unblock-File` 로 표시를 지운다. 회사 PC 정책에 막히면 한 번 허용.

## 함정 (실측)
- 채팅방 창은 메인 창과 **같은 창 클래스**(`EVA_Window_Dblclk`) → 제목으로만 거른다(`open_room_titles`). 2026-11-10 실제로 0개로 나왔던 버그.
- 목록 **가운데**를 누르면 사진 뷰어가 열려 포커스를 뺏긴다 → 빈 여백(`click_fraction`)을 누른다.
- 🚨🚨 **방 창 안에서 Enter 를 치지 말 것 — 곧 전송이다.** 방 창의 Ctrl+F 는 검색을 안 연다. 11-10 16:51 「메시지 위치로 점프」 실험(Ctrl+F → 붙여넣기 → Enter)이 붙여넣기를 입력칸에 넣고 **실제로 한 줄을 보냈다**(jin 이 5분 안에 삭제). 그래서 **키를 치기 전에 `focused_child()` 로 어느 칸인지 확인**한다 — 메시지 입력칸 `RICHEDIT50W`(아래) / 대화 검색칸 `Edit`(돋보기 -109/+57 을 누르면 위에 176x22) / 목록 `EVA_VH_ListControl_Dblclk`. 붙여넣기·Enter 는 **검색칸에 포커스일 때만**(`jump_to_message`), 방을 여는 Enter 는 메인 창 검색칸에서만(`open_room`). 읽기(Ctrl+A·C)는 목록에 포커스일 때만.
- 🔎 **메시지 위치로 이동**(`jump_to_message`) = 방 열기 → 검색칸이 열려 있으면 X(-23/+110)로 닫고(지난 검색어가 남아 이어 붙는다 — jin 11-10) → 돋보기 → 검색칸 포커스 확인 → Ctrl+A → 첫 줄 40자 붙여넣기 → Enter → 그 말풍선이 선택된다(11-10 밥밥밥 「착한맛」 실측). ERP 행 [⋯] 「카톡에서 이 방 열기」가 이걸 부른다.
- 🪤 에이전트 핸들러에서 요청 본문(`self.body()`)은 **한 번만** 읽는다 — 두 번 읽으면 두 번째 `read` 가 영원히 기다려 /focus 가 60초 멈췄다(11-10).
- 🔎 **방 자동 열기**(`open_room`) = 메인 창 「채팅」 탭 돋보기(오른쪽 위에서 -109, +55px) → 이름 붙여넣기 → Enter → 방 창 → 검색 X(-29, +101). 좌표는 창 캡처 실측 — 카톡 UI 가 바뀌면 여기부터.
- 🚨 **ESC 를 무조건 치지 말 것** — 카톡 PC 기본 설정이 「ESC 로 채팅창 닫기」라 방 창이 닫히고 맨 앞이 브라우저가 되어 Ctrl+A·C 가 **ERP 페이지를 읽어 보냈다**(11-10 jin 실사고). ESC 는 사진 뷰어가 떴을 때만, 그리고 복사 전마다 「맨 앞 창 = 그 방」을 확인한다.
- 창 없는 에이전트는 백그라운드 프로세스라 `SetForegroundWindow` 가 조용히 거부될 수 있다 → ALT 키 한 번 · `AttachThreadInput` 으로 재시도하고 **정말 앞에 왔는지** 확인한다(`focus()`).
- Windows 는 `SO_REUSEADDR` 이면 같은 포트에 두 번째 프로세스가 그냥 붙는다 → `allow_reuse_address=False` 로 단일 인스턴스 판정.
- 한국어 Windows 의 `.bat` 은 CP949 로 돌아 한글 `echo` 가 깨진다 → `.bat` 은 ASCII 만.
- 최신 Chrome·Edge 는 https 페이지 → `127.0.0.1` 호출에 「로컬 네트워크 접근」 허용을 한 번 물을 수 있다 → 페이지가 자물쇠 → 「로컬 네트워크」 허용 안내를 띄운다.
- ERP 가 422 「상한」을 돌려주면 범위가 너무 넓은 것 — `config.json` 의 `first_read_days` 를 줄이거나 ERP 기능설정 상한을 올린다.

## 명령줄 진단(개발·장애 조사용)
```powershell
python collector.py windows            # 카톡 창 목록(방 창이 보이는지)
python collector.py read --dry         # 열린 방을 복사만(전송 안 함)
curl http://127.0.0.1:47321/ping       # 에이전트가 떠 있나
```
`collector.py` 의 `setup`/`read` 는 `scripts/kakao-collector/config.json`(gitignore)을 쓴다 — `%LOCALAPPDATA%\KakaoReader\config.json` 을 복사해 쓰면 된다.

## 폐기
`kakao_reader.py`(tkinter 창, 11-10 낮) · `dist/KakaoReader*.exe` — §11 로 대체(jin «EXE 가 계속 떠 있어서 불편»). 바탕화면의 `KakaoReader.exe`·`KakaoReader_v2.exe` 는 지워도 된다.
