# 야간 배치 실행기 — 회사 GPU PC 스크립트 (2026-09-28)

회사 GPU PC(`gpu-office`, Tailscale `100.110.133.112`, 사용자 `SSANCAR`)의 `C:\Users\SSANCAR\nightly\` 에 있는 것의 **레포 사본**이다.
정본 기획 = `docs/design/agent-virtual-office-analysis.md` §11. 배포 대상 아님(서버에서 안 쓴다).

| 파일 | 무엇 | 스케줄 |
|---|---|---|
| `nightly-investigate.ps1` | 3사 서버 읽기 전용 수집 → `claude -p`(Read/Grep/Glob 만) → `reports\report-YYYYMMDD.md` | 작업 스케줄러 「SSANCAR Nightly Investigate」 매일 03:30 |
| `prompt.md` | 조사 지시문(한국어, 1,500자 이내 출력) | — |
| `nightly-notion.ps1` | car-erp·board pull → Notion `--verify` 4종(PHP 만, 읽기 전용) → `reports\notion-YYYYMMDD.md`. `-Apply` 일 때만 Codex 가 불일치분을 타깃 발행 후 재검증 | 작업 스케줄러 「SSANCAR Nightly Notion」 매일 04:30 (**관찰 모드 = -Apply 없음**, 09-29) |
| `notion-prompt.md` | `-Apply` 때 Codex 에 주는 지시문 — AGENTS.md·CODEX_NOTION_HANDOFF.md §9 대로 타깃 발행만, 금지 목록 재명시 | — |

- 텔레그램 전송(`-SendTelegram`)은 2~3일 파일 관찰 뒤 켠다. 경로 = heymanerp `php artisan notify:send --body=-`(토큰은 서버에만).
- ⚠️ 세션 0(스케줄러·SSH) 의 PowerShell 에서 `& ssh …` 로 출력을 잡으면 영영 멈춘다 — 콘솔 앱은 `Start-Process` + 파일 리다이렉트로만(SKILLS §8 #112).
- 🪟 **`.ps1` 은 UTF-8 BOM + CRLF 로 저장**(python `utf-8-sig`). BOM 없으면 PowerShell 5.1 이 CP949 로 읽어 **한글로 끝나는 줄의 개행을 먹어 다음 줄이 주석에 붙는다**(09-29 실측 — 변수 전부 null). `Start-Process -PassThru` 뒤 `ExitCode` 는 `$p.Handle` 을 한 번 건드려야 값이 온다.
- 🤖 Codex(회사 PC): 설치 폴더 `bin` 이 정션 2단이라 **SSH 세션에선 실제 경로**(`.codex\packages\standalone\releases\<버전>\bin\codex.exe`)로만 뜨고, 명령 실행(sandbox runner)은 **대화형 로그온 스케줄러에서만** 된다(SSH 에선 `runner pipe-in` 타임아웃). 플래그 = `exec -C C:\xampp\htdocs --skip-git-repo-check -s workspace-write -c sandbox_workspace_write.network_access=true -o <파일> -`(프롬프트는 stdin). 09-29 실측 통과.
- 📄 Codex 인계문서(`C:\xampp\htdocs\AGENTS.md`·`CODEX_*.md`·`codex-notion.ps1`·`codex-status.ps1`·`notion-hub-inspect.php`·`notion-dev-status-erp-latest.php`·`NOTION_TOKEN_HOWTO.txt`)는 09-29 에 노트북에서 회사 PC 같은 경로로 **scp 복사**했다. ⚠️ 레포 밖이라 자동 동기화 없음 — 노트북에서 바뀌면 다시 복사(동기화 채널은 jin 결정 대기). 회사 PC 사본의 `codex-status.ps1` 만 `$env:USERPROFILE` 로 고쳤다.
- 회사 PC 갱신: 이 폴더를 고친 뒤 `scp -i ~/.ssh/car_erp_key scripts/nightly/* SSANCAR@100.110.133.112:C:/Users/SSANCAR/nightly/`.
