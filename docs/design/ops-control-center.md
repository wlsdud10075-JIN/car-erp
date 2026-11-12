# 관제센터 (Control Center) — 설계자용, jin 단독

> 시작 2026-11-12 (jin «한곳에 몰아서 볼 수 있는 게 필요 — 텔레그램·알림톡·Notion 으로 나눠 받아 헷갈렸고, 아카이브·메모리·문서에 뭐가 저장됐는지 알 수 없었다»).
> 목업 = https://claude.ai/artifact/MSFdABnHjthGcxcP5Wq46u (jin 「이대로 하면 괜찮을 것 같다」).

## 1. 원칙
- **오직 jin** — 시스템관리자(permission=super) **그리고** `OPS_OWNER_EMAILS` 에 적힌 계정. super 를 늘려도 저절로 열리지 않는다. 목록이 비면 아무도 못 연다. 그 외 사용자는 404, super 가 아니면 403.
- **회사 돈 숫자는 넣지 않는다**(jin «각 회사 금액을 알 필요 없다 — 시스템 설계자로서 알아야 할 것»). 예외 1건 = 이상 감지 「미수 하루 급변」 줄이 근거로 금액(±N억)을 한 줄 싣는다 — jin 확인 대기.
- **한 곳이 죽어도 버틴다** — 사이트마다 4초 타임아웃, 5분 캐시(실패는 1분), 그 칸만 「응답 없음」.

## 2. 단계
| 단계 | 내용 | 상태 |
|---|---|---|
| 1 | 예약 작업 실행 기록 `scheduled_task_runs`(리스너 `RecordScheduledTaskRun`, 90일 보관) | ✅ 3사 배포 11-12 `6cfb09b0` |
| 2 | 사이트 요약 API + `/admin/control` 화면(사이트 6칸·이상 감지·예약 작업) | dev `0d9beb1b` |
| 3 | 세컨드 브레인(교훈·메모리·문서 지도, 밤마다 목록 업로드) | 착수 전 |

## 3. 설정(.env)
| 키 | 어디에 | 값 |
|---|---|---|
| `OPS_HMAC_SECRET` | 3사 ERP · board 2곳 **같은 값** | 64자 무작위 |
| `OPS_OWNER_EMAILS` | ssancarerp(허브)만 | `wlsdud10074@naver.com` (쉼표로 여럿) |
| `OPS_SITES` | ssancarerp(허브)만 | JSON 배열 — 아래 |

`OPS_SITES` 항목 = `{"key","name","kind":"erp|board|etc","url"|"self":true,"path"(기본 `/api/internal/ops/summary` — 그누보드처럼 다른 경로면 지정),"host","notes":[…],"ssh"}`. 줄 셋 = ERP / BOARD / ETC(GPU PC · ssancar.com, jin 11-12). 허브 자신은 `"self":true`. url 이 없으면 「요약 API 없음 — 메모만」(GPU PC 는 notes 만 보여 주고 이상 감지에 안 오른다).
⚠️ 운영은 배포 때 `config:cache` — **env 를 먼저 넣고 배포**하거나, 나중에 넣었으면 `php artisan config:cache` 를 다시.

## 4. 요약 API 계약 (board 도 같은 것을 붙이면 저절로 들어온다)
- `GET /api/internal/ops/summary` · 본문 없음.
- 서명 = ERP 끼리 채널과 같은 정규 문자열: `METHOD\npath?query\nts\nbody`(query 는 ksort + http_build_query, 여기선 빈 값) → `hash_hmac('sha256', …, OPS_HMAC_SECRET)`.
  헤더 = `X-Timestamp`(초, ±300) · `X-Nonce`(1회용) · `X-Board-Signature: sha256=<hex>`. 단일 출처 = `App\Http\Middleware\VerifyErpPeerHmac::canonical`.
- 시크릿이 비면 **401**(fail-closed). 없는 경로면 허브가 「미연결」로 그린다.
- 응답(JSON) 최소 = `server.version`(짧은 SHA) + `anomalies[]`. 나머지 키는 비워도 된다.
  ```
  { "generated_at", "company",
    "server":   { "version", "php", "laravel", "env", "pending_migrations", "disk_used_pct", "db_mb", "errors_today" },
    "routines": [ { "command", "expression", "status": "ok|failed|running", "started_at", "duration_ms", "error", "last_ok_at" } ],
    "health":   [ { "key", "ok", "detail" } ],
    "links":    { "alimtalk_sent_today", "alimtalk_failed_today", "claude_cost_today_krw", "keys": { "<이름>": true|false } },
    "anomalies":[ { "level": 1|2|3, "kind", "count", "text" } ] }
  ```
  🚫 키 원문·개인정보·회사 돈 숫자를 싣지 않는다(keys 는 있음/없음만).

## 5. 이상 감지 규칙(ERP — `OpsSummary::anomalies`)
판매가 단위 의심(외화 판매가×환율 > 매입가×10, 11-02·11-09 실례) · 환율 범위 밖(USD 800~2,500 · EUR 900~3,000) · 미수 스냅샷 하루 ±20억 · 삭제된 바이어/컨사이니를 가리키는 차 · 마지막 정기 작업 실패 · 최근 30일 차종 빈 차 · 카톡 읽다 멈춘 방. 허브가 덧붙이는 것 = 사이트 연결 실패 · ERP 3사 버전 어긋남.

## 6. 남은 것
- board 요약 API — board dev `95b1070` 구현 완료(11-12), board master 배포 + 두 박스 `OPS_HMAC_SECRET` 투입은 jin 허락 뒤(board 세션). 붙기 전엔 「미연결」.
- ssancar.com 요약 API — 11-12 ssancar 세션 착수. 경로 `https://www.ssancar.com/page/api_ops_summary.php`(그쪽 제안 — `_ops/` 는 문서 폴더, 기존 서명 API 가 `page/` 에 있음 · `ssancar.com` 은 www 로 301). 허브 나가는 IP 54.116.7.83 은 그쪽 지오게이트 통과 목록에 이미 있음. 시크릿 = 그 서버 `/opt/bitnami/ssancar_secrets/ops_control.json` {"hmac_secret"} (jin 이 넣음, 운영은 수동 업로드). 1차 응답 = version·php·disk · health(ERP 미러 소스별 마지막 성공) · anomalies(미러 13h 없음·미러 실패·디스크 90%). ⚠️ ssancar 지적: nonce 가 서명 대상 밖이라 ±300초 안 nonce 만 바꾼 재전송이 가능(HTTPS·읽기 전용 GET — 바꿀지는 jin 판단, 바꾸면 3곳 동시).
- 루틴 표에 「마지막 성공」 열 · 「N시간 넘게 running」 규칙(기록이 쌓인 뒤 결정).
- 3단계 세컨드 브레인.
