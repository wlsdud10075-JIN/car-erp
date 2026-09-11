# 다른 PC에 이 Claude Code 환경 그대로 옮기기

> 작성 2026-09-11 (jin 요청). **기준 = 집 PC(`C:\xampp\htdocs\car-erp`)의 실측 설정.**
> 이 문서는 `.md` 라 **dev 브랜치에만** 있다. 다른 PC에서 `git checkout dev && git pull` 하면 딸려온다.
>
> 🎯 목적 = 다른 PC에서 **똑같은 방식으로** Claude Code 를 쓰는 것.
> 한국어 응답·전역 작업규칙·권한 프리셋·상태줄·서브에이전트·스킬까지 전부 포함한다.

---

## 0. 전체 지도 — 무엇이 어디에 있나

Claude Code 의 동작은 **4곳**에서 온다. 하나라도 빠지면 다르게 굴러간다.

| # | 위치 | 무엇 | 어떻게 옮기나 |
|---|---|---|---|
| **A** | `C:\Users\<사용자>\.claude\` | 전역 지침·설정·상태줄·서브에이전트·스킬 | **손으로 복사** (git 밖) |
| **B** | 레포 `car-erp/.claude/` · `CLAUDE.md` · `CLAUDE_1.md` · `SKILLS.md` | 프로젝트 규칙·훅·스킬 | **git pull 로 자동** |
| **C** | `C:\xampp\htdocs\claude-dashboard-main\` | 상태줄이 감싸는 대시보드 | **별도 설치** (같은 경로에) |
| **D** | 비밀값 (API 키·APP_KEY·SSH 키) | — | **절대 복사본으로 돌리지 말고 각자 넣기** |

> ⚠️ **B 는 이미 git 에 있다.** `.claude/settings.json` · `.claude/hooks/` · `.claude/skills/` 가 커밋돼 있으니
> 레포만 clone/pull 하면 프로젝트 훅·스킬·권한은 그대로 따라온다. **손댈 필요 없다.**

---

## 1. 먼저 — Claude Code 와 필수 CLI

```powershell
# Claude Code (없으면)
npm install -g @anthropic-ai/claude-code

# 이 환경이 쓰는 외부 CLI
npm install -g @openai/codex          # Codex 위임용 (전역 CLAUDE.md 규칙)
npm install -g @google/gemini-cli     # /cross-verify 스킬용
gh auth login                         # GitHub CLI — 배포 런 확인에 필수
```

로그인은 각 PC에서 따로 한다(`claude` 실행 → 브라우저 인증). **`.credentials.json` 을 복사하지 말 것.**

레포 자체 세팅(PHP 확장·composer·npm·DB)은 **`CLAUDE.md` 의 「새 PC 세팅」 절**을 따른다.
🚨 그중 **`php artisan key:generate` 는 절대 실행 금지** — APP_KEY 가 바뀌면 RRN(주민등록번호)이 전량 복호화 불능이 된다.
집 PC 의 `.env` 에서 `APP_KEY=` 값을 그대로 복사해 넣는다.

---

## 2. A — `~/.claude/` 복사 (핵심)

집 PC 의 `C:\Users\User\.claude\` 에서 **아래 4가지만** 새 PC 의 같은 위치로 복사한다.

```
CLAUDE.md                  ← 전역 지침 (한국어 응답·Codex 위임·팬아웃 작업방식)
settings.json              ← 권한·상태줄·플러그인·테마  ⚠️ API 키는 아래 4번 참고
statusline-state.js        ← 상태줄 래퍼
agents\                    ← 서브에이전트 187개 (car-erp-* 6개 포함)
skills\deep-interview\     ← 전역 스킬
```

🚫 **복사하면 안 되는 것** — 전부 그 PC 전용이거나 자동 생성된다:
```
.credentials.json   로그인 토큰 (각자 로그인)
history.jsonl       대화 기록
sessions\ cache\ file-history\ paste-cache\ session-env\ backups\ daemon\ jobs\
projects\           ⚠️ 아래 5번 참고 (메모리)
plugins\            자동 생성 — settings.json 의 선언만 있으면 첫 실행 때 받아온다
```

### 복사 명령 예시 (집 PC → USB·공유폴더)

```powershell
$src = "$env:USERPROFILE\.claude"
$dst = "D:\claude-이관"          # 옮길 매체
New-Item -ItemType Directory -Force $dst | Out-Null
Copy-Item "$src\CLAUDE.md","$src\settings.json","$src\statusline-state.js" $dst
Copy-Item "$src\agents" $dst -Recurse
Copy-Item "$src\skills" $dst -Recurse
```
새 PC 에서는 같은 파일들을 `%USERPROFILE%\.claude\` 아래로 넣으면 된다.

---

## 3. C — 상태줄 대시보드

상태줄은 **두 겹**이다. `statusline-state.js`(세션 상태 뱃지)가 **claude-dashboard**(모델·컨텍스트·비용·사용량)를 감싼다.

```
🟢 진행중  ◆ opus │ ▓▓▓░░░ │ 32% │ 64K/200K │ $1.20 │ 5시간: 12% │ 7일: 6%
⏱️ 42분
🔷️ gpt-5.6-sol │ 5시간: 0% │ 🔥️ 0/min │ 📦️ 0%
```

**🚨 `statusline-state.js` 안에 절대경로가 박혀 있다** (파일 상단 `DASHBOARD` 상수):
```
C:/xampp/htdocs/claude-dashboard-main/claude-dashboard-main/dist/index.js
```
⇒ 새 PC 에도 **같은 경로에 두거나**, 다르면 그 한 줄을 고친다. 안 그러면 뱃지만 뜨고 나머지가 빈다.

대시보드 설정도 함께 복사한다 — `~/.claude/claude-dashboard.local.json`:
```json
{ "language": "ko", "plan": "max", "displayMode": "custom",
  "lines": [["model","context","cost","rateLimit5h","rateLimit7d"],
            ["projectInfo","linesChanged","sessionDuration","todoProgress"],
            ["codexUsage","burnRate","cacheHit"]],
  "disabledWidgets": ["geminiUsage","geminiUsageAll"],
  "theme": "default", "separator": "pipe", "cache": { "ttlSeconds": 60 } }
```

**확인**: `echo '{}' | node %USERPROFILE%\.claude\statusline-state.js` → 세 줄이 나오면 정상.

---

## 4. D — 비밀값·PC별 값 (복사본 그대로 쓰면 안 되는 것)

`settings.json` 을 복사한 뒤 **아래 3가지는 그 PC 기준으로 고친다.**

| 항목 | 어디 | 어떻게 |
|---|---|---|
| `env.GEMINI_API_KEY` | `settings.json` | 집 PC 값을 그대로 써도 동작하지만, **키 하나를 두 PC 가 공유**하게 된다. 되도록 각자 발급. |
| `permissions.additionalDirectories` | `settings.json` | 집 PC 는 `Desktop\스크린샷`·`Pictures\Screenshots`. **새 PC 의 실제 경로로** 바꾼다. |
| SSH 키 `car_erp_key` | `~/.ssh/car_erp_key` | 운영 서버 접속용. 집 PC 에서 복사하고 **권한을 좁힌다**(`icacls` 로 본인만). |

🚫 **`.env` 를 통째로 복사하지 말 것** — `APP_KEY` 만 맞추고 나머지(DB 접속 등)는 그 PC 값으로.

---

## 5. 메모리 — 옮길지 말지 정해야 한다

```
~\.claude\projects\C--xampp-htdocs-car-erp\memory\
```
여기에 `MEMORY.md`(색인) + `project_*.md` / `feedback_*.md` / `_archive\` 가 있다.
**프로젝트 맥락의 대부분**이 여기 쌓여 있다 — 지난 결정·함정·재제안 금지 목록 등.

| | 복사한다 | 안 한다 |
|---|---|---|
| 장점 | 새 PC 가 **첫 세션부터 같은 맥락**을 안다 | 두 PC 가 안 엉킨다 |
| 단점 | **그 순간의 스냅샷**이다 — 이후 두 PC 가 각자 쌓아 갈라진다 | 새 PC 가 이미 정한 것을 다시 제안한다 |

**권장 = 한 번 복사하고, 갈라진다는 걸 알고 쓴다.**
중요한 결론은 어차피 **`CLAUDE.md`·`SKILLS.md`·`docs/` 같은 git 파일**에 남기는 게 원칙이라
(전역 CLAUDE.md 의 「크로스 레포 규칙」), 메모리가 갈려도 핵심은 git 으로 전파된다.

---

## 6. 플러그인

`settings.json` 에 선언만 있으면 **첫 실행 때 자동으로 받아온다** — 따로 설치할 것 없다.
```json
"enabledPlugins": { "cross-verify@devbrother-plugins": true },
"extraKnownMarketplaces": {
  "devbrother-plugins": { "source": { "source": "github", "repo": "devbrother2024/claude-plugins" } }
}
```
네트워크가 막혀 안 받아지면 `/plugin` 으로 수동 설치.

**MCP 서버는 쓰지 않는다**(실측 0개) — 옮길 것 없다.

---

## 7. 이 환경의 「사용법」 — 새 PC 에서도 그대로 되는 것

아래는 전부 **A(전역) + B(레포)** 만 맞추면 자동으로 따라온다.

**전역 CLAUDE.md 가 정하는 것**
- **모든 응답이 한국어** (진행 상황 줄까지 포함)
- **Codex 위임** — Notion 발행 같은 실행형 반복 작업은 Claude 가 전달 패킷만 만들고 Codex 가 실행
- **오케스트레이터 팬아웃** — 조사 갈래가 둘 이상이면 서브에이전트로 쪼개고, **쓰기·배포는 main 단독**

**레포 CLAUDE.md / SKILLS.md 가 정하는 것**
- 세션 시작 시 `CLAUDE.md` → `CLAUDE_1.md` → `SKILLS.md` 순서로 로드
- 3사 동시배포 체크리스트, 정산 공식, 진행상태 v4 cascade, 재발 버그 88건

**권한 프리셋** (`settings.json`)
- `defaultMode: "auto"` — 읽기·일상 명령은 묻지 않고 진행
- `ask` = `git push origin master`(3사 배포)·force push·`git reset --hard`
- `deny` = `php artisan key:generate`(RRN 전량 손실)·`rm -rf /`·`rm -rf ~`

**훅** (레포, git 으로 따라옴)
- `.claude/hooks/guard-long-test.php` — 필터도 로그도 없는 전체 테스트를 막는다(20분 헛대기 방지).
  ⚠️ PHP 가 PATH 에 있어야 동작한다. 없으면 조용히 통과한다(fail-open).

**스킬** (레포)
- `/car-erp-excelupload` 차량 적재양식 import · `/consignee-import` 컨사이니 일괄 등록 · `/회의` 라운드테이블
- 전역: `/deep-interview`, 플러그인 `/cross-verify`

**서브에이전트** — `car-erp-po` / `-engineer` / `-qa` / `-security` / `-ops` / `-specialist` 6종 + 범용 다수

---

## 8. 확인 체크리스트 (새 PC 에서 순서대로)

```powershell
# ① 전역 지침이 읽히나 — 응답이 한국어로 오면 정상
claude              # 아무거나 물어본다

# ② 상태줄
echo '{}' | node "$env:USERPROFILE\.claude\statusline-state.js"     # 세 줄 출력

# ③ 레포 쪽
cd C:\xampp\htdocs\car-erp
git checkout dev; git pull origin dev
php artisan migrate:status                # Pending 없어야
php artisan test --filter=LocaleKeyParityTest    # 초록이면 PHP·의존성 OK

# ④ 훅 — 아래를 Claude 에게 시켜 보면 막혀야 정상
#    "php artisan test 돌려줘"  → 가드가 「필터나 로그를 붙이라」고 막는다

# ⑤ 서브에이전트·스킬이 보이나
#    Claude 세션에서 /  입력 → car-erp-excelupload · 회의 · deep-interview 가 목록에 있어야

# ⑥ 운영 접속 (필요할 때만)
ssh -i ~/.ssh/car_erp_key ubuntu@52.79.200.151 "echo ok"
```

---

## 9. 자주 어긋나는 곳

| 증상 | 원인 |
|---|---|
| 응답이 영어로 온다 | `~/.claude/CLAUDE.md` 미복사 |
| 상태줄에 뱃지만 뜨고 나머지가 빈다 | claude-dashboard 경로 불일치 (3번) |
| 전체 테스트가 안 막힌다 | PHP 가 PATH 에 없다 — 훅이 fail-open |
| 스킬이 목록에 없다 | 레포를 dev 브랜치로 안 받았다(`.claude/skills/` 는 dev 에 있다) |
| `/cross-verify` 가 안 뜬다 | 플러그인 미수신 — `/plugin` 수동 설치 |
| 서류 생성이 깨진다 | `php.ini` 에서 `extension=gd`·`extension=zip` 주석 해제 안 함 |
| 「이건 아까 정했잖아」를 다시 물어본다 | 메모리 미복사 (5번) |

---

## 10. 옮긴 뒤 — 두 PC 를 쓸 때

- **작업은 `dev` 브랜치에서, 결론은 git 에 커밋한다.** 메모리는 PC 별이라 안 따라온다.
- **`APP_KEY` 는 두 PC 가 같아야 한다.** 다르면 한쪽에서 만든 RRN 을 다른 쪽이 못 읽는다.
- PC 를 바꿔 앉으면 `CLAUDE.md` 의 **「다른 PC에서 작업 재개 절차」**(pull → migrate:status → composer/npm → cache clear → test)를 따른다.
