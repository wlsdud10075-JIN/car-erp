# 다른 PC 이관 페이로드

`~/.claude/`(전역 설정)에 있어서 **git 으로 안 따라오는 것**들의 사본이다.
새 PC 의 Claude 세션에 아래 지시문을 붙여넣으면 이 폴더를 읽어 반영한다.

| 파일 | 새 PC 의 목적지 |
|---|---|
| `global-CLAUDE.md` | `~/.claude/CLAUDE.md` |
| `global-settings.json` | `~/.claude/settings.json` (⚠️ `GEMINI_API_KEY` 자리표시자) |
| `statusline-state.js` | `~/.claude/statusline-state.js` |
| `claude-dashboard.local.json` | `~/.claude/claude-dashboard.local.json` |
| `agents/car-erp-*.md` (6) | `~/.claude/agents/` |

**여기 없는 것** — git 으로 못 옮긴다:
- 마켓플레이스 서브에이전트 181개(3.1MB, 재설치 가능) — 필요하면 `~/.claude/agents/` 폴더를 직접 복사
- `claude-dashboard` (`C:\xampp\htdocs\claude-dashboard-main\`) — 제3자 도구, 폴더 복사
- 비밀값(GEMINI 키·APP_KEY·SSH 키) — 각자

🔄 **집 PC 설정이 바뀌면 이 폴더도 갱신**해야 한다. 안 그러면 새 PC 가 옛 설정을 받는다.
갱신 = 위 표의 원본 5종을 다시 복사하고 `global-settings.json` 의 키만 다시 가린다.
