# 다른 PC에 이 Claude Code 환경 그대로 옮기기

> 작성 2026-09-11 (jin 요청). 기준 = 집 PC(`C:\xampp\htdocs\car-erp`) 실측 설정.
> 이 문서는 `.md` 라 **dev 브랜치에만** 있다.
>
> **옮길 것은 딱 두 덩어리다.** 나머지는 자동이거나 각자 넣는 값이다.

---

## 옮길 것

| | 무엇 | 어디로 |
|---|---|---|
| **A** | `C:\Users\User\.claude\` 안의 **5가지** | 새 PC 의 `%USERPROFILE%\.claude\` |
| **C** | `C:\xampp\htdocs\claude-dashboard-main\` 폴더 통째 | 새 PC 의 **같은 경로** |

> 레포(`car-erp`)는 **git 으로 자동**이다 — `.claude/settings.json`·`hooks/`·`skills/` 가 이미 커밋돼 있어
> `git checkout dev && git pull` 하면 프로젝트 훅·스킬·권한이 그대로 따라온다. 손댈 것 없다.

### A — `~/.claude/` 에서 가져갈 5가지

```
CLAUDE.md                  전역 지침 (한국어 응답 · Codex 위임 · 팬아웃 작업방식)
settings.json              권한 프리셋 · 상태줄 · 플러그인 · 테마
statusline-state.js        상태줄 래퍼
claude-dashboard.local.json 상태줄 표시 항목 (한국어·3줄 구성)
agents\                    서브에이전트 187개 (car-erp-po/engineer/qa/security/ops/specialist 포함)
skills\                    전역 스킬 (deep-interview)
```

🚫 **나머지는 가져가지 말 것** — 그 PC 전용이거나 자동 생성된다:
`.credentials.json`(각자 로그인) · `history.jsonl` · `sessions\` · `cache\` · `file-history\` ·
`paste-cache\` · `session-env\` · `backups\` · `daemon\` · `jobs\` · `plugins\`(첫 실행 때 자동 수신)

### 복사 명령

```powershell
# ── 집 PC 에서 (D:\claude-이관 은 USB·공유폴더 등)
$src = "$env:USERPROFILE\.claude"
$dst = "D:\claude-이관"
New-Item -ItemType Directory -Force "$dst\dotclaude" | Out-Null
Copy-Item "$src\CLAUDE.md","$src\settings.json","$src\statusline-state.js","$src\claude-dashboard.local.json" "$dst\dotclaude"
Copy-Item "$src\agents","$src\skills" "$dst\dotclaude" -Recurse
Copy-Item "C:\xampp\htdocs\claude-dashboard-main" "$dst\claude-dashboard-main" -Recurse

# ── 새 PC 에서
Copy-Item "D:\claude-이관\dotclaude\*" "$env:USERPROFILE\.claude\" -Recurse -Force
Copy-Item "D:\claude-이관\claude-dashboard-main" "C:\xampp\htdocs\" -Recurse -Force
```

---

## 새 PC 에서 손봐야 하는 3곳

붙여넣기만으로는 안 되는 것들이다.

**① 대시보드 경로** — `statusline-state.js` 상단에 **절대경로가 박혀 있다**:
```js
const DASHBOARD = 'C:/xampp/htdocs/claude-dashboard-main/claude-dashboard-main/dist/index.js';
```
같은 경로에 뒀으면 그대로 두고, 다르면 이 한 줄을 고친다.
⚠️ 안 맞아도 **에러는 안 난다** — 뱃지(`⚪ 대기`)만 나오고 모델·컨텍스트·비용·사용량이 통째로 빈다(실측).

**② 추가 디렉터리** — `settings.json` 의 `permissions.additionalDirectories` 가 집 PC 경로다.
```json
"additionalDirectories": ["C:/Users/User/Desktop/스크린샷", "C:/Users/User/Pictures/Screenshots"]
```
새 PC 의 실제 스크린샷 폴더로 바꾼다(없으면 그냥 지워도 된다).

**③ API 키** — `settings.json` 의 `env.GEMINI_API_KEY` 는 **실제 키**다(`/cross-verify` 용).
그대로 써도 동작하지만 키 하나를 두 PC 가 공유하게 되니, 되도록 각자 발급해 바꾼다.

---

## 함께 챙길 것 (복사가 아니라 각자)

```powershell
npm install -g @anthropic-ai/claude-code     # 없으면
npm install -g @openai/codex                 # Codex 위임용
npm install -g @google/gemini-cli            # /cross-verify 용
gh auth login                                # 배포 런 확인에 필수
claude                                       # 실행 → 브라우저 로그인 (각 PC 따로)
```

레포 세팅(PHP 확장·composer·npm·DB)은 **`CLAUDE.md` 의 「새 PC 세팅」** 절을 따른다.

🚨 **`php artisan key:generate` 절대 금지.** APP_KEY 가 바뀌면 RRN(주민등록번호)이 전량 복호화 불능이 된다.
집 PC `.env` 의 `APP_KEY=` 값을 그대로 새 PC `.env` 에 넣는다. **`.env` 통째 복사는 말 것**(DB 접속 등은 그 PC 값).

운영 서버를 볼 거면 SSH 키도: `~/.ssh/car_erp_key` 복사 후 권한을 본인만으로 좁힌다.

---

## 메모리는 옮길지 정해야 한다

```
~\.claude\projects\C--xampp-htdocs-car-erp\memory\
```
지난 결정·함정·재제안 금지 목록이 여기 쌓여 있다. **A 에 안 넣었다** — 판단이 필요해서다.

- **복사하면**: 새 PC 가 첫 세션부터 같은 맥락을 안다. 단 **그 순간의 스냅샷**이라 이후 두 PC 가 갈라진다.
- **안 하면**: 새 PC 가 이미 정한 것을 다시 제안한다.

권장 = **한 번 복사하고, 갈라진다는 걸 알고 쓴다.** 중요한 결론은 어차피 `CLAUDE.md`·`SKILLS.md`·`docs/`
같은 **git 파일**에 남기는 게 원칙이라(전역 CLAUDE.md 「크로스 레포 규칙」), 메모리가 갈려도 핵심은 전파된다.

---

## 확인 (새 PC 에서 순서대로)

```powershell
# ① 전역 지침 — 응답이 한국어로 오면 정상
claude

# ② 상태줄 — 세 줄이 나와야 정상. 한 줄이면 ①번 경로 문제
echo '{}' | node "$env:USERPROFILE\.claude\statusline-state.js"

# ③ 레포
cd C:\xampp\htdocs\car-erp
git checkout dev; git pull origin dev
php artisan migrate:status                        # Pending 없어야
php artisan test --filter=LocaleKeyParityTest     # 초록이면 PHP·의존성 OK

# ④ 훅 — Claude 에게 "php artisan test 돌려줘" 시키면 「필터나 로그를 붙이라」고 막혀야 정상
# ⑤ 스킬 — 세션에서 / 입력 → car-erp-excelupload · 회의 · deep-interview 가 보여야
```

## 어긋나면 여기부터

| 증상 | 원인 |
|---|---|
| 응답이 영어로 온다 | `~/.claude/CLAUDE.md` 미복사 |
| 상태줄에 뱃지만 뜬다 | 대시보드 경로 불일치 (손볼 곳 ①) |
| 전체 테스트가 안 막힌다 | PHP 가 PATH 에 없다 — 훅이 fail-open |
| 스킬이 목록에 없다 | 레포를 **dev** 브랜치로 안 받았다 |
| `/cross-verify` 가 없다 | 플러그인 미수신 — `/plugin` 으로 수동 설치 |
| 서류 생성이 깨진다 | `php.ini` 의 `extension=gd`·`extension=zip` 주석 해제 안 함 |
| 「아까 정했잖아」를 다시 묻는다 | 메모리 미복사 |

---

## 옮긴 뒤 두 PC 를 쓸 때

- 작업은 **dev 브랜치**에서, 결론은 **git 에 커밋**한다. 메모리는 PC 별이라 안 따라온다.
- **APP_KEY 는 두 PC 가 같아야 한다.** 다르면 한쪽이 만든 RRN 을 다른 쪽이 못 읽는다.
- PC 를 바꿔 앉으면 `CLAUDE.md` 의 **「다른 PC에서 작업 재개 절차」**(pull → migrate:status →
  composer/npm → cache clear → test)를 따른다.
