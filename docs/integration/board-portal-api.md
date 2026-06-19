# board 영업 포털 ↔ car-erp 연동 — 권위 스펙 (읽기 API + 선적요청 + 서류)

> **권위 스펙(car-erp).** board는 이 파일을 **경로로 읽고** 자기 client(`CarErpReadService`)를 맞춰 구현한다. board repo의 SKILLS/CLAUDE엔 **포인터 1줄만**(복사 금지 — drift). 연동 B(`purchase-sync-receiver.md`)와 동일 상호링크 규칙.
> **방향**: 영업은 board만 씀(car-erp 계정 없음). board → car-erp **HMAC GET = 읽기**(purchase-sync POST의 역방향) + 유일 쓰기 = 선적요청. car-erp 권위·계산, board 표시만(재무로직 재현 금지 = drift 방지).
> 상태: **설계 확정 / 미구현**. 회의록 = `docs/meetings/2026-06-18-board-portal-api.md`(6부서+Codex/Gemini, 조건부 GO·보안 선행조건). 인계 출처 = `board/meetings/handoff-car-erp-board-portal.md`.
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
| `GET /settlements` | 정산 — `status`·`actual_payout`·확정일 | **마진 raw 제외**. `$s->settlement_amount` accessor 경유(환차·이월 분기) |
| `GET /by-buyer` | **바이어별 묶음** — `vehicle_count`·`sales_by_currency`(통화별 판매금액)·`payout_total_krw`(정산 실지급액 합="나에게 준 이득")·`payout_paid_krw`(paid 확정만) | 바이어=**판매측**(`buyer_id`). **매입은 구입처 기준이라 바이어 무관 → 미포함**. payout=`actual_payout` accessor 합(환차·이월). 마진 raw 제외 |

- **환율0 외화**: `sale_unpaid_amount_krw_cache`가 `NULL`이면 그대로 `null` 반환 + `currency`·`exchange_rate` 동봉. board는 `null`을 "환율 미입력"으로 표시(절대 `0`/완납 coerce 금지).
- N+1 방지: `with(['finalPayments','purchaseBalancePayments','receivableHistories'])`.

## 5. ③ 선적요청 (읽기 + 가벼운 쓰기)
- **`GET /shippable?salesman_email=`** — 선적 가능 차 + 바이어 + 컨사이니 목록.
  - 대상(확정): `progress_status_cache = '판매완료'` **AND** `sales_channel='export'`. **⚠️ 요청해도 목록서 안 빠짐** — 사라짐 = 관리가 선적/통관 진행해 progress 가 '판매완료' 벗어날 때(자연소멸). board 가 요청한 차를 계속 보고 재요청 가능.
  - item 에 **`shipping_status`**(`none`/`requested`/`in_progress`) + `requested_method`·`requested_consignee_id`(재요청 prefill·뱃지). board 가 "요청됨/진행중" 뱃지.
  - 컨사이니(열린항목1 확정): **기존 컨사이니 선택만**(Buyer HasMany Consignee). 신규 입력은 v2(PII 신규생성·중복 검증 이슈).
- **`POST /shipping-request`** (HMAC) — payload:
  ```json
  { "vehicle_ids":[...], "buyer_id":N, "consignee_id":N, "shipping_method":"RORO|CONTAINER", "salesman_email":"...", "requested_at":"..." }
  ```
  - 적재 = **신규 `shipping_requests` 테이블**(`batch_id`(1 POST=1 uuid, car-erp 내부 묶음표시용)·`vehicle_id` FK·`buyer_id`·`consignee_id`·`shipping_method` enum·`requested_by_email`·`status` enum(requested/in_progress/done)·`requested_at`·`processed_at`·`note`). **⚠️ `vehicles` 컬럼(특히 `export_buyer_id`)에 적재 금지** — C4/C5 게이트(`guardStageOrderForExport`)·`ManagementWorkflowChecklistTest:375` 회귀.
  - car-erp 후단: 수출통관/관리가 **「통관·선적 > 선적요청」 화면**(`erp.shipping-requests.index`)에서 배치별로 보고 `requested→in_progress→done` 전환. done 시 연동 `shipping_requested` 알람 자동 resolve.
  - **취소 = car-erp 측 처리**(board 취소 엔드포인트 없음). 통관/관리가 화면에서 배치 취소 → `status='cancelled'`(open 집계 제외 → `/shippable` shipping_status 가 다시 `none` → 영업이 board 에서 재요청 가능) + 연동 알람 resolve. done 은 취소 불가.
  - **재요청 = 제자리 갱신**: open `'requested'` 있으면 새 row 안 만들고 **기존 row 의 consignee/method 갱신**(batch_id·status 유지 = 배치 정합). `'in_progress'`(관리 처리중)면 갱신 불가 skip. 응답 `{created:[], updated:[], skipped:[]}` 구분.
  - 알람 = **`TaskAlarm` 신규 type `shipping_requested`**(`target_role='관리'`) **즉시 생성·발동**(scan 불필요, ETA `eta_clearance`와 별개). 관리가 차량 편집 패널에서 실무(컨테이너#·B/L·선적일·서류) 채움.
  - board 표시 = 요청 상태(requested/in_progress/done)만. 권위 = `progress_status_cache`(관리가 진행).

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
| 2 | 선적 가능 차 상태경계 | **`판매완료` + export + open요청 없음** |
| 3 | 알람 매핑 | `TaskAlarm` 신규 type `shipping_requested`(관리) 즉시발동 |
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

## 9. 흡수 금지
- board가 `vehicles`/정산/회계 컬럼 **쓰기**(읽기 + 선적요청 지시만).
- 마진 raw·RRN·계좌 노출.
- 선적요청을 vehicles 상태 컬럼에 적재(게이트 회귀).
