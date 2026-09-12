# 신규 정산 게이트 + 예외 처리 (jin 2026-09-12 확정)

> 상태 = **사양 확정 · 착수 대기**. 공수 **2.5~3 인일**.
> 이 문서가 정본이다. 착수 시 다시 조사하지 말 것 — 아래 「조사로 확인된 사실」이 실측 결과다.
> 관련: `SKILLS.md §8 #65`(가드 3질문) · `#66`(완화 시 체크) · `#81`(예외는 새 게이트에 안 물려짐)
> · 메모리 `project_settlement_lock_redesign`(2026-07-24 락 개편)

---

## 0. 한 줄 요약

수동 「신규 정산」에는 차량 상태 검증이 **하나도 없다**(형식 4줄뿐). 반면 **자동 생성은 미수가 남으면
정산을 아예 안 만들고**, 그 아래 「미수 지급보류」에도 우회가 **0건**이라 운임비 후입력 건이
**영구히 정산되지 않는다** — 실측 heymanerp **6건이 2개월째**(§1-7).
⇒ 조건을 단일 출처 함수로 뽑아 자동·수동·지급보류가 **같은 술어**를 보게 하고,
**금액 조건 없는 사유 기반 예외**를 만든다. 예외 건은 2차 마감까지 하되 **회계 잠금만 유예**한다.

⚠️ **주 통로는 「예외로 정산 생성」이다**(§2-4). 「지급보류 해제」는 실측상 대상이 0건이라 부 통로다 —
설계 중간에 이 판단이 뒤집혔으므로 §1-2 를 §1-7 보다 먼저 읽고 오해하지 말 것.

---

## 1. 조사로 확인된 사실 (2026-09-12 실측)

### 1-1. 자동 vs 수동 검증

| 검사 | 자동 `Vehicle::createSettlementIfComplete` (`app/Models/Vehicle.php:1464-1513`) | 수동 `settlements/index.blade.php::save()` (:658-710) |
|---|---|---|
| 매입취소 | :1468 return | 없음 |
| 판매가 > 0 | :1471 return | 없음 |
| **미수 > 0** | :1474 return | **없음** |
| 담당자 존재 | :1477 return | 없음 |
| 중복 정산 | :1477 return | **없음** |
| **운임 확정** | :1480 return | **없음** |
| 내수 차액 0 | :1491 return | 없음 |
| `attributed_month` 박제 | :1500 | NULL (폴백 `COALESCE(confirmed_at, created_at)`) |
| `is_domestic` 박제 | :1506 | NULL/false |

수동 경로 검증 = **형식 4줄뿐** (`vehicle_id` 존재 / `settlement_type` enum / `other_deduction` 숫자 /
`settlement_status` enum). 차량이 정산할 상태인가를 보는 조건 **0개**.

### 1-2. 지급보류에 우회가 0건이다

> ⚠️ **이 절의 제목은 원래 「진짜 병목은 생성이 아니라 지급보류」였다. §1-7 실측으로 뒤집혔다** —
> 지급보류 목록은 3사 전부 **0건**이고, 실제로 막혀 있는 것은 **정산이 아예 안 생긴 쪽**이다.
> 아래 내용(우회 0건·주석 근거)은 그대로 유효하고, **예외가 지급까지 열어야 한다**는 결론도 그대로다.

- `Settlement::isPayoutHeldByUnpaid()` (`app/Models/Settlement.php:289-295`)
- `Settlement::scopePayoutHeldByUnpaid()` (:298-303) — SQL 판, 캐시 컬럼 기준
- `SettlementPayoutBatch::eligibleSettlementIds()` (`app/Models/SettlementPayoutBatch.php:197-199`)
  `->reject(fn ($s) => (int) ($s->vehicle?->sale_unpaid_amount ?? 0) > 0)`

**우회·override 전 레포 grep 0건.** 그 주석(:194-196)이 이미 상황을 적어두고 있다 —
「운임비 후입력 등으로 완납 후 미수가 재발하면 지급 시점에 재차단」.

⇒ 예외를 만들면 **지급보류까지 함께 열어야** 한다. 생성만 열면 그 정산은 영원히 지급이 안 된다.
jin 이 든 두 사례:
1. 컨테이너 운임비 — 도착까지 길게 5개월. 운임비는 정산 기준액 **밖**인데 미수를 만들어 막는다. (실재 6건)
2. 6 USD / 10 EUR 소액 — 바이어가 다음 건과 묶어 보내겠다고 한 경우. (현재 실재 0건)

### 1-3. 지급 통로별 보류 준수 (새로 드러난 것)

| 통로 | 보류 보나 | 결정 |
|---|---|---|
| 월배치 `eligibleSettlementIds` | ✅ | 유지 + 예외 통과 |
| 개별 지급 승인 `ApprovalRequest::executeSettlementPay` (`app/Models/ApprovalRequest.php:220-240`) | ❌ 안 봄 | **보류를 걸되 예외로 통과** |
| 수동 save 로 `paid` 직접 지정 (`index.blade.php:663, 686-689`, `Settlement::saving` :161-168 admin 통과) | ❌ 안 봄 | **그대로 열어둠** (jin 2026-09-12 «최고관리자가 정산 만들 일 없긴 한데 그대로 둬») |

⚠️ 실사고 `#5806`(ssancarerp `21소0281`, 미수 87 EUR)이 **수동 생성인 것은 확인됐다** —
`note`·`attributed_month` 가 둘 다 NULL 인데 자동 경로 3곳은 반드시 채운다(메모리 `project_manual_settlement_gate`).
다만 그 뒤 **어느 지급 통로를 탔는지는 미확인 = 추정**.

### 1-4. 운임비의 성격 (코드 확인됨)

| 합계 | `transport_fee` | 근거 |
|---|---|---|
| 총판매가(미수 분모) | 포함 | `Vehicle::getSaleTotalAmountAttribute` :2878-2884 |
| 미수 분자 | 포함 | `getSaleUnpaidAmountAttribute` :2181-2182 |
| **정산 기준액** | **제외** | `Settlement::getSalesAmountKrwAttribute` :404-407 |
| 면장 기준액 | 포함 | `getDeclarationBaseAmountAttribute` :2900-2903 |

⇒ 운임비는 **정산액에 1원도 영향을 안 주면서** 미수를 만들어 지급을 막는다.

### 1-5. 미수 표시는 정산과 무관 (확인됨)

- `Vehicle::getUnpaidRatioAttribute` = 총판매가 vs 받은 돈. **정산을 안 본다** ⇒ 예외 지급돼도 게이지 그대로.
- 채권관리(`receivables/index.blade.php`)가 정산을 보는 곳은 **:516 한 군데**뿐 —
  2차 마감 차량의 **기존** 회수이력 수정 차단용. 예외 건(2차 마감 + 잠금 유예)에는 영향 없음.

### 1-6. 회계 잠금 구조 (2026-07-24 개편 후)

단일 트리거 = `Vehicle::hasClosedSecondarySettlement()` (`app/Models/Vehicle.php:622`).
`paid` 만으로는 더 이상 안 잠긴다.

잠금이 거는 것 — **세 지점이 코드상 이미 분리돼 있다**:

| | 지점 | 비고 |
|---|---|---|
| **A** | `guardLedgerLockOnSaving` — 차량 회계 26컬럼 | 매입가·판매가·환율·비용 10개 |
| **B-①** | `FinalPayment::creating` (`app/Models/FinalPayment.php:117`) | **신규 잔금 추가** |
| **B-②** | `FinalPayment::updating` (:194) 금액·수금일·환율 | 잠금해제 토큰 1회 소비로 통과 |
| **B-③** | `FinalPayment::deleting` (:208) | 확정 잔금 삭제 |

같은 트리거를 쓰는 다른 소비자: `PaymentConfirmationService::confirmPayment` (`:134`) ·
채권관리 `deposit` 회수이력 · `PurchaseBalancePayment` (:125, :140) · `VehicleShipment` (:108) ·
`BulkVehicleShipmentService` (:61) · `VehicleLedgerUnlockService::unlock` (:64).

2차 마감 게이트 = `secondaryCloseBlocker()` (`settlements/index.blade.php:1147-1164`):
`secondary_status !== 'pending'` / **외화 && 미수 > 0** / 환율 산출 실패.

---

## 1-7. 실제 대상 규모 (2026-09-12 운영 실측 — 읽기 전용)

🚨 **「지급보류로 돈이 묶여 있다」는 틀렸다.** 실측:

| 회사 | 지급보류(confirmed·미배치·미수>0) | 정산 미생성 + 미수 있음 | 그중 **미수 = 운임비** | 소액(미수<100) |
|---|---|---|---|---|
| heymanerp | **0건** | 61건 | **6건** | 0건 |
| ssancarerp | **0건** | 458건 | **0건** | — (금액대가 큼) |

⇒ **막혀 있는 것은 「지급보류」가 아니라 「정산이 아예 안 생긴 쪽」이다.**
자동 생성이 `미수 > 0` 에서 return 하므로(:1474) 정산 행 자체가 없다.

**진짜 대상 = heymanerp 6건** — 전부 **미수가 운임비와 정확히 일치**하고 판매일이 2026-07-10~22,
즉 **2개월째 담당자 정산이 안 나가고 있다**:
```
328서9043  1,312 EUR   164너7075  1,312 EUR   28소7935  1,312 EUR
65누1779   1,312 EUR   226나4545  1,739 EUR   65보2256  1,739 EUR
```
ssancarerp 458건은 미수가 8,070 / 28,300 / 65,610 USD 급이라 **진짜 못 받은 돈**이다.
운임비 일치 0건 · 소액 0건 ⇒ **예외 대상이 아니다.** 이 화면에 예외 버튼이 생겨도 눌릴 일이 없어야 한다.

> 🔑 **그래서 주 통로가 바뀐다.** 앞서 「지급보류 행에서 바로 예외」를 주 통로로 잡았으나,
> 실측상 그 목록은 **비어 있다**. 실제로 필요한 것은 **「예외로 정산을 만드는」 통로**다(아래 2-4).

## 2. 확정 사양 (jin 2026-09-12)

| 항목 | 결정 |
|---|---|
| **예외 권한** | **재무 이상** (`canConfirmFinance` 계열). 해제도 **재무 이상 아무나** |
| **예외 조건** | **없음.** 미수 금액 무관, 사유만 쓰면 통과 |
| **예외 거는 곳** | **① 「예외로 정산 생성」(주 통로 — 실측상 여기가 대상 전부)** + ② 지급보류 걸린 정산 행에서 해제 |
| **지급 통로** | 월배치·개별 승인 = 보류 + 예외 통과 / **최고관리자 직접 `paid` = 그대로 열어둠** |
| **2차 정산** | **완납 게이트를 예외가 통과** → 마감 가능 → 환차·이월 정상 계산(프리랜서 이월 생존) |
| **회계 잠금** | **유예.** 단 **B-① 신규 잔금 추가 + 재무확정 + 채권관리 입금만** 열고 **A·B-②·B-③ 은 잠금 유지** |
| **재잠금** | **미수 0 이 되는 순간 자동** — 입금·손실처리·예외해제 어느 쪽이든 |
| **미수 표시** | 손대지 않음. 게이지·채권관리 그대로 |
| **필터** | 정산 화면에 「예외 처리된 건」 |
| **경고** | 예외 처리 시 「환차·이월은 지금 확정됩니다. 이후 입금은 담당자 정산에 반영되지 않습니다」 |
| **남는 기록** | 사유 · 누가 · 언제 · **그때의 미수액**(보여주기용, 막지 않음) |

### 2-4. ⭐ 「예외로 정산 생성」 — 주 통로

대상 6건은 **정산 행이 없다.** 예외는 정산 행에 붙는데 붙일 데가 없으므로, 먼저 만들어야 한다.

🚫 **수동 「신규 정산」 폼을 쓰게 하지 말 것** — 담당자·정산방식·비율을 손으로 고르게 되고,
`attributed_month`·`is_domestic` 이 NULL 로 남아 **귀속월이 완납월이 아니라 생성월**이 된다(1-1 표).

✅ **차량을 고르고 사유만 쓰면 「자동 생성과 똑같이」 만드는 버튼**을 둔다:
- 대상 = `settlementBlockers()` 가 **`unpaid` / `freight_unconfirmed` 만** 남은 차량
  (담당자 없음·판매가 0·중복은 예외 사유가 아니라 **입력 미비**다 — 그건 계속 막는다)
- 생성 로직은 `createSettlementIfComplete()` **본체를 그대로 재사용**(조건만 건너뜀)
  ⇒ `attributed_month`·`is_domestic`·`note` 가 자동 경로와 동일하게 채워진다
- 만들면서 예외 5컬럼 기입 + `AuditLog`
- 화면 = 정산관리에 「예외 대상」 목록(위 조건 차량) + 각 행에 [예외로 정산 생성]

### 2-1. 왜 「잠금 유예」의 범위를 B-① 로 좁혔나

jin 요구 = *"예외처리 된것들 한해서 잔금처리는 기존대로"*. 필요한 건 **나중에 들어온 돈을
기록하는 것** 하나다. 통째로 풀면 판매가·환율이 사후에 바뀔 여지가 열리고,
그 변경은 **마감된 정산에 반영되지 않아**(record-only) 차량 데이터와 정산 스냅샷이 갈린다.
⇒ **과거 기록은 못 건드리고 새로 받은 돈만 추가.** 꼭 고쳐야 하면 기존 **[🔓 회계 재조정]**
(관리 승인 + 사유 10자) 통로를 그대로 쓴다.

### 2-2. 재잠금 판정 (별도 상태값 없음 — 매번 계산)

```
잠금 = 2차 마감된 정산이 있다
       AND NOT ( 그 마감 정산에 예외가 있다  AND  차량 미수 > 0 )
```
- 한 차에 마감 정산이 여럿이고 **그중 하나라도 예외가 아니면 → 잠금**(안전한 쪽).
- 새 컬럼으로 상태를 들고 있지 않으므로 **어긋날 여지가 없다**(§8 #80 의 그 부류를 회피).

### 2-3. 받아들인 제약 (jin 인지함)

**2차 마감하는 순간 환차·이월이 1회 확정되고, 그 뒤 들어온 돈은 담당자 정산에 반영되지 않는다.**
이건 이번에 생기는 제약이 아니라 2026-07-24 개편의 기존 규칙이다(post-close = record-only, 3차 없음).

💡 **실무 회피법** — 나중에 들어온 잔금을 **판매환율로 넣으면 정산 숫자가 안 움직인다**
(운임비는 정산 base 밖 + 실효환율 = 판매환율이 되어 총마진 불변). 움직이는 건 **다른 환율로 넣을 때**다.
금액이 큰 미수를 예외로 넘길 때만 유의. 화면 경고 문구가 이걸 알린다.

---

## 3. 구현 계획

### 3-1. 조건을 단일 출처로 (조건 복제 금지 — §8 #44·#67)

`app/Models/Vehicle.php` 에 신설:

```php
/** 정산을 만들 수 없는 사유 — 자동·수동·화면 칩의 단일 출처. 빈 배열 = 생성 가능. */
public function settlementBlockers(): array
// ['purchase_cancelled','no_sale','unpaid','no_salesman','already_exists','freight_unconfirmed','domestic_zero']
```

`createSettlementIfComplete()` (:1464-1493)의 7개 early-return 을 **글자 그대로 옮기고**
훅은 `if ($this->settlementBlockers() !== []) return;` 로 축소. **동작 불변**.

⚠️ **파리티 주의** — 같은 조건의 사본이 둘 더 있다: `settlementStage()` (:1637-1660) ·
`scopeAwaitingFreightConfirm()` (:1671-1689, SQL판). **축이 달라 이번엔 안 건드린다**
(「지금 어느 단계인가」 vs 「왜 못 만드나」). `isFreightConfirmedForSettlement()` 공유는 유지.

### 3-2. 마이그레이션 — `settlements` 5컬럼 (전부 nullable, enum 무관)

| 컬럼 | 타입 | 뜻 |
|---|---|---|
| `gate_override_reason` | `text` | 사유 (앱 검증 `min:10` — `VehicleLedgerUnlockService::MIN_REASON_LENGTH` 와 같은 기준) |
| `gate_override_by` | `foreignId` users `nullOnDelete` | 누가 |
| `gate_override_at` | `timestamp` | 언제 |
| `gate_override_blockers` | `json` | 그때 무엇을 넘겼나 |
| `gate_override_unpaid_amount` | `decimal(18,2)` | **그때의 미수 스냅샷 — 보여주기 전용, 판정에 안 씀** |

> 🚫 스냅샷으로 **막지 않는다**(jin 2026-09-12 «미수가 얼마든 예외처리»).
> 화면에 「예외 당시 6 EUR → 현재 520만원」을 보여주는 용도다.

🚫 **`UnpaidExportOverride` 재사용 부적합** — `vehicle_id` 하드 FK(`2026_05_13_000001:17`),
stage 축이 다름, enum ALTER 필요(§8 #36). 단 **그 테이블의 설계는 본뜬다**(사유 NOT NULL + 승인자 + 금액 스냅샷).

### 3-3. 생성 게이트 (create 분기에만)

`resources/views/livewire/erp/settlements/index.blade.php`
- `selectVehicle()` (:555) · `openCreate()` (:621) 에서 blockers 계산 → **패널에 칩으로 먼저 표시**(§8 #60)
- `save()` 의 **create 분기**(:702-710)에만:
  - blockers 없음 → 통과 (사유란 안 뜸)
  - blockers 있고 사유 10자 미만 → `ValidationException(['vehicle_id' => …])` (이미 `validate()` 를 쓰므로 인라인 렌더)
  - blockers 있고 사유 충족 → 통과 + 5컬럼 기입 + `AuditLog`
- 🚫 **편집 분기(`editingId`)는 게이트 제외** (§8 #65 ①) — 폼에 실린 옛 `vehicle_id` 로도 참이 되어
  메모·기타공제 수정이 막힌다(`SettlementMemoSearchTest:159` 반타작이 통째로 죽는다).
- 🚫 **`Settlement::creating` 에 걸지 말 것** — `ImportVehicles:419` · `RecreateSettlementsFromCk:128` ·
  `ImportSsancarSettled:888` · `BackfillMissingSettlements:112` 가 전부 걸린다.

### 3-4. 지급 보류에 같은 술어 물리기 ⭐ (빠지면 반쪽)

네 곳이 **같은 판정**을 봐야 한다 — 하나만 빠져도 「뱃지는 없는데 필터엔 잡히는」 형태가 된다(§8 #44·#81):
1. `Settlement::isPayoutHeldByUnpaid()` (:289-295)
2. `Settlement::scopePayoutHeldByUnpaid()` (:298-303) — **SQL 판도 반드시**
3. `SettlementPayoutBatch::eligibleSettlementIds()` (:197-199)
4. 목록 뱃지 (`settlements/index.blade.php:1906-1907`)

➕ `ApprovalRequest::executeSettlementPay()` (:220-240) 에 **보류를 새로 걸고** 예외로 통과시킨다.

### 3-5. 2차 마감 게이트에 예외 통과

`secondaryCloseBlocker()` (`settlements/index.blade.php:1154`)의 `외화 && 미수>0` 분기를
**예외가 있으면 건너뛴다**. 미리보기·일괄 마감이 같은 함수를 쓰므로 자동으로 따라온다(§8 #67).
➕ 2차 마감 대기 목록·일괄 마감 미리보기에 **「예외 지급됨」 뱃지**(안 그러면 매달 「왜 안 되지」가 된다).

### 3-6. 회계 잠금 유예

`Vehicle::hasClosedSecondarySettlement()` 는 **그대로 두고**(A·B-②·B-③ 이 계속 이걸 본다),
**B-① 계열 3경로만** 새 판정을 쓰게 한다:
- `FinalPayment::creating` (`app/Models/FinalPayment.php:117`)
- `PaymentConfirmationService::confirmPayment` (`:134`)
- 채권관리 `deposit` 회수이력 미러

> 🔑 **재무확정을 같이 안 풀면 반쪽이다** — 미수 분자는 `confirmed_at` 있는 행만 센다(§13).
> 잔금은 들어가는데 미수가 안 줄어 재잠금이 영영 안 걸린다. §8 #66 에서 이미 밟은 자리.

### 3-7. 감사·라벨·가이드
- `AuditLog::recordEvent($settlement, 'settlement_gate_overridden')` / `..._released`
  사유는 `new_value` (`vehicles/index.blade.php:3644-3652` 의 `unpaid_override_approved` 패턴).
- `config/column_labels.php` `actions` 에 **한글 라벨 필수** (§8 #41, :417 옆). 안 넣으면 감사 화면에 영문 노출.
- 사용자 가시 동작이 바뀌므로 **가이드·카드 소스도 같은 커밋** (`--verify` 3줄, CLAUDE.md 「📖」절).

### 3-8. 자동 「사유 제안」 (판정 아님)

사유란을 **미리 채워주는 용도로만**:
- 미수가 운임비와 일치 → 「미수 1,528 EUR — 운임비와 같은 금액입니다」
- 미수가 총판매가 대비 작음 → 「미수 6 EUR — 판매금의 0.02%」

🚫 **자동 통과에 쓰지 말 것.** 「운임비만 남은 미수」는 원리상 판별 불가 —
입금이 항목별로 귀속되지 않고 총액으로만 들어온다(`Vehicle.php:2189`). 부분입금·혼합이면 우연히 맞거나 틀린다.
🚫 **소액 자동 판정 금지** — 통화별 임계가 필요하고(6 USD ≠ 10 EUR ≠ 800 JPY), 바이어 약속은 데이터가 아니며
(§8 #49), 임계는 곧 뚫리고(§8 #83), 같은 금액인데 통화마다 결과가 갈린다(§8 #72).

---

## 4. 테스트 (전부 **일부러 깨뜨려 확인** — §8 #73)

1. 수동 생성 — blockers 있고 사유 없으면 차단 / 사유 있으면 통과 + 5컬럼 기입
2. **편집 분기는 게이트를 안 탄다**(무관한 저장이 안 막힌다 — §8 #65 ①)
3. 자동 생성 경로 **동작 불변**(리팩터 파리티)
4. 예외 있으면 월배치에 포함 / 없으면 제외
5. 예외 있으면 개별 지급 승인 통과 / 없으면 차단(새 가드)
6. 예외 있으면 2차 마감 가능(외화 미수 있어도)
7. **잠금 유예** — 예외 + 미수 > 0 이면 신규 잔금 추가 가능, **차량 판매가 수정은 여전히 차단**
8. **재잠금** — 잔금이 들어와 미수 0 이 되면 즉시 잠김 / 예외 해제해도 잠김
9. 미수 표시 불변 — 게이지·채권관리
10. 정적 파리티 — 보류 판정 술어가 4곳에서 동일한지

⚠️ **`save()` 의 create 분기는 지금 테스트가 0건이다** (`openCreate`+`save` 조합 없음).
기존 테스트 4개는 전부 `openEdit` 경유라 **create 게이트는 아무것도 안 깬다**.
뒤집어 말하면 **그래서 `#5806` 이 났다.**

---

## 5. 공수

| 단계 | 공수 |
|---|---|
| 조건 단일화 + 자동 훅 리팩터(동작 불변) + 파리티 확인 | 0.5일 |
| 마이그레이션 5컬럼 + 모델 + 3-DB 검증 | 0.25일 |
| 생성 게이트 + 칩 + 사유란 + 「예외」 뱃지 + 필터 + lang ko·en(§8 #73) | 0.75일 |
| 보류 술어 4곳 + 개별 승인 가드 + 2차 마감 통과 + 대기목록 뱃지 | 0.5일 |
| **잠금 유예 3경로 + 재잠금 판정** | 0.5일 |
| 테스트 10종 + 일부러 깨뜨리기 | 0.5일 |
| 감사 라벨 + 가이드·카드 동기화 | 0.25일 |
| **합계** | **2.5~3 인일** |
