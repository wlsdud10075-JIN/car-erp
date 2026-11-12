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
| 2 | 사이트 요약 API + `/admin/control` 화면(사이트 7칸·이상 감지·예약 작업) | ✅ 3사 배포 11-12 `07cdab26` → `417fbb1a`(ETC 줄·path) |
| 3 | 세컨드 브레인 — `ops:brain-sync --push` (개발 PC) → `POST /api/internal/ops/brain` → 3D 지도·목록·찾기·본문 | dev 커밋(미배포). 목록은 이 PC 에서 올린다(밤마다 자동은 후속) |

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
- 서명 **v2**(jin 11-12 결정 — nonce 를 서명 안에): `METHOD\npath?query\nts\nnonce\nbody`(query 는 ksort + http_build_query, 여기선 빈 값) → `hash_hmac('sha256', …, OPS_HMAC_SECRET)`. 단일 출처 = `App\Services\Ops\OpsSignature::canonicalV2`.
  헤더 = `X-Timestamp`(초, ±300) · `X-Nonce`(1회용) · `X-Board-Signature: sha256=<hex>`.
  전환은 11-12 당일 끝났다: board(faebd7a)·ssancar.com(5b0d26cd)이 v2 를 먼저 받고 → ERP `1ec946c8` 가 v2 로 보냄(실측 7칸 전부 v2) → jin «전달해줘» 로 세 곳 v1 삭제 통보. ERP 는 v1 대조·폴백을 지웠다(다음 배포). 🚫 v1 을 다시 받지 말 것.
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
- ✅ **사이트 카드 접기·오와열 = 11-12 밤 dev `b46dd091`**(jin «회사 GPU PC 내용이 너무 길어 보드가 안 이쁘다 — ERP·BOARD 처럼 접고 펼치게, 전부 가지런히» → «진행하자») — 한 줄의 카드 같은 높이(`.ops-row` stretch, 링크는 바닥), GPU PC 메모·점검도 「▸ 메모 · 점검」 접힘(머리줄 = Ollama 응답·확인 시각 한 줄 + 이상 줄), 접힘 내용 순서 = 메모 → PHP/DB → 키 → 알림톡·Claude → 점검. 보드 폭 1400 → **1720**(jin «양쪽 빈 공간이 상당한데 좌우폭을 좀 넓혀보자»). 실측(Playwright 1920px) = 카드 175px 균일 · 보드 1660px.
- ✅ **SITES 접기 = 「칩 한 줄로 압축」(11-12 밤 dev `08340db6`)** — jin «ERP·BOARD·ETC 각각 접고 SITES 보드도 접게. 접는 것 말고 색다른 방식?» → 접으면 사라지는 게 아니라 **신호등 칩**(`● 이름 버전 · 이상 N` / GPU = Ollama 정상·없음 / 응답 없음)으로 바뀐다. 줄 이름 클릭 = 그 줄 칩화, SITES 머리줄 클릭 = 보드 전체가 7칩 한 줄(66px). 칩 클릭 = 그 줄 펼침. 상태 = `localStorage ops_sites_fold_v1`(브라우저별). 칩 데이터는 Blade `@php $chips` 한 곳에서 만들어 두 자리(보드 줄·각 줄)에 같은 걸 쓴다.
- ✅ board 요약 API — board master `801b381` 두 박스 배포 + 시크릿 투입(11-12) → 허브에서 읽힘.
- 🔔 **nonce 를 서명에 넣을지** — jin 11-12 «셋 다 붙고 얘기해줘». 이제 ERP·board·ssancar.com 전부 붙었다 → jin 결정 대기(바꾸면 3곳 같은 날).
- 세컨드 브레인 목록 자동 갱신(이 PC 작업 스케줄러로 밤마다 `ops:brain-sync --push`) — 후속.
- 회사 GPU PC 칸 = `"probe":"assistant"` → `assistant:health-check`(5분, ssancarerp .env `ASSISTANT_OLLAMA_URL=http://100.110.133.112:11434`) 캐시로 Ollama 응답·색인 시각을 보여 준다(그 PC 에 API 없이). 5분 넘게 응답 없으면 이상 「GPU PC 응답 없음」.
- ✅ ssancar.com 요약 API 운영 반영(11-12 14:25, jin 직접) — 허브에서 읽힘(6b1e9164, health 13줄). 아래는 경위:
- ssancar.com 요약 API — 11-12 ssancar 세션 착수. 경로 `https://www.ssancar.com/page/api_ops_summary.php`(그쪽 제안 — `_ops/` 는 문서 폴더, 기존 서명 API 가 `page/` 에 있음 · `ssancar.com` 은 www 로 301). 허브 나가는 IP 54.116.7.83 은 그쪽 지오게이트 통과 목록에 이미 있음. 시크릿 = 그 서버 `/opt/bitnami/ssancar_secrets/ops_control.json` {"hmac_secret"} (jin 이 넣음, 운영은 수동 업로드). 1차 응답 = version·php·disk · health(ERP 미러 소스별 마지막 성공) · anomalies(미러 13h 없음·미러 실패·디스크 90%). ⚠️ ssancar 지적: nonce 가 서명 대상 밖이라 ±300초 안 nonce 만 바꾼 재전송이 가능(HTTPS·읽기 전용 GET — 바꿀지는 jin 판단, 바꾸면 3곳 동시).
- 루틴 표에 「마지막 성공」 열 · 「N시간 넘게 running」 규칙(기록이 쌓인 뒤 결정).
- 3단계 세컨드 브레인.
