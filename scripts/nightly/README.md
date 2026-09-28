# 야간 배치 실행기 — 회사 GPU PC 스크립트 (2026-09-28)

회사 GPU PC(`gpu-office`, Tailscale `100.110.133.112`, 사용자 `SSANCAR`)의 `C:\Users\SSANCAR\nightly\` 에 있는 것의 **레포 사본**이다.
정본 기획 = `docs/design/agent-virtual-office-analysis.md` §11. 배포 대상 아님(서버에서 안 쓴다).

| 파일 | 무엇 | 스케줄 |
|---|---|---|
| `nightly-investigate.ps1` | 3사 서버 읽기 전용 수집 → `claude -p`(Read/Grep/Glob 만) → `reports\report-YYYYMMDD.md` | 작업 스케줄러 「SSANCAR Nightly Investigate」 매일 03:30 |
| `prompt.md` | 조사 지시문(한국어, 1,500자 이내 출력) | — |

- 텔레그램 전송(`-SendTelegram`)은 2~3일 파일 관찰 뒤 켠다. 경로 = heymanerp `php artisan notify:send --body=-`(토큰은 서버에만).
- ⚠️ 세션 0(스케줄러·SSH) 의 PowerShell 에서 `& ssh …` 로 출력을 잡으면 영영 멈춘다 — 콘솔 앱은 `Start-Process` + 파일 리다이렉트로만(SKILLS §8 #112).
- 회사 PC 갱신: 이 폴더를 고친 뒤 `scp -i ~/.ssh/car_erp_key scripts/nightly/* SSANCAR@100.110.133.112:C:/Users/SSANCAR/nightly/`.
