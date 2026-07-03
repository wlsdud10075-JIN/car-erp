# board 영업 포털 ↔ car-erp 연동 — 권위 스펙 (읽기 API + 선적요청 + 서류)

> **권위 스펙(car-erp).** board는 이 파일을 **경로로 읽고** 자기 client(`CarErpReadService`)를 맞춰 구현한다. board repo의 SKILLS/CLAUDE엔 **포인터 1줄만**(복사 금지 — drift). 연동 B(`purchase-sync-receiver.md`)와 동일 상호링크 규칙.
> **방향**: 영업은 board만 씀(car-erp 계정 없음). board → car-erp **HMAC GET = 읽기**(purchase-sync POST의 역방향) + 유일 쓰기 = 선적요청. car-erp 권위·계산, board 표시만(재무로직 재현 금지 = drift 방지).
> 상태: **base(§4 재무읽기·§5 단발 선적요청·§6 서류) = ✅배포완료**(deploy #11, 2026-06-19 / v3 `ad1c8b3`). **§5 v2 묶음 모델(영속 묶음·선언형 sync·B/L요청·오리지널/써랜더 이중가드·묶음 미수 집계) = 신규 미구현**(2026-06-30 jin 설계 확정). 회의록 = `docs/meetings/2026-06-18-board-portal-api.md`(6부서+Codex/Gemini, 조건부 GO·보안 선행조건). 인계 출처 = `board/meetings/handoff-car-erp-board-portal.md`.
> ⚠️ **구현 순서**: ① 이 스펙 커밋 → ② car-erp 미들웨어/API/테이블 구현 → ③ board가 이 스펙대로 client 구현. **보안 선행조건(아래 §1~§3) 충족 전 라우트 활성화 금지.**

## 0. 빌드 순서
**④ 재무 읽기 API → ③ 선적요청 → ①② 서류 다운로드.** (읽기 무결성 우선)

---

## 1. 인증 — HMAC GET (신규 미들웨어 `VerifyBoardReadHmac`)
> 기존 `VerifyPurchaseSyncHmac`은 `$request->getContent()`(raw body) 서명이라 **GET 빈바디면 무력** → 재사용 불가, 신규 미들웨어 필수.

- **시크릿**: 별도 `CAR_ERP_READ_HMAC_SECRET`(쓰기 `CAR_ERP_HMAC_SECRET`와 분리). 미설정 시 401(silent no-op 금지).
- **서명 대상(canonical string)**:
  ```
  METHOD + "\n" + PATH + "?" + SORTED_QUERY + "\n" + X-Timestamp + "\n" + BODY
  ```
  (GET은 BODY="" / POST는 raw body. SORTED_QUERY = 쿼리 키 ksort 후 PHP `http_build_query()`로 직렬화 — **값·키 URL 인코딩됨**(공백→`+`, RFC1738 기본). 단순 `k=v&...` raw 결합 아님 — 양쪽이 바이트 단위 일치해야 함. 구현 출처 = `app/Http/Middleware/VerifyBoardReadHmac.php:49-51`. **`salesman_email`은 쿼리에 포함 = 서명 대상** → 위조 차단.)
- **헤더**: `X-Board-Signature: sha256=<hex>` · `X-Timestamp: <unix epoch sec>` · `X-Nonce: <uuid>`
- **replay 방지**: `|now - X-Timestamp| ≤ 300초` + `X-Nonce` 캐시(5분 TTL, 재사용 거부).
- 비교 = `hash_equals`(timing-safe). 로그엔 IP만(서명·시크릿 평문 금지).

## 2. IDOR 본인격리 (최중요) — `InternalSalesmanScope` 단일출처
> board 유저는 car-erp auth 세션 없음 → `User::canScopeVehicle`(auth 기반) 직접 불가. 전용 스코프 헬퍼로 강제.

- `salesman_email`(쿼리, 서명 포함) → `Salesman::where('email', $email)` 매칭.
- **재직/active 검증**: 매칭된 Salesman이 비활성/퇴사면 **403**(데이터 0). (email은 가변 — 향후 불변 식별자 v2 검토.)
- 모든 쿼리에 `where('salesman_id', $salesman->id)` 강제. board가 임의 email 주입해도 그 영업 것만(서명+매핑 이중).
- 매칭 실패 = 403(404로 salesman 존재 여부 노출 금지).

## 3. PII·응답 화이트리스트 (절대 노출 금지)
- ⛔ `nice_reg_owner_rrn`(RRN)·`nice_reg_owner_name/addr`·`purchase_seller_account`(계좌)·`purchase_seller_holder` — **어떤 응답에도 미포함**.
- ⛔ **마진 raw**(`sales_margin`·`vat_margin`·`total_margin`) — **기본 미포함**. (기능설정 `board_show_margin` 토글 = v2, default off.)
- ✅ 허용: `vehicle_number`·금액류(원화/외화)·`currency`·`exchange_rate`·바이어명·진행상태·일자·정산 status·`actual_payout`(실지급액).
- 에러 응답 = 표준 JSON `{ "error": "...", "message": "..." }`. 스택·DB구조·Laravel 내부 노출 금지.

---

## 4. ④ 재무 읽기 API (읽기전용, accessor/cache 그대로 — raw SQL 재계산 금지=drift)
prefix `/api/internal/board`, 미들웨어 `[VerifyBoardReadHmac, throttle:300,1 by(salesman_email)]`.

| 메서드·경로 | 반환 | 비고 |
|---|---|---|
| `GET /finance` | 영업 본인 요약(미수금 합·매입미지급 합·정산 대기 건수) | 통화별+KRW |
| `GET /receivables` | 차량별 미수금 — `sale_unpaid_amount_krw_cache`·`currency`·`exchange_rate`·바이어 | **NULL=환율 미입력**(완납 아님) |
| `GET /purchases` | 매입 차 — `purchase_price`·비용9 합·매입일·매입 미지급(`PurchaseBalancePayment`) | |
| `GET /sales` | 판매 차 — `sale_price`·`currency`·바이어 | |
| `GET /settlements` | 정산 — `status`·`actual_payout`·`confirmed_at`·**`paid_at`(실제 지급일)** | **마진 raw 제외**. `$s->settlement_amount` accessor 경유(환차·이월 분기). board 는 **`paid_at` 月 기준으로 정산 묶음**(예: 4월 일한 분 = 5/10 지급 → 5월). 일괄적재 과거분은 CK 배치로 paid_at 백데이트(`settlements:backdate-from-ck`), 이후 신규는 paid 전환 시점 자동 기록 |
| `GET /by-buyer` | **바이어별 묶음** — `vehicle_count`·`sales_by_currency`(통화별 판매금액)·`payout_total_krw`(정산 실지급액 합="나에게 준 이득")·`payout_paid_krw`(paid 확정만) | 바이어=**판매측**(`buyer_id`). **매입은 구입처 기준이라 바이어 무관 → 미포함**. payout=`actual_payout` accessor 합(환차·이월). 마진 raw 제외 |
| `GET /buyers` | **드로어 드롭다운** — `{id, name, country}` | **영업 본인 바이어만**(`buyers.salesman_id`=해소 영업) + `is_active`. 연락처·주소·메모 등 PII 금지 |
| `GET /consignees?buyer_id=` | **드로어 드롭다운** — `{id, name}` | 해당 buyer 하위 `is_active` 컨사이니. **IDOR — buyer_id 가 본인 소유일 때만**(아니면 빈 목록) |

- **연동 B v3 드롭다운**(2026-06-23): board 경매/구매 드로어가 바이어·컨사이니를 car-erp 목록에서 선택(→ purchase-sync v3 `buyer_id`/`consignee_id` 송신). ⚠️ **Jin 결정 = 영업 본인 스코프**(인계문서의 "전체 활성 허용" 권장과 다름). `buyers` 가 비스코프였다면 IDOR 불변식 깨는 첫 사례라 거부 — 본인 바이어만. board 는 신차에 본인 바이어만 지정 가능(타 영업 바이어 필요 시 car-erp 에서 수동). 미구현 시 board graceful degrade(수동 입력).
- **환율0 외화**: `sale_unpaid_amount_krw_cache`가 `NULL`이면 그대로 `null` 반환 + `currency`·`exchange_rate` 동봉. board는 `null`을 "환율 미입력"으로 표시(절대 `0`/완납 coerce 금지).
- N+1 방지: `with(['finalPayments','purchaseBalancePayments','receivableHistories'])`.

### 4-1. 환율 read (`GET /rates`) — board 가 car-erp 값 받아쓰기 (2026-07-03)

> 인계 = board `meetings/handoff-car-erp-exchange-rate.md`. 결정 B: board 가 독자 스크래핑(Frankfurter/ECB) 대신 **car-erp 값을 그대로 받음** — 같은 소스를 각자 긁으면 시점차로 어긋나므로 단일 소스(car-erp)로 통일해야 100% 일치.

| 메서드·경로 | 반환 | 비고 |
|---|---|---|
| `GET /rates` | `{rates:{USD,JPY,EUR,GBP,CNY}, fetched_at, source}` | ⚠️ **스코프 없음**(환율은 전역값, `salesman_email` 불필요). HMAC 인증만. |

- `rates.{CUR}` = **car-erp 가 실제 계산·저장에 쓰는 네이버 전신환 매입률(송금받을때) 원본 그대로** (`ExchangeRateService::getRates`). ⚠️ **반올림 금지** — 정수화하면 board 값과 어긋나 통일 목적이 무너짐(소수 그대로). JPY 는 **100엔 기준**(car-erp 관례). 조회 실패 통화는 키 생략 → board 는 없는 통화는 자체 폴백(마지막 캐시→config) 유지.
- `fetched_at` = car-erp 가 마지막으로 네이버에서 긁은 시각(`Y-m-d H:i`, 신선도 표시용, null 가능). `source` = `naver_전신환매입률`.
- car-erp 는 이미 이 환율을 1h 캐시로 저장/조회 중 → **그 값을 노출만** 함(새 스크래핑 없음, 부하 무시). board 는 lazy `refreshIfStale`(1h)로 호출 → car-erp 부하 1시간 1회.
- **배포 순서**: car-erp `/rates` 먼저 배포 → board 소스 전환. (엔드포인트 없으면 board 는 폴백으로 도니 안전.)

## 5. ③ 선적·B/L 묶음 (bundle) — 영속 그룹 + 선언형 sync + 재무 집계
> **v2 묶음 모델 (2026-06-30, jin 4턴 설계).** 구 단발 선적요청(1 POST=1 batch, 판매완료서 자연소멸, car-erp만 취소)을 **영속 묶음**으로 확장. 핵심 통찰 = **1 묶음 = 1 선적 = 1 B/L = 1 오리지널/써랜더.** 묶음은 선적단계→B/L단계까지 살아있고 board에서 안 사라짐(같은 묶음을 B/L요청으로 재사용). 회의록 = `docs/meetings/2026-06-18-board-portal-api.md` + `docs/meetings/2026-06-30-bl-shipment-bundle-v2.md`(풀회의 조건부 GO) + 본 절.
>
> **🔑 jin 결정 반영 (2026-06-30 회의 후)**:
> 1. **알람 target_role 분리** — 선적요청=`수출통관`(현행 유지) / **B/L요청·변경요청=`관리`**. ⚠️ 현재 [관리]가 실무를 다 겸하므로 **`관리`가 두 종류 알람을 모두 볼 수 있어야 함**(`TaskAlarm::visibleToScope`·`User::canSeeAlarm`에서 관리가 수출통관 타겟 알람도 보이는지 확인 — 관리∈clearance이므로 통상 가시).
> 2. **v1 → v2 한 번에 교체(하위호환 불필요)** — board 포털 base가 deploy #11로 **배포는 됐으나 board가 실제로 미가동**(실트래픽·실데이터 0, jin 확인). 따라서 구 `POST /shipping-request`(단발)은 병존 없이 **`/sync`로 교체/제거**, `/shippable` 의미축소도 자유 적용(board 의존 없음). Codex의 "병존" 권고는 board 라이브 전제였으므로 기각.
> 3. (파생) `InternalPortalController::finance() L199 ?? 0` 버그는 board 미가동이라 **현재 실사용자 오표시는 없음** → "긴급"에서 "**board 가동 전 수정 필수**"로 격하(여전히 묶음 집계 전 수정).

### 5-0. 묶음 = 얇은 그룹 레이어 (⚠️ 새 테이블 없음)
- 저장 = **기존 `shipping_requests` 행(멤버십, vehicle 단위) + `batch_id`(영속 식별자)**. `shipping_requests`는 **하드삭제 안 함**(cancel=`status='cancelled'`, 끝=`done`) → 묶음은 항상 살아있음.
- **B/L 실데이터(`bl_document`·`bl_number`·`vessel`…)는 `vehicles`에 저장** — 진행상태 cascade(`bl_document → 거래완료`)가 **per-vehicle**이라 다른 집은 불가(drift). 묶음은 `batch_id` + `bl_type`(영업 요청값) + `bl_status` 플래그만 갖는 **그룹/의도 레이어**.
- **컬럼 추가 (마이그 2개 — 2026-06-30 회의 확정)**: ① `shipping_requests`에 `bl_type`(`original`/`surrender`, nullable)·`bl_status`(`none`/`requested`/`issued`, default none)·`change_requested_at`(nullable)·`change_request_meta`(json) ② **`vehicles.bl_type`**(nullable — 이중가드가 `bundle.bl_type` vs `vehicle.bl_type` 비교할 컬럼. 없으면 silent null 비교). 둘 다 nullable/default → MySQL 8 INSTANT DDL 무중단(ssancarerp 0초). `ShippingRequest` 상수(`BL_TYPES`/`BL_STATUS`)·`$fillable`·`$casts`(json) 추가 필수(누락 시 4엔드포인트 500). 기존 `status`(requested/in_progress/done/cancelled)=선적단계.
- **⚠️ `vehicles` 컬럼(특히 `export_buyer_id`)에 적재 금지** — C4/C5 게이트(`guardStageOrderForExport`)·`ManagementWorkflowChecklistTest:375` 회귀.

### 5-1. 읽기 — `GET /shippable` (새로 묶을 차 후보) + `GET /bundles` (영속 묶음)
- **`GET /shippable?salesman_email=`** — **새로 묶을 차 후보만.** `progress_status_cache='판매완료'` **AND** `sales_channel='export'` **AND** 아직 어느 open 묶음에도 없음. + 바이어·컨사이니(기존 선택만, 신규입력 v2).
- **`GET /bundles?salesman_email=`** — **영업 본인 묶음 전체(전 상태, 안 사라짐).** 묶음별:
  - `batch_id`·`shipping_method`·`bl_type`·**`ship_status`**(선적단계 — ⚠️ 키 이름은 `ship_status`, 스펙 초기 텍스트의 `status` 아님. 권위=구현)·`bl_status`·`vehicles[]`(번호·차별 status).
  - **⚠️ `buyer`/`consignee` = `{id, name}` 객체** (이름 문자열 아님 — board 가 sync 재전송 시 `buyer_id` 필요. 문자열만이면 묶음 누락→자동취소 footgun. 2026-06-30 board e2e 차단이슈) + **`consignees`=`[{id,name}]`**(그 바이어 컨사이니 옵션, 편집용). buyer 없으면 `null`·`[]`.
  - **재무 집계**(아래 5-4): `sales_by_currency`·`unpaid_total_krw`·`fx_missing_count`·`fully_paid`·`unpaid_ratio`·`surrender_unpaid_warning`.
  - `change_requested`(in_progress 변경요청 대기 여부).
  - → board "내 선적묶음" 영속 뷰 + 미수 게이지. *(이게 "묶음이 화면에서 안 사라짐"의 구현)*

### 5-2. 쓰기 — 선언형 sync + B/L요청 + 변경요청 (모두 HMAC, 본인 차만)
- **`POST /shipping-requests/sync`** — 영업의 **"지금 원하는 묶음 전체(desired state)"** 전송 → car-erp가 현재 open 행과 diff.
  ```json
  { "salesman_email":"...",
    "bundles":[
      { "buyer_id":N, "consignee_id":N, "shipping_method":"RORO|CONTAINER", "bl_type":"original|surrender|null", "vehicle_ids":[A,B] }
    ] }
  ```
  - diff(트랜잭션): desired에 있고 open 없음→**생성** / `requested`이고 attrs 변경→**갱신**(bundle 이동 시 batch 재배치) / desired에 없고 `requested`→**자동취소**(+알람 resolve) / `in_progress`→**잠금**(desired 유무로 자동변경·자동취소 안 함).
  - 응답 `{created:[], updated:[], cancelled:[], skipped:[], locked:[]}`.
  - **⚠️ board 측 강제**: payload는 **반드시 영업 전체 desired 묶음**. 일부만 보내면 빠진 `requested` 차가 **의도치 않게 자동취소**됨 → board는 `/bundles`로 전체를 그려놓고 영업이 빼/옮긴 것만 반영해 통째 전송.
- **`POST /bundles/{batch}/bl-request`** — 기존 묶음의 **B/L요청 재사용**. `{ salesman_email, bl_type:"original|surrender" }` → `bl_type` 확정 + `bl_status='requested'` + 관리 알람. (선적요청을 베낀 별도 시스템 아님 = 같은 묶음의 상태 전이.)
- **`POST /bundles/{batch}/bl-cancel`** — **B/L요청 무름**(영업 오발송 정정, 2026-06-30 board 요청). `{ salesman_email }` → `bl_status='requested'→'none'`(`bl_type`은 유지=재요청 prefill) + 관리 `bl_requested` 알람 resolve. **이미 발급(`issued`)됐으면 `409 already_issued`**(관리가 발급함 → 무름 불가, 관리에게 문의). IDOR — batch 의 모든 행이 본인 차.
- **`POST /shipping-requests/change-request`** — `in_progress`(관리 착수) 차의 **명시적** 변경/취소 요청. `{ vehicle_id, salesman_email, note }` → `change_requested_at`·`change_request_meta` 기록 + 관리 알람. **자동적용 안 함** — 관리가 화면에서 수락(취소/재오픈)/거절. (omission으로 cancel-request 추론 절대 금지.)

### 5-3. car-erp 후단 — 「선적·B/L 묶음」 화면 (구 「선적요청」 확장)
- 라우트 `erp.shipping-requests.index` 확장. 선적단계(requested→in_progress→done) + **B/L단계**(bl_status) 같이 표시. done/취소/B/L요청·변경요청 수락거절·자동취소 반영.
- 묶음별 **미수 게이지(`unpaid_ratio`)** + 완납뱃지 + **환율 미입력 N대 경고** → 관리가 "이 묶음 B/L 발급 가능?" 한눈에.
- **「B/L 발급」 bulk-apply**: bl_status='requested' 묶음에서 관리가 1회 클릭 → 공유 B/L 필드(`bl_number`·`bl_type`·`container_number`·`vessel_name`·`bl_loading_location`)를 **멤버 차량 전체에 트랜잭션 일괄 기입** → bl_status='issued'. (B/L 문서 업로드는 차량별, 이중가드 적용.)
- **이중가드 (B/L 문서 업로드 전)**: `bundle.bl_type`(영업 요청) vs `vehicle.bl_type`(관리가 업로드 전 선택) **비교** — 불일치 시 경고. 가드는 **신규 B/L 문서 set 시에만** 강제(blDocFile 있거나 bl_document 빈→채움), **기존 B/L 보유 차(grandfather) 제외**(G1 박스 `if(! $g1HasExistingBl)` 패턴).
- **써랜더 × 미완납 = 경고만**(저장 허용). 최종 차단은 기존 **G1 100% B/L 게이트**(`unpaid_export_overrides` stage='bl').
- 알람 (jin 결정 분리): **선적요청 = `TaskAlarm` type `shipping_requested`·`target_role='수출통관'`**(현행 `fireShippingAlarm()` 유지) / **B/L요청·변경요청 = `target_role='관리'`**(신규 type). 즉시발동, done·취소 시 resolve. 관리가 실무 겸업이라 두 알람 모두 가시여야 함.

### 5-4. 묶음 재무 집계 (⚠️ 단일출처 SKILLS §13 — accessor만, raw SQL 재계산 금지)
> 구현 = **기존 `InternalPortalController`(`/finance`·`/by-buyer`) 집계 패턴 재사용**. 새 저장·새 accessor 없음.
```
unpaid_total_krw  = Σ sale_unpaid_amount_krw_cache (멤버, NULL 제외)
sales_by_currency = 통화별 Σ sale_price
fx_missing_count  = count(sale_unpaid_amount_krw_cache === null)        // 환율 미입력 차
unpaid_ratio      = Σ unpaid_krw / Σ(sale_total_amount × exchange_rate)  // fx-missing 양쪽 제외 → 게이지 fill
fully_paid        = (unpaid_total_krw <= 0) AND (fx_missing_count === 0)
```
- **⚠️ NULL(환율 미입력)을 0으로 합치지 말 것** — 가짜 "완납" → 가짜 B/L 발급 가능(cash_audit 교훈). 환율 미입력 1대라도 있으면 `fully_paid=false` + "환율 미입력 N대" 경고. **집계는 `whereNotNull('sale_unpaid_amount_krw_cache')` 또는 `filter(fn=>$v!==null)` 명시.**
- **⚠️ 기존 버그 동반 수정 (2026-06-30 회의 발견)**: `InternalPortalController::finance()` (≈L199)가 `$v->sale_unpaid_amount_krw_cache ?? 0`로 **NULL을 0(완납) coerce** — board 재무 미러가 **지금도 미수금을 낮게 오표시 중**(deployed). 묶음 집계 재사용 전 이 `?? 0` 제거(긴급). 묶음 집계 코드는 절대 이 패턴 답습 금지.
- **UI**: 묶음 미수 = **기존 미납 게이지 패턴(`unpaid_ratio`)** 재사용 + 보기 좋은 카드. **board·car-erp 양쪽 표시**. ⚠️ `fully_paid`·`써랜더×미완납 warning`은 **car-erp가 계산해서 내려보냄**(Codex+Spec-F 수렴 — board가 raw값으로 재계산하면 drift=운영장애). board는 **표시/경고만**, 절대 완납판정 재현 금지.
- 화이트리스트(§3): 미수금·통화·환율 **허용** / 마진 raw(`sales/vat/total_margin`) **금지**.

## 6. ①② 서류 다운로드 (프록시 스트림 — 선적 4종만)
- **`GET /documents/{type}?ids=1,2,3&salesman_email=`** — car-erp가 `DocumentFiller`로 동적 생성 → xlsx 바이트 스트림 반환(프록시). board가 그대로 전달.
- **type 화이트리스트 `BOARD_ALLOWED_TYPES`(필수)**: `roro_invoice_packing`·`roro_contract`·`container_invoice_packing`·`container_contract` **4종만**. 그 외(`deregistration`·`deregistration_contract`·`poa`·`invoice`·`clearance`) **403** — ⛔ 말소서류엔 RRN·성명·주소 포함(§29 국외이전 차단).
- 차량 스코프 = `InternalSalesmanScope` 재적용(영업 본인 차만). throttle 별도(서류 생성 = PhpSpreadsheet CPU).
- 감사 = `DocumentAccessLog` 기록 + **신규 컬럼 `source='board_api'`·`actor_email=salesman_email`**(`user_id`는 null).

---

## 7. 열린 항목 확정값
| # | 항목 | 확정 |
|---|---|---|
| 1 | 선적요청 컨사이니 | **기존 선택만**(신규 입력 v2) |
| 2 | 선적 가능 차 상태경계 | **`판매완료` + export + open묶음 없음**(`/shippable`=새로 묶을 차만 / 기존 묶음=`/bundles` 영속) |
| 3 | 알람 매핑 (jin 2026-06-30) | 선적요청=`shipping_requested`·`target_role='수출통관'`(현행) / B/L요청·변경요청=`target_role='관리'`. 관리 겸업이라 둘 다 가시 |
| 7 | 재구성·취소 (v2) | **선언형 sync** — `requested`=board sync로 자동취소·재구성 / `in_progress`=잠금, board "변경요청"→관리 수락거절 / car-erp 관리 취소도 유지 (양방향). 구 "board 취소 엔드포인트 없음" 폐기 |
| 8 | 묶음 영속·B/L 재사용 (v2) | 새 테이블 X. `batch_id` 영속 그룹 + `bl_status` 플래그. B/L실데이터는 vehicles(cascade per-vehicle). board는 `/bundles`로 전상태 조회 |
| 9 | 묶음 미수 총액 (v2) | `unpaid_total_krw`=Σ`sale_unpaid_amount_krw_cache`(NULL제외)+`fx_missing_count`. 미납 게이지 패턴, board·car-erp 양쪽 표시. 마진 raw 금지 |
| 4 | 운임비 매핑 | 판매배송(바이어向)=`transport_fee` / 매입배송(지급게이트웨이)=`cost_towing` **분리**. board 선적요청은 transport_fee 미접촉(관리가 입금 전 확정) |
| 5 | 서류 인증 | **프록시 스트림** |
| 6 | HMAC 시크릿 | **별도 `CAR_ERP_READ_HMAC_SECRET`** |

## 8. board 측 작업 (board repo — 참고)
- `config/services.car_erp`: `base_url`(=`https://heysellcar.com`) + `CAR_ERP_READ_HMAC_SECRET`. `CarErpReadService`(HMAC GET, **미설정 시 no-op 안전밸브**).
- HMAC 서명 = §1 canonical string과 **바이트 단위 일치**(METHOD·PATH·sorted query·timestamp·body·X-Nonce).
- 영업 화면: 재무 미러(④) → 선적요청(③) → 서류(①②). 전부 `car_erp_salesman_email ?: email` 스코프.
- **degrade**: car-erp 401/5xx·미설정 → "**조회 불가**" 표시(절대 `0원`/`완납` coerce 금지).
- 서류는 **선적 4종만** 요청(그 외 car-erp 403).
- 마진 raw 안 받음(미수금·정산상태·실지급액만).
- car-erp 응답 board측 캐싱(30~60초) 여부 = board 결정(throttle 완화).

### 8-1. v2 선적·B/L 묶음 board 작업 (handoff — board 세션에서 구현·커밋)
> car-erp가 §5 권위로 먼저 구현·배포 → board는 본 절 읽고 client 구현. **board 변경은 board repo/세션 커밋**(복사 금지=drift). 구 단발 선적요청 UI는 **병존 없이 교체**(board 미가동이라 안전).
1. **「내 선적묶음」 영속 뷰** — `GET /bundles` 폴링. 카드: 차목록·`status`(선적단계)/`bl_status`·`bl_type` + **미수 게이지(`unpaid_ratio`)**·`fully_paid` 완납뱃지·`fx_missing_count` "환율 미입력 N대" 경고. **car-erp 값 그대로**(재계산·0/완납 coerce 금지).
2. **선적 계획(재구성) 뷰** — `/shippable`(새로 묶을 차) + `/bundles`(기존) → 체크/이동/빼기 → **「동기화」 = `POST /shipping-requests/sync`로 전체 desired 전송**(⚠️ 부분=자동취소). 응답 `cancelled[]`/`locked[]` → "취소 N·처리중 N" 토스트. `in_progress`는 취소/이동 비활성 + "변경요청" 버튼만.
3. **오리지널/써랜더 선택기** — sync bundle별 `bl_type`(선택값, 미정 생략).
4. **B/L요청** — `POST /bundles/{batch}/bl-request`(`bl_type` 확정) + **무름** `POST /bundles/{batch}/bl-cancel`(`bl_status='requested'`일 때만, `409 already_issued`면 "관리 발급완료" 표시). **변경요청** — `POST /shipping-requests/change-request`(`vehicle_id`+note).
5. **HMAC** — 4신규도 §1 canonical 바이트 일치. `CarErpReadService` 재사용. 401/5xx/미설정 → "조회 불가" degrade.

## 9. 흡수 금지
- board가 `vehicles`/정산/회계 컬럼 **쓰기**(읽기 + 선적요청 지시만).
- 마진 raw·RRN·계좌 노출.
- 선적요청을 vehicles 상태 컬럼에 적재(게이트 회귀).
