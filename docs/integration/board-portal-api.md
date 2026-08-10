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
- ✅ 허용: `vehicle_number`·금액류(원화/외화)·`currency`·`exchange_rate`·바이어명·진행상태·일자·정산 status·`actual_payout`(실지급액)
  ·**`vin`(`nice_reg_vin`)·`brand`·`model_type`** (2026-08-10 판단 — §4-2).
- 에러 응답 = 표준 JSON `{ "error": "...", "message": "..." }`. 스택·DB구조·Laravel 내부 노출 금지.

---

## 4. ④ 재무 읽기 API (읽기전용, accessor/cache 그대로 — raw SQL 재계산 금지=drift)
prefix `/api/internal/board`, 미들웨어 `[VerifyBoardReadHmac, throttle:300,1 by(salesman_email)]`.

| 메서드·경로 | 반환 | 비고 |
|---|---|---|
| `GET /finance` | 영업 본인 요약(미수금 합·매입미지급 합·정산 대기 건수) | 통화별+KRW |
| `GET /receivables` | 차량별 미수금 — `sale_total`·`unpaid_krw`(`sale_unpaid_amount_krw_cache`)·`unpaid_ratio`·`currency`·`exchange_rate`·바이어 | **`unpaid_krw` NULL=환율 미입력**(완납 아님). **`unpaid_ratio`**(0~1|`0.0`완납|`null`판매가 미입력) = `Vehicle::unpaid_ratio` accessor 그대로, 통화 비의존(환율 무관). board 미납률 게이지용 |
| `GET /purchases` | 매입 차 — `purchase_price`·비용9 합·매입일·매입 미지급(`PurchaseBalancePayment`) | |
| `GET /sales` | 판매 차 — `sale_price`·`currency`·바이어 | |
| `GET /settlements` | 정산 — `status`·`actual_payout`·`confirmed_at`·**`paid_at`(실제 지급일)** | **마진 raw 제외**. `$s->settlement_amount` accessor 경유(환차·이월 분기). board 는 **`paid_at` 月 기준으로 정산 묶음**(예: 4월 일한 분 = 5/10 지급 → 5월). 일괄적재 과거분은 CK 배치로 paid_at 백데이트(`settlements:backdate-from-ck`), 이후 신규는 paid 전환 시점 자동 기록 |
| `GET /by-buyer` | **바이어별 묶음** — `vehicle_count`·`sales_by_currency`(통화별 판매금액)·`payout_total_krw`(정산 실지급액 합="나에게 준 이득")·`payout_paid_krw`(paid 확정만) | 바이어=**판매측**(`buyer_id`). **매입은 구입처 기준이라 바이어 무관 → 미포함**. payout=`actual_payout` accessor 합(환차·이월). 마진 raw 제외 |
| `GET /buyers` | **드로어 드롭다운** — `{id, name, country, purchase_locked, purchase_lock{…}}` | **영업 본인 바이어만**(`buyers.salesman_id`=해소 영업) + `is_active`. 연락처·주소·메모 등 PII 금지. 매입 락 = **§4-0** |
| `GET /consignees?buyer_id=` | **드로어 드롭다운** — `{id, name}` | 해당 buyer 하위 `is_active` 컨사이니. **IDOR — buyer_id 가 본인 소유일 때만**(아니면 빈 목록) |

- **연동 B v3 드롭다운**(2026-06-23): board 경매/구매 드로어가 바이어·컨사이니를 car-erp 목록에서 선택(→ purchase-sync v3 `buyer_id`/`consignee_id` 송신). ⚠️ **Jin 결정 = 영업 본인 스코프**(인계문서의 "전체 활성 허용" 권장과 다름). `buyers` 가 비스코프였다면 IDOR 불변식 깨는 첫 사례라 거부 — 본인 바이어만. board 는 신차에 본인 바이어만 지정 가능(타 영업 바이어 필요 시 car-erp 에서 수동). 미구현 시 board graceful degrade(수동 입력).
- **환율0 외화**: `sale_unpaid_amount_krw_cache`가 `NULL`이면 그대로 `null` 반환 + `currency`·`exchange_rate` 동봉. board는 `null`을 "환율 미입력"으로 표시(절대 `0`/완납 coerce 금지).
- N+1 방지: `with(['finalPayments','purchaseBalancePayments','receivableHistories'])`.

### 4-0. 매입 등록 락 (`GET /buyers` 동봉, 2026-08-10)

> **왜 여기인가** — car-erp 의 매입 락 4겹은 전부 차량관리 화면 `save()` 안에 있다. board 가 밀어넣는
> 연동 B(`POST /internal/purchase-sync`)는 `Vehicle::create` 직접 호출이라 **어느 락도 안 거친다**.
> 그렇다고 수신 시점에 거부하면 안 된다 — board 는 이미 `status='won'`(낙찰 = 돈이 나간 뒤)에 보내므로,
> 거부하면 회사가 소유한 차가 ERP 에 없는 상태가 될 뿐이다. **막을 수 있는 유일한 지점은 상류**,
> 즉 영업이 바이어를 고르는 순간이다. 그래서 판정을 드롭다운에 실어 보낸다.

`GET /buyers` 의 각 행에 아래가 추가된다(기존 필드는 그대로 — 전방호환).

```json
{
  "id": 12, "name": "OSAKA MOTORS", "country": "Japan",
  "purchase_locked": true,
  "purchase_lock": {
    "locked": true,
    "mode": "unsecured",              // unsecured(금액 판정) | ratio(미수율 판정) | off(락 토글 OFF)
    "basis": {                        // ⭐ 락을 **실제로 결정하는** 값 — board 는 이것만 표시한다
      "kind": "unsecured_krw",        // unsecured_krw | ratio | null(근거 없음·토글 OFF)
      "current": 0,                   // 남은 무담보(원)  /  ratio 면 현재 미수율(%)
      "limit": 5000000                // 무담보 한도(원)  /  ratio 면 임계(%)
    },
    "reference": {                    // 참고 숫자 — 🚫 락 판정과 무관
      "unpaid_krw": 92000000,
      "vehicle_count": 3,             // 선적 전 진행중 대수
      "unpaid_ratio_pct": 92.0,
      "available_krw": 0,             // 보증금 여력 = 입금액×비율 + 무담보 − 매입 지급
      "unsecured_limit_krw": 5000000,
      "unsecured_available_krw": 0
    }
  }
}
```

- **판정 = ERP `App\Services\PurchaseRegistrationGate` 단일 출처.** 화면 저장 게이트와 **같은 함수**를 탄다.
  🚫 board 가 조건을 옮겨 적지 말 것 — 갈리는 순간 영업은 board 에서 "가능"을 보고 돈을 쓴 뒤 ERP 에서
  막힌다. board 는 `purchase_locked` 를 **그대로 신뢰**해서 표시·차단만 한다.
- 🚨 **`basis` 와 `reference` 를 나란히 렌더하지 말 것.** ratio 모드에서 `available_krw`(보증금 여력)는
  락 판정과 **분모·분자가 아예 다르다**. 그래서 *"여력 0원인데 등록 가능"* · *"락인데 여력 1천만"* 이
  **둘 다 정상**으로 나온다. 나란히 보여주면 영업이 반드시 오판한다 — 근거는 `basis` 하나뿐이다.
  (억지로 일치시키려 하면 매입 락이 망가진다. 그 어긋남은 `BoardPurchaseLockApiTest` 가 박아뒀다.)
- **`basis.current/limit` 은 숫자로만 다룰 것** — JSON 이 `20.0` 을 `20` 으로 직렬화하므로 정수/실수가 섞인다.
- **`mode`**: 바이어에 무담보 한도가 설정돼 있으면 `unsecured`(무담보 잔액 0 = 락), 아니면 `ratio`(미수율 > 임계 = 락).
  시스템관리자가 매입 락 토글을 끈 회사는 전부 `off` + `locked=false` + `basis.kind=null`.
- **판정 근거가 없는 바이어**(차량 0대 · 무담보 미설정)는 `locked=false` + `basis.kind=null`. 신규 바이어가 막히면 안 된다.
- **락은 절대 규칙이 아니다** — ERP 화면에서는 [관리]·최고관리자가 사유를 적고 **1회 통과**시킬 수 있다
  (다음 차는 또 발동, 지속 토큰 없음, `AuditLog(purchase_gate_override)`). board 에서 막혔다고 끝이 아니라
  **"ERP 관리자 승인이 필요하다"** 로 안내하는 게 맞다.
- **스코프**: 미수 금액이 실리므로 본인 바이어 스코프가 더 중요해졌다. 기존 `salesman_id` 스코프 그대로.
- 성능: 바이어당 쿼리를 돌리지 않는다(`Buyer::computeReceivableGaugesFor` — 차량·한도 각 1쿼리).
  ⚠️ 단 그 1쿼리는 **상태 필터가 없다** — 거래완료·출고분까지 끌어와 PHP 에서 버린다(게이지가
  `completed_*`·`shipped_*` 를 집계하기 때문). 즉 **단조증가**한다. 실측 2026-08-10 heymanerp =
  가장 바쁜 영업이 바이어 16명 · 차량 **239행**(나머지는 3행 이하)이라 지금은 문제없다.
  드로어가 느리다는 제보가 오면 **여기부터 볼 것**(같은 병으로 `purchases()` → `inventory()` 를
  2026-08-09 에 갈아엎었다). 좁힐 땐 API 경로만 — 화면(`buyers/index`)은 `completed_krw` 를 쓴다.
- 가드 = `tests/Feature/BoardPurchaseLockApiTest`(API 판정 ≡ 저장 게이트, 모드 분기, 토글 OFF, 판정식 복제 정적 검사).

### 4-2. 차량 메타 — 차대번호·브랜드/차종 (board 인계 2026-08-10)

> 요청(jin) = **"차량번호가 보이는 곳이면 차대번호와 브랜드/차종도 같이"**. board 는 표시만 한다 —
> 응답에 없으면 아무것도 못 그린다. 인계 = board `meetings/handoff-carerp-portal-vehicle-meta.md`.

차량 행을 내보내는 **모든** 응답에 아래 3키가 함께 나간다(기존 필드 불변 — 전방호환).

```json
"vin": "KMHXX00000000001",   // ERP nice_reg_vin
"brand": "현대",
"model_type": "그랜저 IG"
```

**적용 지점 (컨트롤러 2개 — ⚠️ 하나만 고치면 절반만 된다)**

| 컨트롤러 | 응답 | board 탭 |
|---|---|---|
| `InternalPortalController` | `receivables` | 미수금 |
| `InternalPortalController` | `sales` | 판매내역 |
| `InternalPortalController` | `inventory` (4분류 전부) | 재고 |
| `ShippingRequestController` | `shippable` | 선적요청(미배정 차) |
| `ShippingRequestController` | `bundles` → **`vehicles[]`** | 선적요청(묶음 pill·변경요청 행) |

- 🚫 **`settlements` 는 제외** — board 화면에 차량 행이 없고(바이어별 집계만 렌더) 인계문서도
  "넣어도 board 는 안 쓴다"고 명시했다. 안 쓰는 필드를 노출면에 올리지 않는다.
- **단일 출처 = `Vehicle::portalMeta(?Vehicle)`.** 각 지점이 배열을 손으로 짜지 않는다 —
  키가 갈리면 board 는 **에러도 없이 그냥 안 그린다**(없는 필드 = degrade). null-safe 라
  `bundles` 처럼 `$r->vehicle` 이 null 일 수 있는 자리에서도 그대로 쓴다.
- 값이 없으면 **`null`**(빈 문자열 아님). 각 필드 독립 degrade라 **car-erp 배포 전에 board 를 올려도
  화면이 안 틀어진다** — 그래도 올릴 이유는 없다(보일 게 없다). 순서 = **car-erp → board**.
- 🔒 **VIN 노출 판단(2026-08-10)** — 허용. VIN 은 **차량 식별자이지 소유자 식별정보가 아니고**
  (⛔ 목록 = RRN·소유자명/주소·계좌·마진) 암호화 대상도 아니다. 이 엔드포인트들은 전부 **영업 본인
  차량 스코프**라, 그 영업이 ERP 기본정보 탭·선적서류(이미 다운로드 가능)에서 보는 값과 같다 —
  노출면이 늘지 않는다. 🚫 `portalMeta` 에 소유자·계좌 필드를 얹지 말 것(그 순간 이 근거가 무너진다).
- 가드 = `tests/Feature/BoardPortalVehicleMetaTest`(5개 응답 전수 · null degrade · 키 손코딩 정적 검사 · PII 누출).

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

---

## 10. 판매계약서 전자서명 세션 발급 (2026-07-10 풀회의 — ERP 직접호스팅)
> 회의록 = `docs/meetings/2026-07-10-sales-contract-e-signature.md`(6부서+Codex/Gemini 조건부 GO). 인계 패킷 = `docs/integration/handoff-board-esignature.md`.
> **핵심 아키텍처**: 서명 페이지·서명본·증거 전부 **ERP가 직접 호스팅·완결**. board = **서명 URL을 바이어 1:1 채널(카톡/SNS)로 전달하는 창구만**.
> ⇒ **board는 계약서 바이트를 받지 않는다**(§6 프록시와 다름). 여권ID·주소 등 바이어 PII는 ERP-호스팅 서명 페이지에만 노출 → **§3/§29 화이트리스트 확장 불필요**(board 노출면 0). 서명본 회신 HMAC·board 프록시 PII·queue worker 문제 전부 회피.

### 10-1. `POST /internal/board/signing-requests` — 서명 세션 발급 후 서명 URL 반환
prefix `/api/internal/board`, 미들웨어 `[VerifyBoardReadHmac, throttle:board-read]` (§1 HMAC 동일 — POST라 canonical BODY = raw JSON).

**요청 (JSON body)**:
```json
{ "salesman_email": "sales@heyman.com",
  "vehicle_ids": [1215, 1216],
  "recipient_email": "buyer@example.com" }
```
- `salesman_email` (필수) — §2 IDOR 스코프(`SalesmanResolver::resolveActiveOrFail`). 매칭 실패/퇴사 = 403.
- `vehicle_ids` (필수, 1~30) — **all-or-nothing 계약 묶음**. 검증: 전부 `sales_channel='export'` + 전부 그 영업 소유(`salesman_id`) + **동일 바이어** + **동일 통화**(sales_contract 동질성 가드, §VehicleDocumentController::showMulti 재사용). 위반 시 422.
- `recipient_email` (선택) — 미전송 시 ERP가 **바이어 `contact_email`로 기본 설정**. 서명 완료 시 바이어가 페이지에서 최종 확정 입력.

**응답 (200)**:
```json
{ "signed_url": "https://heysellcar.com/sign/<token>?expires=...&signature=...",
  "contract_no": "SC2607-01215",
  "buyer": { "id": 42, "name": "ABC TRADING" },
  "currency": "USD",
  "vehicle_count": 2,
  "status": "pending",
  "expires_at": "2026-07-17T09:00:00+09:00" }
```
- `signed_url` = **Laravel `temporarySignedRoute`**(APP_KEY 서명 + 만료 7일 임베드) + DB `sign_token`(추측불가 핸들). 이 URL 자체가 인가 — board는 **그대로 바이어에게 전달만**(파싱·재서명 금지).
- **단일활성 불변식 (재발급 = 항상 성공, 409 없음)**: 재발급하면 ERP가 **선택 차량과 겹치는(공유 차량 1대 이상) 기존 `pending`/`viewed` 세션을 자동 `revoked`** 처리하고 새 세션 발급(한 차량이 두 미서명 계약에 안 묶임). 이미 `signed`된 세션은 **법적 증거물이라 보존**(revoke·삭제 안 됨)되지만 새 발급을 막지 않는다 — **가격 정정 후 재서명**(중고차 실무) 등을 위해 재발급은 항상 새 pending 세션을 만든다. ⇒ `409` 없음.

> ✅ **ERP측 구현 완료 (2026-07-10, dev f9e686d·이 커밋)** — 엔드포인트·서명 페이지(ERP 호스팅)·서명본(Certificate of Completion, 옵션 A 단일 PDF)·증거메일 전부 동작. **board측(§10-3)은 미착수** — board 세션에서 이 스펙대로 client·버튼 구현.

### 10-2. `GET /internal/board/signing-requests?salesman_email=&vehicle_ids=1,2` — 서명 상태 조회 (✅ ERP 구현됨)
board가 "발송됨/열람됨/서명완료"를 영업 화면에 표시할 때 — **그 묶음 차량 set 을 넘겨 폴링**한다.
- 쿼리: `salesman_email`(§2 IDOR) + `vehicle_ids`(그 묶음 차량, 콤마구분). 본인 차 아니면 403.
- 매칭: 그 set 의 현 세션(signed 우선, 없으면 active pending/viewed, revoked 제외). ERP 칩과 동일 규칙(`SignedContract::pickForSet`).
- 응답(200):
```json
{ "status": "signed",              // none | pending | viewed | signed
  "contract_no": "SC2607-01215",
  "vehicle_count": 2,
  "sent_at": "...", "viewed_at": "...", "signed_at": "..." }
```
  미발송이면 `{ "status": "none" }`.
- **⚠️ PII·서명본 파일·서명이미지 미포함**(상태 메타만). 서명본 열람은 ERP 내부에서만(canScopeVehicle).
- board 는 이 status 로 칩 색/문구 갱신: none=`✍요청` / pending·viewed=`⏳대기` / signed=`✓서명완료`(녹색). ERP 와 동일한 그림.
- HMAC = 읽기 GET canonical(§1, 빈 바디). 미구현/degrade 시 board 는 "전송함"만 노출(graceful).

### 10-3. board 측 작업 (board repo·board 세션 — 복사 금지)
1. `CarErpReadService`에 `requestSigningSession(vehicleIds, recipientEmail?)` 추가(POST HMAC, §1 canonical BODY 포함). 401/5xx/미설정 → "발급 불가" degrade.
2. board 판매계약서 화면에 **「전자서명 요청」** 버튼 → 위 호출 → 응답 `signed_url`을 **바이어에게 카톡/SNS/이메일로 전달**(board가 이미 가진 바이어 채널 사용). ERP는 전달을 대행하지 않음.
3. (선택) `GET`으로 상태 폴링해 "열람됨/서명완료" 뱃지 표시.
4. **board는 서명 페이지를 호스팅하지 않는다** — URL만 전달. 서명·서명본·증거메일은 전부 ERP.

### 10-4. 흡수 금지 (서명)
- board가 서명 페이지 호스팅·서명본 보관·CoC 생성(전부 ERP 완결).
- 서명 URL을 board가 재서명·변조·프록시(그대로 전달만).
- 계약서 바이트·바이어 PII를 board로 끌어오기(URL 전달만이라 애초에 불필요).

---

## 11. 요청·확인 신호 (카톡 대체) — `board_requests` (2026-08-07 jin 범위 확정)

> 상태: **스펙 확정 · ERP 미구현.** 계약을 먼저 고정하고 양쪽이 병행 개발한다.
> ERP 계획서 = `docs/design/board-erp-request-ack.md`. 결정 배경 = 그 문서 §1·§6.
> ⚠️ **board 변경은 board repo/세션에서 커밋**한다(복사 금지=drift). 본 절이 권위.

### 11-1. 무엇인가 — "두 마디"만 옮긴다

실무자가 카톡으로 주고받던 **"해주세요" / "했습니다"** 를 시스템 안으로 넣는다.
데이터(금액·상태)는 이미 §4 읽기 API 로 흐르고 있으므로 **새로 실어 보낼 게 없다**.

| 신호 | `type` | 단위 | 뜻 | 닫히는 방법 |
|---|---|---|---|---|
| **입금요청** | `purchase_payment` | 차량 1대 | "이 차 입금해주세요" | **ERP 자동** — 매입 미지급 0 이면 소멸 |
| **판매대금확인** | `sale_payment_confirm` | 바이어 1 + 차량 N대 | "이 바이어 차 N대 대금 넣었으니 확인해주세요" | **ERP 에서 수동** — 차량별 체크(부분확인) |

> 👤 **확인 주체 = `canConfirmFinance()`** — **super · admin · 업무관리자 · role∈{재무, 관리}**.
> "재무만"이 아니다(jin 2026-08-09 지적). 관리·업무관리자도 누를 수 있고, 그게 의도다.
> 요점은 **영업이 스스로 확인할 수 없다**는 것 — 통장을 본 사람과 요청한 사람이 갈려야 신호에 의미가 있다.

### 11-2. 🚫 금액을 주고받지 않는다 (최중요)

- 요청 body·응답 어디에도 **금액 필드가 없다**. board 가 금액을 보내도 ERP 는 **무시**한다.
- 매입 지급액·판매 N잔금 기입은 **전부 ERP 관리 이상**의 일이다. 신호는 "누구의 어느 차"까지만 지목한다.
- 근거 = jin 2026-08-07: "금액을 넣어서 그 금액이 반영되게는 하지말자. 진짜 단순한 신호수준."
- 은행 API 연동 시 입금요청이 "계약금/잔금 얼마" 로 확장될 예정 — **그때 이 절을 개정**한다. 지금 필드를 선점하지 말 것.

### 11-3. 엔드포인트

prefix `/api/internal/board`, 미들웨어 = §1 `VerifyBoardReadHmac` + `throttle:board-read`.
인증·서명 canonical·replay 방지는 **§1 그대로**(POST 는 raw body 포함). 스코프는 **§2 그대로**(`salesman_email`).

#### `POST /requests` — 신호 보내기

```jsonc
{
  "salesman_email": "sales@example.com",   // 쿼리·서명 포함 (§2)
  "type": "sale_payment_confirm",
  "vehicle_ids": [12, 34, 56],
  "buyer_id": 7,          // sale_payment_confirm 필수 / purchase_payment 는 생략
  "note": "5/12 송금분"    // 선택, 200자
}
```

응답 `201`:
```jsonc
{
  "batch_id": "9f1c…",                     // sale_payment_confirm 만. purchase_payment 는 **null**
  "created": ["18누0304", "19더49065"],
  "skipped": [
    { "vehicle_number": "21모1234", "reason": "already_open" },  // 내 차 → 차량번호로 돌려준다
    { "vehicle_id": 9999,          "reason": "forbidden" }       // 남의 차 → **id 만**(차량번호를 알려주지 않는다)
  ]
}
```

- **멱등**: 같은 `(vehicle_id, type)` 에 `open` 이 있으면 새로 만들지 않고 `skipped` 로 돌려준다.
  board 가 재전송해도 안전 — 중복 뱃지가 안 생긴다.
- **스코프 재인가**: `vehicle_ids` 는 §2 로 매칭된 영업 것만 통과. 남의 차량 id 는 `skipped(reason: forbidden)`.
  (전량 거부 대신 부분 성공 — 한 대 때문에 묶음 전체가 죽지 않게.)
- `sale_payment_confirm` 은 **모든 차량이 `buyer_id` 소속**이어야 한다. 아니면 `422 buyer_mismatch`(오배치 방지).
  🔒 **이 422 를 약화시키지 말 것** — board 의 화면 제약(바이어 블록 안에서만 체크)은 실수 방지일 뿐이고,
  `toggleReqVehicle(buyerId, vehicleId)` 는 공개 Livewire 액션이라 조작된 클라이언트는 섞인 묶음을 만들 수 있다.
  **바이어 혼합을 실제로 막는 건 서버의 이 422 하나뿐이다.**
- `purchase_payment` 에 `vehicle_ids` 를 2개 이상 보내면 **각각 별개 요청**으로 생성된다(단위가 1대라서).
  응답 `batch_id` 가 `null` 인 이유이기도 하다 — 돌려줄 묶음이 하나로 정해지지 않는다(행에는 각자 uuid 가 들어간다).
  ⇒ 입금요청을 취소하려면 `GET /requests` 로 `batch_id` 를 먼저 조회한다.
- ✅ **`vehicle_id` 는 `/purchases`·`/sales` 응답에 있다**(2026-08-08 추가). `/sales` 는 `buyer_id` 도 준다 —
  바이어를 이름 문자열로 맞추면 동명이인·표기흔들림에서 위 422 로 튕긴다.
  ⚠️ 이 필드들을 지우면 **예외도 로그도 없이 board 버튼만 안 켜진다**(board 는 "전송 불가"로 비활성 처리).
  가드 = `BoardPortalApiTest::test_portal_exposes_ids_needed_for_board_requests`.

#### `GET /requests` — 상태 폴링 (칩 갱신)

`?salesman_email=…&status=open|all` (기본 `open`)

```jsonc
{
  "count": 1,                                // 묶음 개수
  "requests": [{
    "batch_id": "9f1c…",
    "type": "sale_payment_confirm",
    "status": "partial",                   // open | partial | done | cancelled
    "buyer_name": "ABC TRADING",
    "requested_at": "2026-08-07T10:00:00+09:00",
    "vehicles": [
      { "vehicle_number": "18누0304", "status": "done", "confirmed_at": "2026-08-07T14:20:00+09:00" },
      { "vehicle_number": "22가1111", "status": "open", "confirmed_at": null }
    ]
  }]
}
```

- `status` 는 라인 집계 — 전부 done = `done` / 일부 done = `partial` / 하나도 안 됐으면 `open`.
- **금액·마진·PII 없음**(§3 화이트리스트 유지). 차량번호·상태·시각뿐.

#### `POST /requests/{batch_id}/cancel` — 오클릭 취소 (선택 구현)

`open` 라인만 `cancelled`. 이미 `done` 인 라인은 남긴다(회신 기록 보존). 없으면 `409`.

### 11-4. board 측 작업 (board repo·board 세션 — 복사 금지)

1. `CarErpReadService` 에 `sendBoardRequest(type, vehicleIds, buyerId?, note?)` + `fetchBoardRequests(status?)` 추가.
   HMAC 은 §1 canonical **바이트 단위 일치**(POST 는 body 포함).
2. **[입금요청] 버튼** — 차량 1대 화면/행에서. 누르면 그 차 1대만 전송.
3. **[판매대금확인] 버튼** — 바이어를 고르고 그 바이어 차량 N대를 **체크해서** 전송(선적요청 UI 와 같은 감각).
   ⚠️ 서로 다른 바이어의 차를 한 묶음에 담지 못하게 board 에서 먼저 막는다(ERP 도 `422` 로 이중 방어).
4. **상태 칩** — `GET /requests` 폴링. `partial` 이면 `3/5` 처럼 보여준다.
   ERP 값 **그대로** 표시(재계산·완료 coerce 금지 — §8 degrade 원칙 동일).
5. **degrade** — 401/5xx/미설정이면 "**전송 불가**" 표시. 성공한 척하지 말 것(영업이 보냈다고 착각하면 카톡보다 나쁘다).
6. **금액 입력칸을 만들지 않는다** — §11-2. 만들어도 ERP 가 버린다.

### 11-5. 흡수 금지 (신호)

- board 가 금액을 실어 보내거나, ERP 회계 컬럼(`final_payments`·`purchase_balance_payments`)에 **간접적으로라도 쓰기**.
- board 가 요청을 **스스로 done 처리**(확인은 ERP 쪽 사람의 행위다 — 요청자와 확인자가 갈리는 게 이 기능의 존재 이유).
- 신호를 `vehicles` 상태 컬럼에 적재(§9 와 동일 — 게이트 회귀).

---

## 12. 운항 상태 (🚢 운항중 / ⚓ 도착예정) — 2026-08-09 신설

> **ERP 차량목록에 먼저 만들고 같은 축을 board 에도 내보낸다.** 판정은 `Vehicle::scopeSailing` **단일 출처**.
> board 가 조건을 옮겨 적으면 "ERP 엔 운항중인데 board 엔 아님"이 생긴다(§8 #44 와 같은 형태).

### 12-1. 무엇인가 — 진행상태와 **직교하는 축**

`progress_status`(매입중~거래완료)의 **한 단계가 아니다.** 선적일(= 실제 출항일)과 도착예정일이 둘 다 있으면
배가 떴다고 보고, ETA 가 미래면 `운항중`, 지났으면 `도착예정`이다.

```
선적일 있음 + ETA 미래  →  운항중     (in_transit)
선적일 있음 + ETA 과거  →  도착예정   (arrived)
둘 중 하나라도 없음     →  null
```

- **진행상태를 가로지른다** — 실측(heymanerp 239대) 기준 운항중 97대가 `선적중·선적완료·통관중·거래완료`
  네 단계에 흩어져 있다. 그래서 진행상태 필터와 **동시에** 걸 수 있다.
- ⚠️ **「도착예정」은 ETA 가 지났다는 뜻이지 실제 입항 확인이 아니다.** board 화면 문구도 그렇게 쓸 것.
  실제 입항을 알려면 포워더 소스가 필요한데 2026-08-09 현재 ERP 에 없다.
- 🚫 **`progress_status` cascade 에 넣지 않았다** — 넣으면 정산 자동생성(거래완료 진입 감지)과
  재고 판정이 동시에 깨진다. board 도 이 값을 진행상태로 **승격시켜 쓰지 말 것**.

### 12-2. 응답 필드 (`GET /sales` · `GET /inventory`)

| 필드 | 값 | 용도 |
|---|---|---|
| `sailing` | `in_transit` \| `arrived` \| `null` | **기계용 키.** 필터 파라미터와 같은 값 — 분기·아이콘 매핑은 이걸로 |
| `sailing_status` | `운항중` \| `도착예정` \| `null` | **표시 라벨.** ERP 값 그대로(재명명 금지) |
| `vessel_name` | 선박명(VSL) \| `null` | 같은 배에 실린 차를 묶어 보여줄 때 |
| `shipping_date` | `YYYY-MM-DD` \| `null` | 출항일 |
| `eta_date` | `YYYY-MM-DD` \| `null` | 도착예정일 |

> `sailing` 과 `sailing_status` 는 **같은 판정의 두 표현**이다. 둘 중 하나만 보고 다른 하나를 만들어내지 말 것.
> 재고(`지급대기`·`일반`·`선적전`)는 출고 전이라 대개 `null` 이고, **`출고완료`(`shipped_out`) 탭에서 의미가 있다**
> — "나갔다"만으로는 배 위인지 도착했는지 모른다.

### 12-3. 필터 — `GET /sales?sailing=in_transit`

- 값은 **영문 키만**(`in_transit` | `arrived`). ⚠️ 라벨(한글)을 쿼리에 쓰지 말 것 —
  쿼리 문자열은 §1 HMAC **서명 대상**이라 인코딩이 한 바이트만 달라도 서명이 깨진다.
- 화이트리스트 밖 값은 **무시**(필터 없음)한다. 422 로 죽이지 않는다 — board 구버전 호환.
- `exclude_status` 와 **동시에** 걸린다(직교 축). 예: `sailing=in_transit&exclude_status=거래완료`
  = "배 위에 있는데 아직 B/L 안 나온 차".
- 서버에서 거르므로 트래픽이 실제로 준다(board 가 받아놓고 감추면 의미 없음 — §4 와 같은 원칙).

### 12-4. board 측 작업 (board repo·board 세션 — 복사 금지)

1. `CarErpReadService` 의 `sales()`/`inventory()` 응답 매핑에 위 5개 필드를 태운다.
   **없으면 `null` 로 두고 조용히 넘어갈 것**(ERP 배포 전이면 필드가 안 온다 — degrade).
2. **칩 표시** — 목록 행에 `sailing_status` 를 뱃지로. ERP 색과 맞추면 `운항중`=파랑 / `도착예정`=초록.
   분기는 `sailing`(영문 키)으로 하고 문구는 `sailing_status` 를 그대로 출력한다.
3. **필터** — 「운항중만 보기」 토글이면 `sailing=in_transit` 를 쿼리에 얹는다(서명에 포함).
4. **선박명 묶기** — `vessel_name` 으로 그룹핑하면 "이 배에 실린 내 차" 목록이 된다.
   ERP 차량목록도 선박명 컬럼·정렬을 같은 목적으로 추가했다.
5. ⚠️ **문구에 "도착"만 쓰지 말 것** — 반드시 「도착예정」. 영업이 바이어에게 "도착했다"고 전하면
   지연 시 그대로 클레임이 된다.

### 12-5. 흡수 금지 (운항)

- board 가 **선적일·ETA 로 자체 판정**(조건 복제 = drift). ERP 가 준 `sailing` 을 그대로 쓴다.
- `sailing` 을 `progress_status` 자리에 끼워 넣어 **한 축으로 합치기** — 두 축은 독립이다.
- 「도착예정」을 **입항 확정**으로 표시하거나, 그걸 근거로 정산·미수 판단을 하기.
