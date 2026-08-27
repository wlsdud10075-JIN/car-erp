# SKILLS - car-erp 기술 문서

재구현 시 필수 구현 패턴·재발 버그 회피·UI 디자인 규약 모음. 환경·권한·도메인 용어는 `CLAUDE.md` 참고.

> 📦 **2026-05-29 트림** — v1/v2/v3 grandfather 코드·폐기된 dompdf 버그 3건(#16~#18)·이미 구현된 NICE/이메일 상세 코드는 `docs/archive/md-2026-05-29/SKILLS.md.full` 로 이동. 옛 결정 맥락 필요 시 grep.

## 1. Volt 단일파일 컴포넌트 패턴

이 프로젝트는 Livewire Volt **단일파일** 방식을 사용합니다. PHP 클래스와 Blade가 하나의 `.blade.php`에 함께 있습니다.

```php
<?php
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    // 프로퍼티, 메서드
}; ?>

<div> {{-- 반드시 단일 루트 요소 --}}
    {{-- Blade 템플릿 --}}
</div>
```

**핵심 규칙**:
- `#[Layout('components.layouts.app')]` 속성으로 레이아웃 지정. `<x-layouts.app>` + `@volt` 래핑은 "Multiple root elements" 에러
- **Layout attribute 누락 금지** — Livewire 기본 `component_layout`이 `layouts::app`(존재하지 않는 namespace)이라 attribute 없는 Volt는 500 에러. auth 페이지는 `components.layouts.auth`

## 2. 차량 진행상태 자동계산 (`Vehicle::progress_status`)

진행상태는 **DB 컬럼이 아니라 computed property**. `getProgressStatusAttribute()` accessor에서 우선순위 순으로 평가하고 첫 번째 매칭을 반환.

`progress_status_rule_version` 분기 — **v4=기본값** (신규 row). v1·v2·v3=grandfather (운영 데이터 거의 없으나 안전망 유지, 상세는 archive `SKILLS.md.full` §2).

```php
public function getProgressStatusAttribute(): string
{
    $ruleVersion = (int) ($this->progress_status_rule_version ?? 4);

    if ($ruleVersion >= 4) {
        // v4 cascade — 반입(선적) → 통관 → B/L → 거래완료
        if ($this->bl_document) return '거래완료';
        if ($this->bl_document && $this->is_export_cleared) return '통관완료';     // 실질 도달 불가 — #1 우선
        if ($this->is_export_cleared && $this->bl_loading_location) return '통관중';
        if ($this->bl_loading_location && $this->export_declaration_document) return '선적완료';
        if ($this->bl_loading_location) return '선적중';
    } else {
        // v1~v3 grandfather (archive 참조)
        // ...
    }

    if ($this->sale_price > 0 && $this->sale_unpaid_amount <= 0) return '판매완료';
    if ($this->sale_price > 0) return '판매중';
    if ($this->is_deregistered && $this->deregistration_document) return '말소완료';
    if ($this->purchase_price > 0 && $this->purchase_unpaid_amount <= 0) return '매입완료';
    return '매입중';
}
```

**목록 필터링 — `progress_status_cache` 사용 (✅ 도입 완료)**:
- DB 컬럼 `progress_status_cache` (varchar 20, indexed) 자동 갱신
- `Vehicle::saving` 이벤트가 매번 `progress_status` 재계산 후 컬럼에 기록
- 잔금 변경 → `FinalPayment` / `PurchaseBalancePayment`의 `saved` / `deleted` 이벤트가 부모 차량의 `refreshProgressCache()` 호출
- 단, **bulk delete/update**(`whereIn->delete()`, `where->update()`)는 모델 이벤트가 안 뜸 → 그런 코드 직후 `$vehicle->refreshProgressCache()` **명시 호출 필수**
- 목록 SQL: `->when($filter, fn($q) => $q->where('progress_status_cache', $filter))`
- 일괄 재계산: `php artisan vehicles:rebuild-progress-cache`

**미입금/미지급 보조 accessor**:
```php
public function getSaleUnpaidAmountAttribute(): int { /* §13 공식 참조 */ }
public function getPurchaseUnpaidAmountAttribute(): int { /* §13 공식 참조 */ }
```

## 3. 슬라이드 패널 + 7탭 차량 편집

차량 70+ 필드를 한 화면에 펼치지 않고 **우측 슬라이드 패널** 내부 7개 탭으로 분산.

| 탭 | 주요 필드 |
|---|---|
| `기본정보` | 차량번호, 주행거리, brand/type/cc/kg, NICE API 등록정보 12개, 제원정보 12개, 차량 사진/첨부(사진·PDF·Excel·HWP 등, 최대 10건 — `vehicle_photos`) |
| `매입` | 매입일, 매입담당자, 구입처, 소유자, 매입가, 매도비, **비용 9개** (말소/면허/탁송/캐리/쇼링/보험/이전비/기타1/기타2), 계약금, 잔금 N건, 송금메모 |
| `판매` | 판매일, 통화/환율, 바이어/컨사이니, 판매가, TAX D/C, Commission, 운임비, 입금현황(계약금 + 중도금 + 잔금 N건 + 선수금1/2 + 적립금 사용) |
| `수출통관` | 통관 바이어/컨사이니, 포워딩사, **면장금액(USD)**, 선적일, 도착일자(ETA), RORO/CONTAINER, Port of Loading, 수출신고서 업로드 |
| `선적(B/L)` | 선적 바이어/컨사이니, B/L번호, 컨테이너 No, 반입지, VSL, B/L 문서 업로드 |
| `DHL` | 수취인 정보, 발송인 정보, 중량/크기, DHL 발송신청 체크 |
| `서류` | 말소신청서 / 계약서 / 등록증신청서 / 양도증명서 / Invoice / RO·con CIPL 자동 생성 |

**탭 전환 패턴**: Alpine `x-data="{ tab: 'basic' }"` + `x-show="tab === 'basic'"` (Volt `wire:model` 아님 — 탭 전환 시 서버 라운드트립 불필요).

**섹션 그룹핑**: 탭 내부에서 `.section-header` + `.section-dot` + `.section-divider`로 의미 단위 구분 (§10 참조).

## 4. 잔금 동적 N건 패턴

판매 잔금(`final_payments`) / 매입 잔금(`purchase_balance_payments`)은 1차량 N건 모델. Livewire 동적 추가/삭제.

```php
public array $finalPayments = [];   // [['id' => null, 'amount' => 0, 'payment_date' => '', 'note' => ''], ...]

public function addFinalPayment(): void
{
    $this->finalPayments[] = ['id' => null, 'amount' => 0, 'payment_date' => null, 'note' => ''];
}

public function removeFinalPayment(int $idx): void
{
    unset($this->finalPayments[$idx]);
    $this->finalPayments = array_values($this->finalPayments);
}
```

**저장 전략 (id-diff 권장)**:
- `id` 있는 행 → update
- `id` 없는 행 → insert
- 원본에 있고 폼에 없는 id → delete
- 트랜잭션으로 감싸기. truncate-and-reinsert는 FK·created_at 손실 위험

## 5. 정산 마진 computed 패턴 (엑셀 v2 — 2026-05-21 재구조)

마진은 `settlements` 테이블에 **저장하지 않고 PHP property로 매번 계산**. 환율·매입가 변경 시 갱신 로직 불필요.

> 🔀 **2026-08-06 (jin) — 정산환율 전환.** 아래 판매금원화의 환율이 `exchange_rate`(판매환율) 에서
> `settlement_exchange_rate`(**실효 입금환율**) 로 바뀌었다. 상세 = `CLAUDE.md` 「정산 마진 공식」 상단 박스.
> 요점 3줄: ①환차가 2차 1:1 가산 → **1차 마진공식 통과**로 이동(`actual_payout` 의 환차 가산 제거).
> ②**미완납이면 판매환율 폴백**(안 하면 원금 미수가 환율로 둔갑). ③운임비 환차는 회사 몫(정산 base 밖).

```php
// 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × 정산환율
//   ※ 정산환율 = Vehicle::settlement_exchange_rate (완납 외화 = 실입금KRW ÷ 총판매가, 그 외 = 판매환율)
//   ※ 면장(export_declaration_amount)은 매출 검증용. 정산 공식엔 미포함 (엑셀 AH = SUM(AJ+AM+AN-AO)×AL)
public function getSalesAmountKrwAttribute(): int
{
    $v = $this->vehicle;
    $base = ($v->sale_price ?? 0) + ($v->commission ?? 0) + ($v->auto_loading ?? 0) - ($v->tax_dc ?? 0);
    return (int) ($base * $v->settlement_exchange_rate);
}

public function getSettlementSalesKrwAttribute(): int
{
    return $this->sales_amount_krw - $this->vehicle->cost_total;
}

// 판매마진 = 정산판매금원화 - (purchase_price + selling_fee) — 매입합계 기준 (엑셀 CF = CE - CB, CB = T+U)
public function getSalesMarginAttribute(): int
{
    return $this->settlement_sales_krw - ($this->vehicle->purchase_price + $this->vehicle->selling_fee);
}

// 부가세마진 = purchase_price × 0.09 (매도비 제외, 엑셀 CG = T × 0.09)
public function getVatMarginAttribute(): int
{
    return (int) ($this->vehicle->purchase_price * 0.09);
}

// 총마진 = (판매마진 + 부가세마진) × 0.9 — × 0.9 = 부가세 10% 차감 (엑셀 CH)
public function getTotalMarginAttribute(): int
{
    return (int) (($this->sales_margin + $this->vat_margin) * 0.9);
}

// 정산액 — type 별 분기 + NULL fallback 자동 default
public const FREELANCE_RATIO_DEFAULT = 50;
public const EMPLOYEE_PER_UNIT_DEFAULT = 100_000;
public const FREELANCE_DOCUMENT_FEE = 50_000;

public function getSettlementAmountAttribute(): int
{
    if ($this->settlement_type === 'ratio') {
        $ratio = $this->settlement_ratio ?? self::FREELANCE_RATIO_DEFAULT;
        return (int) ($this->total_margin * ($ratio / 100));
    }
    return $this->per_unit_amount ?? self::EMPLOYEE_PER_UNIT_DEFAULT;
}

// 실지급액 = 정산액 - 서류비 - 기타공제. 서류비는 프리랜서만 5만원 (엑셀 CJ = CH/2 - 50000)
public function getActualPayoutAttribute(): int
{
    $documentFee = $this->settlement_type === 'ratio' ? self::FREELANCE_DOCUMENT_FEE : 0;
    return $this->settlement_amount - $documentFee - ($this->other_deduction ?? 0);
}
```

**자동 default 채움**: `Vehicle::saved` 거래완료 진입 시 `Salesman.type` 보고 `settlement_ratio=50`(프리랜서) 또는 `per_unit_amount=100000`(사내직원) DB 컬럼에 자동 저장. 재무가 override 필요 시 명시 입력 → H3 가드(confirmed/paid 전환 시 값>0) 자연 통과.

### 5-2. 1차 정산 흐름 (settlement_status)

```
거래완료 (Vehicle::saved 훅)
  → settlement_status='pending'  (자동 생성)
  → settlement_status='confirmed' (재무 확정)
  → settlement_status='paid'      (관리/admin 지급 — 재무는 직접 paid 불가, 승인 요청 흐름)
```

**paid 전환 시 자동 트리거** (`Settlement::saving` 훅):
- `confirmed_snapshot` 캡처 (paid 시점 회계 상태 영구 보존 — Gemini Lock)
- `secondary_status='pending'` 자동 set → 2차 정산 대기 시작
- `canApprove` 가드: 재무는 직접 못 함 → `ApprovalRequest` 흐름

### 5-3. 2차 정산 흐름 (secondary_status — 2026-05-22 회의확장씬 #8)

paid 후 한 달 간 secondary='pending' 상태 유지 — 실제 측정된 비용 보정 + 환차 정정용:

```
paid → secondary='pending' (자동, 한 달 대기)
  → 차량 비용 9개 수정 (말소비·면허비·탁송·쇼링·보험·이전비·기타1·2 실측치)
  → 정산 시점 환율 입력 (또는 ExchangeRateService 자동 fetch)
  → "2차 완료" 버튼 클릭 (재무/관리/admin 권한, closeSecondarySettlement)
  → secondary='closed' (회계 잠금)
```

**closed 시점 자동 계산 (2가지 값)**:

> 🔀 **2026-08-06 (jin)** — ①환차는 계속 계산·저장되지만 **지급액에 가산하지 않는다**(1차에 이미 반영, §5-4 박스).
> 실현 환차 총액의 감사·참고 기록이다. ②이월(carryover)이 넘기는 것은 이제 **명세서 기입(비용 9개) 변동분**이다.

```php
// ① 환차 (exchange_difference_krw)
//    환차 = (2차 정산 환율 × Σ외화입금) - 입금 시점 누적 KRW
//    KRW 차량 → 0 / ExchangeRateService 실패 → null
calculateExchangeDifference($settlement) → [$exchangeDiff, $usedRate]

// ② 이월 (carryover_out_krw)
//    carry_out = closed actual_payout - paid snapshot.actual_payout
//    closed 시점에 cost·환차 모두 반영된 실지급액 vs paid 시점 snapshot 차이
$carryoverOut = $closedPayout - (int) ($settlement->confirmed_snapshot['actual_payout'] ?? 0);
```

### 5-4. 환차 반영 정책 — 영업담당자 타입별 (중요)

> 🔀 **2026-08-06 (jin) 개편으로 아래 원문은 폐기됐다.** `getActualPayoutAttribute` 의 환차 1:1 가산
> 블록은 **제거**됐고, 환차는 판매금원화의 환율(`Vehicle::settlement_exchange_rate`)을 통해 **1차 정산**에
> 들어간다. 결과적으로 표의 결론(프리랜서만 환차를 가져가고 사내직원은 회사 부담)은 **그대로 유지**되지만
> 경로가 다르다 — 사내직원은 "제외 분기"가 아니라 **정산액이 총마진과 무관해서** 자동으로 안 움직인다.
> ⚠️ 배율도 다르다: 구 = 환차 전액(운임비 포함) 1:1 / 신 = 정산 base(운임비 제외) × 0.9 × 비율.
> 아래 코드는 **역사 기록**이다. 현행은 `CLAUDE.md` 「정산 마진 공식」 상단 박스를 볼 것.

```php
// Settlement::getActualPayoutAttribute
$base = $this->settlement_amount - $this->document_fee - $this->other_deduction;

// 환차 반영 — 프리랜서(ratio) 만 본인 정산에 +/- 반영
if ($this->settlement_type === 'ratio'           // ← 사내직원(per_unit) 제외
    && $this->secondary_status === 'closed'
    && $this->exchange_difference_krw !== null) {
    $base += (int) $this->exchange_difference_krw;
}

// 이월 흡수 — type 무관 (사내직원도 받는 carry_in은 적용)
if ($this->carryover_in_krw !== null) {
    $base += (int) $this->carryover_in_krw;
}
```

| 정산 유형 | 환차 화면 표시 | 본인 실지급에 환차 반영? | UI 라벨 |
|---|---|---|---|
| 프리랜서 (ratio) | ✅ | ✅ +/- 반영 | "프리랜서(비율제) 정산금에 환차 1:1 가산됨" |
| 사내직원 (per_unit) | ✅ (정보 제공) | ❌ **회사 부담** | **"미반영"** |

**정책 근거** (사용자 운영 정책):
- 프리랜서: 비율(50%) 정산이라 환율 변동도 비율로 부담 → 환차 본인 몫
- 사내직원: 건당 고정(10만원)이라 안정적 수입 보장 → 환율 변동 회사 흡수

### 5-5. 이월(carryover) 동작 — 영업담당자별 이월 (2026-05-23 회의확장씬 #8 보강)

```php
// Settlement::creating 훅 — 같은 영업담당자의 미적용 이월 자동 흡수
static::creating(function (Settlement $s) {
    if ($s->carryover_in_krw !== null || ! $s->salesman_id) {
        return;
    }
    $totalOut = (float) self::where('salesman_id', $s->salesman_id)
        ->where('secondary_status', 'closed')
        ->whereNotNull('carryover_out_krw')
        ->sum('carryover_out_krw');
    $totalIn = (float) self::where('salesman_id', $s->salesman_id)
        ->whereNotNull('carryover_in_krw')
        ->sum('carryover_in_krw');
    $unconsumed = $totalOut - $totalIn;
    if (abs($unconsumed) >= 0.01) {
        $s->carryover_in_krw = $unconsumed;
    }
});
```

**핵심 규칙**:
- 같은 `salesman_id` 기준 (다른 영업담당자 이월 안 흡수)
- closed된 정산의 `carryover_out_krw` 누적 합 - 이미 흡수된 `carryover_in_krw` 누적 합 = 미적용 잔액
- 다음 정산 creating 시 자동 흡수
- **사내직원의 carry_out은 항상 0** (per_unit 고정 + 환차 미반영이라 closed actual_payout = paid snapshot)
- 음수 이월(환차손) 허용 — 실지급 차감

### 5-6. 회계 무결성 lock (큐 20-D + Gemini Lock)

> ⚠️ **2026-07-24 정산 락 개편 — 락 트리거 변경 (jin).** 아래 "`confirmed_at SET` 후 차단"·차량 회계컬럼 락은 이제 트리거가 **"2차 정산 마감(`secondary_status='closed'`) 후"**로 바뀌었다. **confirmed 잔금만으론 더 이상 안 잠긴다** — 마감 전엔 매입가·판매가·환율·비용·잔금 자유 수정(전부 감사 로그), 정산이 carryover 로 +/− 흡수. 정산 없이 confirmed 잔금만 있는 차량(딜러 대금 지급한 재고차 등)은 완전 편집 가능. 단일 트리거 `Vehicle::hasClosedSecondarySettlement()`. **잠금 해제 UI는 정산 화면 closed 행 [🔓 회계 재조정]으로 이동**(차량 패널 🔓 제거). 유지: snapshot 박제·`Settlement::deleting` 차단·per_unit 동결·`transfer_id`/`confirmed_at` 구조적 차단·차량 삭제 가드(confirmed). ⚠️ 함정 = post-close 재조정은 **record-only**(지급 재정산 안 됨, 3차 없음). **상세 = 메모리 `project_settlement_lock_redesign`.** (아래 원문은 개편 전 서술 — 트리거만 closed 로 치환해 읽을 것.)

paid 전환 시 `confirmed_snapshot` 캡처되는 항목들 — 사후 retroactive 변경 차단:
- vehicle 회계 컬럼 (exchange_rate·purchase_price·cost_total 등)
- 마진 (sales_margin·vat_margin·total_margin)
- 정산 결과 (settlement_amount·actual_payout)
- **confirmed FP/PBP rows** (Gemini Lock — 잔금 상태도 함께 캡처해서 회계감사 추적 가능)

`Settlement::booted()` + `FinalPayment::booted()` updating/deleting 가드:
- `confirmed_at SET` 후 amount/payment_date/transfer_id 변경 차단
- 우회 플래그 `$allowConfirmedMutation` (정산 처리 화면에서 4항목 입력 시점에만 try/finally 패턴)

**목록에서 정산액 정렬 시**: computed 컬럼은 SQL `ORDER BY` 불가. 방법 2가지:
- **컬렉션 정렬**: 페이지당 결과셋만 `sortBy()` (소량)
- **subquery select**: `select_raw('(...) as total_margin_calc')` (대량 — 단, SQL에 공식을 박아야 함 — 동기화 위험)

## 6. SavingsStatus 통화별 잔액 자동 계산

`savings_statuses.balance`는 **저장 시점의 (바이어×통화) 잔액 스냅샷**. 거래 추가 시 service에서 직전 행 balance 조회 후 가감.

```
EARNED / REFUND       → balance += savings
USED                  → balance -= savings  (음수 검증)
ADJUSTMENT / CANCELLED → balance += savings  (savings 양/음수 모두 가능)
```

**잔액 음수 불가**: DB CHECK constraint + service 검증 이중. 동시성 race condition 대비 `Buyer::lockForUpdate()` + 트랜잭션.

**원본 거래 참조**: `original_transaction_id` (self FK) — 수정/취소 시 원본 추적용.

## 7. 사이드바 레이아웃

전역 레이아웃은 **단일 사이드바**. 파일: `resources/views/components/layouts/app/sidebar.blade.php`.

**반응형 구조** (md 768px 분기):
- **데스크탑(md+)**: `<aside class="app-sidebar sticky top-0 h-screen">` 220px ↔ 48px 전환, transition `width 0.22s ease`. **`sticky top-0 h-screen` 필수** — 없으면 nav `overflow-y-auto` 작동 안 해서 하단 설정/로그아웃이 viewport 밖으로 밀림
- **모바일(<md)**: `class="sidebar-mobile"` (fixed left-0 top-0 width:240px height:100dvh z-50) + `.sidebar-backdrop` (rgba(0,0,0,.45) z-40) + `sidebar-enter-*` translateX 트랜지션 (`app.css`)

**Alpine 상태 (3개)**:
```js
{
  open: localStorage('sidebar-open') !== 'false',  // 데스크탑 펼침/접힘
  mobileOpen: false,                                // 모바일 drawer 열림
  isMobile: window.innerWidth < 768,                // matchMedia(767px)로 갱신
  init() { matchMedia 구독, 데스크탑 복귀 시 mobileOpen=false },
  toggle() { isMobile ? mobileOpen : open },
  closeMobile() { mobileOpen = false },
}
```

**조건부 렌더링 패턴**:
- `:class="isMobile ? 'sidebar-mobile' : 'sticky top-0 h-screen'"`
- `:style="isMobile ? '' : ('width: ' + (open ? '220px' : '48px'))"`
- 펼침 표시 = `(isMobile || open)` / 접힘 아이콘 모드 = `(!isMobile && !open)`
- 모든 링크에 `@click="if(isMobile) closeMobile()"` (drawer 자동 닫힘)
- 메뉴는 PHP `$menuGroups` 배열 기반 foreach (그룹: ERP / 정산 / 관리)

**ERP 권한 조건 (`show`)**:
- ERP 그룹 → `$user->canAccessErp()`
- 정산 메뉴 → role∈{전체,정산} 또는 admin
- 관리 그룹 → `$user->canAccessAdmin()`
- 기능 설정 항목 → `$user->isSuperAdmin()`

## 8. 자주 발생한 버그와 해결방법

### 1. "Multiple root elements" 에러
**원인**: Volt에서 `<x-layouts.app>` + `@volt` 래핑
**해결**: `#[Layout('components.layouts.app')]` 속성 사용, Blade는 `<div>` 단일 루트

### 2. 마이그레이션 순서 에러 (Foreign key constraint)
**원인**: 동일 타임스탬프 마이그레이션이 알파벳 순 실행되어 참조 테이블 부재
**해결**: 파일명 타임스탬프 수동 조정. ERP는 FK 의존성 깊음 — `countries → buyers → consignees / savings_statuses → forwardings → salesmen → vehicles → final_payments / purchase_balance_payments / settlements` 순서 유지

### 3. 날짜 필드 초기화 버그
**원인**: `?: null`이 빈 문자열(`''`)을 falsy로 판단해 값 있어도 null 덮어씀
**해결**: `$toDate = fn (string $v): ?string => $v !== '' ? $v : null;` 헬퍼 사용

### 4. enum 컬럼 제약
**해결**: 동적 추가 가능성이 있는 항목은 `string` + 공통코드 테이블 연동. ERP의 `currency / sales_channel / shipping_method / freight_payment_type / settlement_type / settlement_status` 등은 enum 사용 OK (값 변경 빈도 낮음)

### 5. Livewire navigate와 행 클릭
**원인**: `wire:navigate`는 `<a>` 전용, `<tr>` 클릭 사용 불가
**해결**: `@click="Livewire.navigate('URL')"` Alpine.js 방식

### 6. 로그인은 되지만 대시보드 접근 불가
**원인**: `email_verified_at`이 NULL이면 `verified` 미들웨어 차단
**해결**: 사용자 생성 시 `'email_verified_at' => now()` 필수. Seeder도 동일

> ⚠️ **2026-07-30 실측 정정 — 지금은 `verified` 가 아무것도 안 막는다.** Laravel `EnsureEmailIsVerified` 는
> `$user instanceof MustVerifyEmail` 일 때만 차단하는데 `App\Models\User` 는 그 인터페이스를 **구현하지 않는다**.
> 실측: `email_verified_at=NULL` 인 admin 이 `/admin/dashboard` 에서 **200**. 위 증상은 과거 구성 기준이다.
> → `email_verified_at => now()` 관행은 그대로 둬도 무해하지만(향후 인터페이스 도입 대비),
> **이걸 접근 차단 수단으로 믿지 말 것.** 실제 게이트는 `permission` + `role` 미들웨어(`erp`/`admin`/…)뿐이다.

### 7. 다른 PC에서 접속 불가
**해결**: `php artisan serve --host=0.0.0.0 --port=8001`

### 8. Carbon 객체 배열 저장 시 메모리 초과 (500)
**해결**: `$start->format('Y-m-d')` 문자열 변환 후 저장. Carbon은 계산용으로만

### 9. Eloquent cast와 DB raw SELECT 별칭 충돌
**원인**: 모델에 `'sale_date' => 'date'` cast가 있으면 `DB::raw("DATE_FORMAT(sale_date, '%Y-%m-%d') as sale_date")` 결과가 다시 Carbon으로 변환되어 `T00:00:00.000000Z` 붙음
**해결**: DB raw 별칭을 다른 이름으로 (예: `sale_date_fmt`)

### 10. Livewire Cache::remember closure에서 변수 미접근
**해결**: closure 밖에서 변수 할당 후 `use()`로 전달:
```php
$startDate = $request->input('start');
Cache::remember($key, 300, function () use ($startDate) { ... });
```

### 11. Eloquent `create()`로 `created_at` 설정 불가
**원인**: Eloquent가 자동 `now()`. `fillable`에 없으면 무시
**해결**: `create()` 직후 raw update:
```php
$v = Vehicle::create([...]);
Vehicle::where('id', $v->id)->update(['created_at' => '2026-04-10 10:00:00']);
```

### 12. Blade `@json()` 내부 배열 + ternary 파싱 에러
**원인**: `@json(cond ? [...] : [])`는 Blade 컴파일러가 ternary 괄호 매칭 실패
**해결**: `@php` 블록 변수 할당 후 `json_encode`:
```blade
@php $list = $condition ? [['key' => 'a']] : []; @endphp
jsArray: {!! json_encode($list, JSON_UNESCAPED_UNICODE) !!},
```

### 13. `<x-layouts.app>` 슬롯 내부 `@php` 블록에서 `use` 문 파싱 에러
**원인**: Blade가 익명 컴포넌트 슬롯을 if/elseif 컨텍스트로 래핑. `use` 선언 시 `syntax error, unexpected token "use"` 500
**해결**: 슬롯 내 `@php`에서는 `use` 금지. FQN 직접 호출:
```blade
{{-- ❌ 500 에러 --}}
<x-layouts.app>
    @php use App\Models\Vehicle; $count = Vehicle::count(); @endphp
</x-layouts.app>

{{-- ✅ 올바른 예 --}}
<x-layouts.app>
    @php $count = \App\Models\Vehicle::query()->count(); @endphp
</x-layouts.app>
```

### 14. SortableJS + `<a>` 태그 HTML5 드래그 충돌
**원인**: `<a>` 기본 `draggable="true"`가 SortableJS보다 먼저 트리거
**해결**: 드래그 카드 `<a>`에 `draggable="false"` 명시

### 15. Chart.js x-show 숨겨진 canvas 0x0
**원인**: `x-show`로 숨겨진 canvas에서 `new Chart()` 호출 시 width=0
**해결**: 카테고리 전환 후 `this.$nextTick(() => this.renderCharts())`. 재진입 시 기존 인스턴스 `destroy()` 필수

> #16~#18 (dompdf 한글 PDF 3건) — 서류 시스템이 xlsx 자동기입(§12)으로 전환되어 폐기. archive `SKILLS.md.full` 보존.

### 19. xlsm → xlsx 시트 추출 시 외부 참조 `definedName` 잔재로 파일 손상
**원인**: 원본 xlsm이 다른 워크북(`[1]입출고`, `[7]수출현황_종합` 등)과 공유 데이터로 연결돼 있을 때, 시트만 분리해도 워크북 레벨 `<definedName>` 50+개가 그대로 따라옴 (`#REF!` 또는 외부 참조 깨짐). Excel은 이걸 만나면 "복구할 수 없는 콘텐츠"로 판단 → 파일 열기 실패
**해결**: 추출 시 `wb.defined_names = DefinedNameDict()` + `ws.defined_names = DefinedNameDict()` (openpyxl). 표준 Print_Titles / Print_Area 2개만 남기는 게 안전

### 20. PhpSpreadsheet ZipArchive 의존 — php.ini extension=zip 필수
**원인**: .xlsx는 zip 컨테이너. PhpSpreadsheet가 압축 풀 때 `ZipArchive` 클래스 부름. XAMPP 기본 php.ini에서 `;extension=zip`은 **주석 처리 상태**라 활성화 필요. `extension=gd`도 PhpSpreadsheet 권장 의존성 (이미지 렌더링)
**해결**:
```ini
# C:\xampp\php\php.ini
extension=gd     # 주석 제거
extension=zip    # 주석 제거
```
설정 변경 후 **PHP 프로세스 재시작 필수** — `php artisan serve`는 Ctrl+C 후 재기동, Apache는 XAMPP Control Panel에서 Stop/Start. CLI는 즉시 반영되지만 떠있던 웹 서버는 옛 ini를 들고 있음 → "Class ZipArchive not found" 에러 그대로 발생

### 21. wire:navigate 후 JS 호버/툴팁 죽음 (게이지 hover, 2026-06-04)
**원인**: 차량 미납 게이지 hover를 행마다 `addEventListener` + `dataset.gaugeBound` 가드로 1회 바인딩 → `wire:navigate`(SPA)가 페이지를 캐시·복원할 때 **리스너는 유실되는데 가드 속성은 복원돼 남아** 재바인딩 스킵 → hover 죽음(전체 새로고침해야 동작). 더해서 툴팁 div를 `document.body`에 append했는데 navigate가 `<body>`를 교체하면 **그 div가 제거**돼 변수는 detached 옛 요소를 가리킴(리스너는 동작해도 툴팁 안 보임).
**해결**: ① hover를 `document` **이벤트 위임**(mouseover/out + `closest('tr[data-ratio]')`)으로 — `document`는 navigate로 안 바뀌어 리스너 유지, 페이지네이션·morph로 행이 새로 생겨도 자동 적용. ② `ensureTooltip()`에서 `tooltipEl.isConnected` 확인 → 분리됐으면 재생성. ③ 배경 inline-style 게이지는 `livewire:navigated`·`morph.updated`마다 재적용. (`resources/js/app.js`). **교훈: wire:navigate 환경에선 per-element 리스너 + DOM 캐시 복원이 충돌 — 안정 요소(document) 위임 + body-append 요소는 isConnected 재생성.**

### 22. pint를 .blade.php에 돌리면 Volt 클래스 대량 reformat + 깨짐 (2026-06-04)
**원인**: `vendor/bin/pint <파일.blade.php>` 실행 시 PHP-CS-Fixer가 Volt 단일파일의 `<?php ?>` 클래스를 전면 재배치 → 실측 `vehicles/index.blade.php`에 **1356줄 변경(783+/573−) + 테스트 깨짐**. 이 프로젝트 blade는 pint 스타일이 아님 = 팀이 blade에 pint 안 돌림(pint.json 없음=기본).
**해결**: blade 변경은 pint 제외하고 커밋. 실수로 돌렸으면 `git checkout -- <blade>`로 pint분 되돌리고 기능 수정만 재적용. `.php`만 `pint --dirty`. (CLAUDE.md 핵심주의 #5)

### 23. 매입 자동 PBP Draft — ⚠️ **폐기됨 (jin 2026-07-03)**
> **자동 PBP Draft 생성 제거**: `Vehicle::saved` 훅이 매입가 저장 시 만들던 **전액·confirmed_at=NULL 자동 잔금 Draft**를 없앴다. 이유 = 단순 저장(매입가/매도비 입력)이 **재무처리 큐로 자동 유입**되는 게 board 연동·실무상 부담(더 복잡). 이제 **단순 저장은 PBP 0건** — 매입 미지급은 accessor(`getPurchaseUnpaidAmountAttribute`, 확정 PBP 기준 §13)로 대시보드 매입 미지급 KPI·매입 미지급 요약 박스에 그대로 노출되고, **재무는 실제 지급 시 transfers 매입 잔금 탭 '신규 입력'(`createNewPbp`, 즉시확정)으로 직접 기록**한다. 훅엔 매입일 변경 시 미확정 PBP `payment_date` 동기화만 남김.
> - `AUTO_DRAFT_NOTE` 상수 + `vehicles/index::save()`의 reconcile 가드(`->where('note', AUTO_DRAFT_NOTE)->first()`, null-safe no-op)는 **운영 레거시 Draft 대비 존치**(과거 생성분이 확정 입금과 대조돼 정리됨). ⚠️ 운영 DB의 기존 auto-Draft 는 코드 제거로 안 사라짐 — 정리는 별건(미확정만 삭제 안전, 확정은 실지급 기록이라 금지).
> - 테스트: `AutoPbpDraftReconcileTest`(확정만·단순저장 0건·부분확정) + `WorkflowGapTest`(22c no-auto-draft·payment_date sync).
>
> (구 기록 — phantom 중복 fix 2026-06-01) 자동 Draft 존재 시절, 같은 저장에서 확정 행 추가 시 자동 Draft가 중복 잔존 → 이중 계상하던 버그를 reconcile로 해소했었음. 자동 Draft 폐기로 근본 원인 소멸.

### 24. 판매당사자 자동전파 × C5/C4 게이트 회귀 (2026-06-01)
**원인**: 판매 바이어+컨사이니 둘 다 지정 시 `propagateSaleParty()`가 통관(export) 당사자까지 자동 전파했는데, **`export_buyer_id`는 `guardStageOrderForExport`의 `$hasExportInput`(통관 진입 신호)** 이라 — 판매 시점 자동 채움이 <50% 입금 차량의 판매 저장을 C5로 통째 차단(`ManagementWorkflowChecklistTest:375`가 export_buyer_id 단독으로 C4 발동 검증).
**해결**: 자동전파에서 **통관(export) 당사자 제거**, B/L 당사자(bl_buyer_id — 게이트 트리거 아님)만 전파 유지. 통관 바이어는 실제 통관 단계에서 입력. **교훈: export_buyer_id에 값 넣는 건 "통관 진입"으로 간주됨 — 판매 단계에서 자동으로 채우지 말 것.**

### 25. chk_sale_required — sale_price>0이면 sale_date·exchange_rate>0 필수 (MySQL CHECK)

> 🔧 **2026-08-18 정정 — 이 문서가 틀렸었다.** 원문에 `buyer_id NOT NULL` 이 포함된다고 적혀 있었으나
> **운영 실제 제약에는 없다.** 3사 `information_schema.CHECK_CONSTRAINTS` 실측:
> ```
> chk_sale_required :: ((`sale_price` = 0) or ((`sale_date` is not null) and (`exchange_rate` > 0)))
> ```
> ⇒ **DB 는 바이어 없는 판매를 막지 않는다.** 실측 시점 위반 행은 0건이라 사고는 없었지만,
> "CHECK 가 바이어를 보장한다"고 믿고 코드를 짜면 **바이어 공란 서류가 조용히 나간다**
> (board 서류 API 가 정확히 그 상태였다 → #60). **바이어 필수는 애플리케이션이 직접 검사해야 한다.**

**원인**: 운영 MySQL CHECK `chk_sale_required = (sale_price=0 OR (sale_date NOT NULL AND exchange_rate>0))`. 판매가만 넣고 나머지 누락하면 INSERT/UPDATE 실패(4025).
**해결**: 판매가 입력 시 sale_date·환율 항상 동반. (엑셀 일괄적재처럼 sale_date 없으면 선적일/구입일로 대체, 못 채우면 판매가 보류=매입만.)
**🧭 교훈**: DB 제약을 문서에서 인용하지 말고 **`information_schema` 로 실측**할 것. 마이그레이션 파일과
운영 스키마가 갈릴 수 있고(수기 ALTER·롤백 이력), 문서는 더 쉽게 갈린다.

### 26. mutating·열람 엔드포인트 재인가 누락 = IDOR (Review.md #3/#4, 2026-06-09)
**원인**: 스코프 가드를 **읽기 진입(`openEdit`/`mount`)에서 1회만** 걸고, 변경 액션(`delete`/`save`)·문서 다운로드 컨트롤러·변조 가능 public 프로퍼티엔 재인가가 없던 안티패턴. Livewire public 프로퍼티(`editingId`·`$salesmanId`)와 라우트 `{id}`는 클라이언트가 직접 주입 가능 → 영업이 타 담당 차량 삭제/변조, 타인 차량 RRN 박힌 서류 다운로드(URL만 변경), 타 영업 자금현황 열람.
**해결**: ① 스코프 규칙을 **`User::canScopeVehicle($vehicle)` 단일 출처**로(영업=본인/관리=팀/통관·재무·admin=전체). ② `delete`·`save(편집)`·`VehicleDocumentController::show/showMulti` 모두에서 매번 호출. ③ mount 1회 인가에만 의존하는 프로퍼티는 `#[Locked]`. **교훈: 읽기에서 인가했어도 mutating·열람 엔드포인트는 매번 재인가. abort(403)는 Livewire가 예외로 안 던지고 403 응답으로 변환 → 테스트는 `->call(...)->assertStatus(403)`.**

### 27. 회계 잠금(paid) row 본체 미잠금 — deleting 가드 부재 (Review.md #1, 2026-06-09)
**원인**: FP/PBP 소급 잠금이 `settlements()->where('settlement_status','paid')->exists()`에 의존하는데, `Settlement`엔 `deleting` 훅도 `SoftDeletes`도 없어 **paid/confirmed/closed 정산을 1클릭 하드삭제** → 잠금 해제 + `confirmed_snapshot`·감사추적 영구 소멸. (불변식을 잘 만들어도 그 불변식이 의존하는 row 자체가 무방비면 우회됨.)
**해결**: `Settlement::deleting` 가드 — `settlement_status∈{confirmed,paid}` 또는 `secondary_status='closed'`면 `DomainException`(시드·artisan 우회), 컴포넌트는 try/catch로 토스트. 삭제는 pending/calculating만. (2026-05-21 사용자 결정 "삭제 등 파괴적 액션만 별도 차단"의 미반영분.) **SoftDeletes는 글로벌 스코프 영향 커서 보류 — 추후 검토.**

> ERP 신규 발견 버그는 본 §8 하단에 추가 기록 (#28+).

### 47. 🚨 파일 쓰기는 **실패해도 예외를 안 던진다** — 반환값을 안 보면 DB만 확정된다 (2026-08-10)

**원인**: `config/filesystems.php` 의 모든 디스크가 `'throw' => false` 라, `store()`·`storeAs()`·`put()`·`copy()`·`writeStream()` 이 실패해도 **예외가 아니라 `false`** 를 리턴한다. `try/catch` 는 **방어가 안 된다**(던지는 부류만 잡는다). 반환값을 검사하지 않으면 그대로 다음 줄로 흘러가 **DB만 확정**된다.
**실측 사고**: 연동 B 첨부가 "`VehiclePhoto` 15건 / S3 객체 0건" 으로 쌓여 있었다. **한 번도 성공한 적이 없었다.** 게다가 dedup 가드(`where('path',$target)->exists()`)가 재전송을 걸러 **영구히 자가복구가 안 됐다**.
**같은 형태가 8곳 더 있었다** — 전수 조사로 찾았다:
- 🔴 **기존 데이터까지 잃는 것**: 차량 서류·입금 증빙 교체는 `false` 를 경로에 넣고 `$old !== $new` 가 참이라 **멀쩡하던 옛 파일을 삭제 목록에 올린다**. 도장은 **옛 도장을 먼저 지운 뒤** 저장해 실패 시 도장이 0개(3사 전 서류 영향).
- 🟠 **복구 경로가 없는 것**: 전자서명은 파일 없는 `status=SIGNED` 를 확정해 영구 404 + 하드삭제 가드라 자가복구 불가. 서명 세션은 저장 실패 후 `revokeOverlappingActive()` 가 **기존에 잘 되던 링크를 폐기**.
- 🟡 DB 백업은 업로드 실패인데 `✓ 원격 업로드` 를 찍고 성공 종료.
**해결**: 반환값 검사 + **복사 후 `exists()` 재확인**. 삭제는 **저장 성공 후에만**. 공용 예외 `App\Exceptions\FileStoreFailedException`.
**🔒 가드 = `tests/Feature/FileWriteResultCheckedTest`**(정적). ⚠️ **`Storage::fake()` 는 로컬 드라이버라 쓰기가 늘 성공한다 — 기능 테스트로는 원리상 못 잡는다.** 도장은 저장·삭제 **순서**까지 검사한다.
**🧭 새 파일 쓰기를 추가할 때마다**: ①반환값을 보는가 ②실패 시 DB를 확정하지 않는가 ③옛 파일을 **먼저 지우지 않는가**.

### 48. 🪣 Flysystem S3 `copy()` 는 **원본 ACL을 먼저 조회**한다 — ACL 비활성 버킷에서 전멸 (2026-08-10)

**원인**: `retain_visibility` 기본값이 `true` 라 `copy()` 마다 `GetObjectAcl` 을 부른다. 우리 버킷은 ACL 이 비활성(Bucket owner enforced)이라 **그 조회가 항상 실패**하고, `UnableToCopyFile` 로 바뀌어 **복사가 시작조차 못 한다**. Laravel 은 **Cloudflare R2 일 때만** 이 값을 자동으로 내린다(`FilesystemManager`) — ACL 비활성 AWS 버킷은 직접 꺼야 한다.
**증상**: `put`(일반 업로드)은 ACL 을 안 봐서 **멀쩡하다**(`vehicles/**` 1451건 정상). **`copy`만** 죽는다 → "업로드는 되는데 복사만 안 되는" 형태로 위장된다.
**진단법(읽기 전용)**: `Storage::disk('s3')->getVisibility($path)` 를 불러본다. 실패하면 이 건이다. 원본·기존 객체 **양쪽 다** 실패하는지 보면 확실하다.
**해결**: `config/filesystems.php` s3 에 `'retain_visibility' => false`.
⚠️ `'throw' => false` 와 겹치면 **예외도 없이 `false` 만** 리턴돼 아무도 모른다(#47 과 세트로 터진다).

### 49. 🧾 계산의 **대상**과 **소진 판정**을 헷갈리면 기능이 통째로 무의미해진다 (2026-08-10 무담보 한도)

무담보 한도(계약금 전용, 500만 규모)를 만들며 **세 번 갈아엎었다**. 매번 "무엇을 세는가"가 틀렸다:
1. 총한도(보증금+무담보) − 총사용액 → 보증금을 이미 초과한 바이어는 **설정하자마자 0**
2. 미수율에 걸렸을 때만 차감 → **같은 금액인데 미수율에 따라 결과가 갈려** 예측 불가("헷갈린다")
3. 보증금 초과분 기준 → **차값 전체(1,300만)가 초과분에 들어가** 500만 한도가 한 번에 증발
4. ✅ **계약금만 담는 독립 주머니** — 보증금 계산과 분리
**교훈**: 새 한도를 기존 한도와 **연결하기 전에 규모를 실측**할 것. 계약금은 운영 78건 중 **67건(86%)이 100만 이하**인데 보증금은 수천만이다. 규모가 다른 둘을 섞으면 작은 쪽이 의미를 잃는다.
**🧭 돈의 출처를 모르면 자동 판정하지 말 것** — "계약금을 넣으면 자동 차감"으로 만들었다가 jin 지적으로 되돌렸다(*"이거 실제로 50만원이 누구 돈일 줄 알고?"*). 계약금 행만으로는 바이어가 보낸 것인지 회사가 대신 낸 것인지 **알 수 없다**. 사람이 체크로 명시한다. ⚠️ 그리고 **그 체크는 회수 전에 못 풀게** 막아야 한다 — 풀면 한도만 돌아와 락을 그대로 우회한다.

### 50. 🎨 새 색 클래스는 **빌드된 CSS에 있는지 먼저 확인** (2026-08-10, 한 세션에 2회 반복)

Tailwind 는 소스에 등장한 클래스만 CSS 로 굽는다. 빌드에 없는 색을 쓰면 **배경이 안 깔리고 회색/흰색으로만** 보인다 — 색을 잘못 고른 게 아니라 **CSS 자체가 없는 것**이다.
- 운항 필터 pill: `bg-sky-600`·`bg-teal-600` 없음 → 눌러도 흰색(jin: "클릭했는지 놓치기 쉽다")
- 무담보 토글: `peer-checked:bg-indigo-500` 없음 → 켜도 회색(jin: "색구분 없어서 보기 어렵네")
**확인법**: `grep -F ".<클래스>" public/build/assets/*.css`. `peer-checked:` 처럼 콜론이 있으면 `\:` 로 이스케이프된 형태로 저장된다.
**해결**: ①기존에 쓰이는 색을 재사용(같은 카드의 다른 토글을 그대로 복사) ②새 색이 꼭 필요하면 `npm run build` 후 **존재를 검증**.
⚠️ `public/build` 는 gitignore 라 배포 시 서버가 다시 굽는다 — **배포하면 반영되지만 로컬에서는 빌드 전까지 안 보인다.** Livewire 갱신은 자산을 다시 안 받으므로 열린 탭은 `Ctrl+Shift+R` 전까지 옛 CSS 로 돈다.


### 51. 🗄️ `settings.value` 는 varchar(255)였다 — JSON 을 넣는 순간 벽에 닿는다 (2026-08-11)

**원인**: `settings` 는 "짧은 스칼라" 가정으로 만들어졌는데, JSON 을 담는 설정 키가 늘면서 그 가정이 깨졌다.
공휴일 한 해치(~700자) 저장이 `SQLSTATE[22001] 1406 Data too long` 으로 죽었다.
**진짜 무서운 건 옆에 있었다** — 알림톡 **수신 시각 규칙**이 기본 3행에 벌써 **196자**였다.
화면에서 [＋규칙 추가]를 **한 번만 더 누르면 저장이 깨지는** 상태였고, 아무도 몰랐다.
**해결**: `settings.value` → **TEXT**(마이그 `2026_08_11_000003`). 인덱스는 `key` 에만 있어 걸림돌 없음.
**🧭 규칙**: `Setting` 에 **JSON·목록·여러 줄 텍스트**를 넣는 새 기능을 만들 때는 **길이를 실측**할 것.
운영 데이터가 커지면 넘는 부류(연도별 목록·규칙 배열)는 특히. 조용히 안 커지고 **예외로 죽는다**.

### 52. ⏱️ `<input type="time">` 은 `24:00` 을 못 받는다 — 값이 **빈칸으로 보인다** (2026-08-11)

**원인**: '종일'을 `00:00~24:00` 으로 저장했는데 HTML time 입력의 최대값은 `23:59` 다. 브라우저가 그 값을
**조용히 버려** 빈칸으로 렌더한다. jin 이 "주말은 오전 9시만 설정돼 있던데?" 로 읽었다 — 설정은 맞았고 화면만 틀렸다.
**해결**: 종일은 시간칸 대신 **칩으로 표시**하고 `24:00` 은 **버튼으로만** 만든다(입력칸에 못 넣는 값이 남지 않게).
**곁다리 교훈 — 시간 범위 UI 3종 세트**: ①자정을 넘긴 구간(`17:30~09:00`)엔 **「익일」을 찍는다**
(안 찍으면 당일/익일 해석이 갈린다) ②규칙 한 줄을 **사람 말로 요약**해 붙인다 ③**「지금 적용」** 표시로
어느 규칙이 살아 있는지 보여준다. 가드 = `AlimtalkCatalogTest`(`value="24:00"` 이 렌더되면 실패).
⚠️ **요일은 "그 시각의 요일"로 판정**된다 — 자정 넘김 구간은 **끝나는 쪽 요일에도** 체크가 있어야 그 새벽이 덮인다.
`AlimtalkRecipients` 는 한 주 10,080분 전수 검사로 빈 구간 0 을 보장한다.

### 53. 🔑 공공 API 키는 **Encoding / Decoding 두 종류** — 어느 쪽이든 그냥 쓰면 실패한다 (2026-08-11)

**원인**: data.go.kr 은 같은 키를 두 형태로 준다. **Encoding 키**(`%3D` 포함)를 쿼리 빌더(Guzzle·`http_build_query`)에
넘기면 **다시 인코딩돼** `%253D` 가 되어 인증 실패. 반대로 **Decoding 키**(`+`·`=` 포함)를 URL 에 그냥 붙이면
`+` 가 **공백**으로 해석돼 또 실패. 어느 쪽을 붙여넣든 사람은 "키가 틀렸나?" 만 반복한다.
**해결**: `%` 유무로 갈라 처리 — 있으면 그대로 붙이고, 없으면 `rawurlencode`(`KoreanHolidayService`).
**곁다리**: 엔드포인트가 **https** 인지 확인할 것(문서 예시는 http 인 경우가 많다).
**🧭 외부 API 를 붙일 때 함께 정할 것**: ①**발송·판정 경로에서 직접 부르지 않는다**(cron 이 받아 저장, 판정은 저장분만 읽음)
②**빈 응답을 성공으로 저장하지 않는다**(0건을 덮으면 그 해 데이터가 통째로 사라진다) ③실패 시 **직전 저장분 유지** + 코드 내장 기본값이 바닥
④**활용기간(만료)** 은 API 가 안 알려준다 — 만료일을 화면에 적고 **D-day** 를 띄운다. 안 그러면 만료 후
**수집만 조용히 멈추고 저장분이 늙는다**(기능은 계속 도는 것처럼 보인다). 상세 = 메모리 `project_holiday_api`.

### 54. 📨 한 템플릿으로 여러 신호를 보내면 **본문 조립에서 갈라야** 한다 (2026-08-11)

**원인**: 알림톡 승인은 템플릿 단위라 board 요청 3종(계약금·매입잔금·판매대금확인)을 **템플릿 하나**로 묶었다
(종류는 `#{구분}`·`#{요청내역}` 변수로 구분). 그런데 본문 조립이 신호 종류를 안 보고 **매입처 계좌를 항상 찍었다**.
판매대금확인은 "돈이 **들어왔으니** 확인해달라"는 신호다 — 거기 송금 계좌가 붙으면 받는 사람이 **거기로 돈을 보낸다**.
방향이 반대라 지저분한 게 아니라 **금전 사고**다.
**해결**: `BoardRequest::TYPE_META['payee']` 로 갈랐다(매입 3종 true / 판매대금확인 false).
**🧭 규칙**: 템플릿을 공유하면 승인 횟수(회사 수 × 신호 수)는 줄지만, **신호별 차이를 본문 조립에서 처리할 책임**이
생긴다. 새 신호를 그 템플릿에 얹을 때 **"이 문장이 이 신호에도 참인가"** 를 항목별로 확인할 것.

#### 54-B. 💸 **돈 얘기를 밖으로 보낼 때 챙길 3가지** (2026-08-12 딜러 입금완료 `erp_purchase_paid`)

계약금·매입잔금 입금완료를 국내 딜러에게 보내며 정리한 것. 위와 같은 「1 템플릿 N 신호」 구조다.

- **① 합계인가 건별인가 — 실측으로 정한다.** 운영 239대 중 **24대가 잔금을 2~3회 분할** 지급한다.
  합계를 보내면 두 번째 알림이 *"추가로 그만큼 더 들어왔다"* 로 읽힌다. 그래서 잔금 버튼은 **행마다** 붙고
  그 줄의 금액만 보낸다(계약금은 실측 78대 전부 1건이라 합계 = 그 1건 → 버튼 하나).
  ⇒ **버튼 개수를 UI 편의로 정하지 말고 "한 번에 몇 건이 생기나" 를 세어 볼 것.**
- **② 계좌번호는 마스킹**(`AccountMask`, 은행 + 뒤 4자리). 수신 번호를 **사람이 손으로 적기** 때문에
  한 자리만 틀려도 남의 계좌가 통째로 나간다. 받는 분은 자기 계좌를 이미 안다.
  ⚠️ `purchase_seller_account` 는 `encrypted` 캐스트 — **쿼리로 직접 뽑으면 암호문이 그대로 나간다.**
  ⚠️ 실측 243대 중 계좌가 채워진 건 67대 → 없으면 `-` 로 낮추고 **발송은 막지 않는다**(금액만으로도 목적 달성).
- **③ 방향을 문장으로 못 박는다.** 「입금받으신 계좌」 — 그냥 「계좌」면 #54 의 그 사고가 재현된다.
  가드 = `PurchasePaidNoticeTest::test_body_states_the_direction_of_money`.
- 🧾 **화면 입력값이 아니라 DB 확정분을 보낸다.** 저장 전 숫자로 "입금했습니다" 를 보내면 거짓 통지다.
  버튼 노출 조건도 발송 액션도 같은 출처(`confirmed_at` 있는 행)를 각자 다시 읽는다.

**🚦 승인과 배포를 분리한다** — 발송 버튼을 `AlimtalkConfig::canSend($code)` 로 감싸면 **tmplId 가 채워질 때
자동으로 켜진다**. 승인 전에 배포해도 «눌러도 안 나가는 버튼» 이 안 생기고(jin: *"괜히 버튼 있..."*),
등록 안 한 회사에선 영영 안 뜬다 — **회사별 분기를 코드에 박을 필요가 없다**. 새 수동 발송 UI 는 이 형태로.

**🛠️ 등록 xlsx 는 이제 스크립트다** — `scripts/alimtalk-build-upload.php <코드> [카테고리]` → 3사 생성,
`scripts/alimtalk-verify-upload.php <코드>` → 본문 글자단위 대조 + 예시행·버튼칸 잔존 검사.
zip 패치 방식의 이유(§12-B 의 두 번 실패)는 스크립트 주석에 박아뒀다. **기본형 전용** — 아이템리스트형은 거부한다.

### 55. 🚢 **출고일과 반입지는 같이 안 움직인다** — 조건을 넓힐 때 실측부터 (2026-08-12)

**상황**: board 선적 계획 후보를 「판매완료」(완납)에서 「판매중까지」로 넓히면서, 인계서가 요구한
*"출고 후 차가 후보로 돌아오면 안 된다"* 를 `whereNull('warehouse_out_date')` 로 구현하려 했다.
**실측이 막았다** — heymanerp 현행 후보 29대 중 **대다수가 이미 출고일이 찍혀 있었다**(진행상태는 판매완료).
그대로 넣었으면 **넓히랬는데 좁아지는** 배포가 됐다. 목록이 비는 게 아니라 **줄어드는** 형태라 눈에도 안 띈다.
**왜 그런가**: 출고일은 "야드에서 나갔다"(돈 관점 pivot, §14)이고 반입지는 "항구에 세웠다"(진행상태 pivot)다.
**두 축이 독립**이라 한쪽만 찍힌 차가 흔하다. 이름이 비슷해서 같은 뜻으로 읽기 쉽다.
**해결**: "이미 떠난 차" 를 **반입지·B/L 유무**로 판정. v4 cascade 상 `판매완료` 는 그 둘이 모두 비어야
도달하므로 **구 후보를 한 대도 안 떨어뜨리는 순수 확대**가 된다.
**🧭 규칙 — 조건을 넓히는 변경엔 「순수 확대」 테스트를 붙인다.** 구 조건이 뽑던 집합을 테스트 안에서 직접
질의해 새 조건에 전부 들어 있는지 단언한다(`BoardShippableScopeTest::test_widening_never_drops_...`).
"넓혔으니 당연히 다 들어있다" 는 착각이고, 실제로는 새로 넣은 배제 조건이 조용히 깎는다.

### 56. 🔍 세션 없는 경로(연동 API)의 감사로그는 **행위자가 빈다** (2026-08-12)

`AuditLog::recordChange` 는 `auth()->id()` 를 쓴다. **HMAC 연동 API 엔 로그인 세션이 없어 늘 null** 이고,
감사 화면은 그걸 「시스템」으로 렌더한다 — **cron 이 바꾼 것과 구분이 안 된다**.
board 가 고른 포워딩사가 정확히 그 경우였다: "관리가 눈치챌 기회는 감사로그가 대신한다" 고 문서에 적어놓고
정작 그 기록에 **사람 이름이 없었다**. 예외도 실패도 없어서 테스트로는 안 잡힌다(행은 정상 생성된다).
**해결**: `AuditLog::actingAs($userId, fn)` — 세션 없는 경로에서 귀속만 명시한다(로그인 아님).
`withApprovalRequest` 와 같은 컨텍스트 모양. 연결 User 가 없으면 null 로 남긴다.
**🧭 규칙**: 연동 API 가 **감사 대상 컬럼**을 쓰면 `actingAs` 로 감쌀 것. 그리고 "추적된다" 고 쓰기 전에
**감사 화면에 뭐라고 찍히는지 실제로 확인**할 것.
⚠️ 곁다리 함정 — 이걸 `DB::transaction(fn () => ...)` 처럼 **arrow fn 으로 감싸면 `&$var` 참조가 끊긴다**
(arrow fn 은 값 캡처). sync 응답의 `cancelled` 가 통째로 비었다. 감쌀 땐 범위를 **실제 쓰기 지점**으로 좁힐 것.

### 57. 🐌 **게이트를 세우면 원래 있던 비용이 대기시간으로 드러난다** (2026-08-12 배포 지연)

**제보**: "배포가 예전보다 엄청 느리다. 3사에 따로 배포해서 그런 것 같다."
**실측이 뒤집었다** — 3개 배포 잡은 **동시에** 돌고 합쳐 **31초**다. 8분 04초 중 **`Run Tests` 가 7분 01초**,
셋업(composer·npm·build)은 27초. 회사를 하나로 줄여도 30초밖에 안 준다.
**진짜 원인 2단**:
1. **07-30 배포 게이트**(`deploy` 가 `needs: tests`)로 배포가 테스트 뒤에 줄을 섰다. 그 전엔 병렬이라
   테스트가 7분이든 말든 배포는 30초에 끝났다 — **비용은 원래 있었고 게이트가 그걸 드러냈을 뿐이다.**
2. 그 7분 안에 **아무도 안 쓰는 xdebug** 가 있었다. `coverage: xdebug` 인데 커버리지 사용처가 0
   (`phpunit.xml` 설정 0 · `--coverage` 0 · 업로드 스텝 0). **로드만으로 PHP 전체가 느려진다.**
**출처**: `git log -S "coverage: xdebug"` → **최초 커밋 `6db65e3`(livewire-starter-kit 임포트) 단 하나.**
사람이 판단해서 넣은 게 아니라 **스캐폴딩 기본값**이 첫날부터 앉아 있었다.
**결과**: `coverage: none` 한 줄 → `Run Tests` **421초 → 188초**, 파이프라인 **8분04초 → 3분46초**.
**안전 증거 = 테스트 수치가 완전히 동일**(1846 tests / 24958 assertions / 2 skipped, 전후 일치).
조용히 건너뛴 게 있으면 숫자가 줄었을 것이다 — **성능 변경은 이 대조로 확인할 것.**
**🧭 교훈 2개**:
- **"느려졌다" 는 새로 생긴 비용이 아니라 「드러난 비용」일 수 있다.** 무엇이 느린지부터 **잡별·스텝별로
  실측**하고 나서 원인을 말할 것(CLAUDE.md #15 의 배포판).
- **스캐폴딩 기본값을 한 번은 읽어볼 것.** 첫 커밋에 들어와 아무도 안 건드린 설정은 "검토된 것"이 아니다.

### 58. ➖ **금액칸에는 음수를 못 넣는다** — 포매터가 지우고 `-` 키는 ÷1000 이다 (2026-08-12)

`input[data-money]` 는 입력할 때마다 `replace(/[^0-9.]/g,'')` 로 **숫자 외 문자를 지운다**. 게다가
`-` 키는 keydown 에서 가로채 **÷1000 단축키**로 쓰인다(jin 2026-07-06, §14). 그래서 `-300000000` 을
치면 마이너스가 사라지는 정도가 아니라 **엉뚱한 숫자가 된다**. 조용히 부호만 날아가는 게 아니라서 더 위험하다.
**어디서 걸렸나**: 마이너스 통장(한도대출) 잔액 입력. 원화 잔액이 −3억인데 넣을 방법이 없었고,
검증도 `if ($krw < 0) 거부` 라 **0 을 넣거나 그날 입력을 건너뛰는 수밖에 없었다** → 청산가치가 그만큼 부풀었다.
**해결 = 부호를 칸 밖으로**. 그 칸에만 `[+/−]` 토글을 붙이고 칸에는 절대값만 담는다(`abs() * (음수? -1 : 1)`).
- 🚫 **공용 포매터를 고치지 말 것** — 전 ERP 금액칸이 음수를 받게 된다. 음수 검증이 없는 칸이 많아
  오타 한 번이 정산으로 흘러간다. 음수가 필요한 칸은 실제로 **하나뿐**이었다.
- ➕ 부수 효과가 좋다: 눌러야만 음수라 **실수로 음수**가 안 생기고, ×1000 단축키도 그대로 산다
  (억 단위 칸에서 `300` + `+` `+` = 300,000,000 — 이 칸에서 특히 유용하다).
- 🧭 새 금액칸에 음수가 필요하면 **먼저 "정말 이 칸만인가" 를 확인**하고, 맞으면 같은 토글 패턴을 쓴다.
- ⚠️ 서버에서도 부호 출처를 토글로 못박을 것 — 칸 값에 음수가 흘러와도 `abs()` 로 눌러야
  "토글 OFF 인데 음수 저장" 이 안 생긴다. 가드 = `OverdraftBalanceTest::test_sign_comes_from_the_toggle_not_the_text`.

### 59. 🚪 값을 폐기할 땐 **「받는 목록」에서만 빼고 「그리는 목록」엔 남긴다** (2026-08-12)

`BoardRequest` 의 구 `purchase_payment`(입금요청)를 계약금·매입잔금으로 쪼갠 뒤 수신을 끊었다.
그런데 **운영에 열린 행이 2건 남아 있었다.** 상수와 `TYPE_META` 까지 같이 지웠으면 그 2건이
**라벨 없는 유령 뱃지**가 됐을 것이다(뱃지 렌더가 META 를 돌기 때문에 조용히 빈칸이 된다).

그래서 목록을 **두 개로 갈랐다**:
- `TYPES` = **받을 수 있는 것**(`raise()`·API 검증). 폐기한 값은 **여기서만** 뺀다.
- `TYPE_META` = **그릴 수 있는 것**(뱃지·색·알람·필터 pill). 폐기해도 **남긴다**.

**🧭 규칙 — 화면·집계는 항상 META 를 돌 것.** `TYPES` 를 돌면 폐기 직후 옛 행의 뱃지가 사라진다.
실제로 `BoardRequestModelTest::test_every_type_has_complete_meta` 를 `TYPES` 기준으로 두면
폐기한 값의 메타 완결성을 **아무도 안 지키게** 된다 → META 전체를 돌게 고쳤다.

**⚠️ 곁다리 — 테스트가 폐기한 값 위에 서 있으면 한꺼번에 무너진다.** 이번에 27개가 깨졌다.
대부분은 "아무 신호나 하나" 가 필요했을 뿐이라 살아있는 type 으로 옮기면 되지만, **일부는
폐기한 값 고유의 성질**(`manual_confirm=false` + `amount=false` 조합이 그 값에만 있었다)을
검증하고 있었다 — 그건 옮기면 **검증이 통째로 사라진다**. 그런 테스트는 생성 경로를 우회해
행을 직접 만들고, 목적을 **"옛 행이 여전히 정상 동작한다"** 로 다시 쓴다.

**🚫 조용히 튕기지 말 것.** `in:` 검증만 걸면 상대(board)가 받는 메시지는
"selected type is invalid" 뿐이라 원인을 못 찾는다. **무엇으로 바꿔 보내야 하는지**를 담아
돌려준다(`error=type_retired` + `purchase_deposit`/`purchase_balance` 명시).

**🧭 폐기 시점은 데이터로 정한다** — "board 가 신버전이겠지" 가 아니라 운영 로그로 확인했다
(heymanerp: 구 신호 마지막 수신 08-10 16:13, 그 뒤 신 신호 6건). 이게 없으면 §11 의
"튕기면 유일한 경로가 죽는다" 경고를 무시하는 셈이 된다.

### 60. 👻 **화면에 안 보이는 규칙** — 하루에 세 번 같은 뿌리로 터졌다 (2026-08-24)

같은 날 세 건이 터졌는데 원인이 전부 같았다: **동작을 결정하는 규칙이 화면에 없었다.**
예외도 로그도 없이 조용히 잘못 돌아간다.

| 무엇 | 증상 | 진짜 원인 |
|---|---|---|
| 채권현황 알림톡 | BizM 승인·tmplId 입력까지 끝냈는데 **발송 0건** | 수신자 표에 코드가 없어 **안내 화면에 체크박스조차 안 뜸** → 켤 방법이 없었다 |
| 보증금독촉·신규차량등록 | 무사백 담당 차의 바이어·매입가가 **영업 8명 전원에게** | 담당자 자동 발송이 **코드에만** 있어 화면엔 안 보임 → jin 이 「영업이 안 받네」 하고 체크 → 자동+전체가 겹침 |
| 무담보 한도 칸 | (배포 직전 발견) 실무자에게 **아예 안 보임** | 08-21 에 super 전용으로 올리며 **숨기는 것까지** 같이 갔다 → 「저장했는데 안 바뀐다」가 될 자리 |

**🧭 규칙 — 동작을 결정하는 것은 화면에 있어야 한다.**
- 자동 발송·자동 판정을 코드에 박으면, 사람은 화면만 보고 **반대로 조작**한다. 그게 사고가 된다.
- 권한을 올릴 때 **「보기」와 「고치기」를 같이 올리지 말 것.** 숨기면 이유를 못 봐서 문의가 몰리고,
  보이되 잠그면 화면이 스스로 설명한다(`disabled` + 안내 문구).
- 「N명이 받습니다」처럼 **모수가 바뀐 문구**를 그대로 두지 말 것 — 스코프형으로 바꾸면
  같은 내용을 받는 게 아니라서 그 숫자가 거짓말이 된다.

**🔒 이 부류의 가드는 전부 정적 검사여야 한다.** 기능 테스트로는 원리상 못 잡는다 —
**동작 자체는 성공하기 때문**이다(알림은 나가고, 화면은 렌더되고, 저장은 된다).
이번에 넣은 것: `AlimtalkRecipientTableTest`(수신자 표 누락·중복·유령) ·
`AlimtalkScopedRoutingTest::test_no_alimtalk_sender_reads_the_salesman_phone_directly`(담당자 하드코딩 재발) ·
`BuyerLockAdminOnlyTest`(보이되 disabled).

### 61. 📮 알림톡 수신자 = **체크박스(받을지) × 역할(무엇을)** (2026-08-24, 배포 `9c48fc7`)

`AlimtalkRecipients::scopedFor($code, $vehicles)` → `[전화 => 그 사람이 볼 수 있는 차량들]`.
범위는 `User::canScopeVehicle()`(화면·서류·삭제가 쓰는 단일 출처)이 정한다 —
admin·업무관리자·수출통관·재무 = 전체 / 관리 = 본인 팀(+휴가 위임) / 영업 = 본인 담당.

- **적용 9종**: 신규차량등록·매입미지급·판매미입금·정산확정대기·ETA잔금·선적임박미수·보증금독촉·픽업필요·딜러입금완료v2
- **그대로 `forBroadcast()`**: 회사 전체 집계 5종(일일요약·채권현황·주간·월결산·자금보고) — 차량 스코프가 무의미
- **그대로 자동 대상**: 정산 승인 3종(기안자·계단) · 딜러 발송 · board 요청(시각 규칙)

**⚠️ 함정 4개**
1. **배정 0명인 `role='관리'` 는 아무것도 안 받는다.** 의도된 규칙이지만 조용하다.
   ⇒ 새 회사를 붙일 때 배정 현황을 **먼저 실측**할 것(08-24 3사 실측 위험 인원 0명).
2. **업무관리자는 배정과 무관하게 전체**다 — `isManager()` 가 `canScopeVehicle()` 첫 줄에서 true.
   배정(subordinate)은 `role='관리'` 전용이라, 업무관리자에게 배정해도 **아무 효과가 없다**.
3. **전화 출처가 둘**이다 — `users.phone` 우선, 비면 `salesmen.phone` 폴백(구 픽업이 후자를 썼다).
   그리고 **ERP 계정 없는 영업담당자**(`salesmen` 만 있고 `users` 없음)는 역할 그룹을 안 탄다 →
   체크박스 안에서 덮게 해뒀다(`user_id IS NULL` + '영업' 체크 시).
4. **담당자 없는 차량**은 영업·관리 스코프 밖이라, **admin·업무관리자가 하나도 안 켜져 있으면 아무도 안 받는다.**

**🚫 기본값을 함부로 비우지 말 것** — 픽업 `['영업']` · 보증금독촉 `['관리','manager','영업']` ·
v2 `['영업']` 은 **구 자동 발송을 보존하려고** 넣은 값이다. 빼면 배포 직후 조용히 끊긴다.
가드 = `AlimtalkScopedRoutingTest::test_defaults_preserve_the_old_automatic_sends`.

**새 알림톡을 만들 때**: 차량이 붙으면 `scopedFor` + `SCOPED_CODES` 등록,
회사 집계면 `forBroadcast`, 그리고 **수신자 표 셋 중 하나에 반드시 등록**(§ 아래 #62).

### 62. 📭 수신자 표에 없는 알림톡은 **영영 안 나간다** (2026-08-24)

`AlimtalkRecipients` 의 표 셋 중 정확히 하나에 코드가 있어야 한다 —
`DEFAULT_ROLES`(역할 선택형) · `TARGETED_LABELS`(자동 대상) · `TIME_RULE_CODES`(시각 규칙).

어디에도 없으면 `isBroadcast()`=false 라 **안내 화면에 체크박스가 안 뜨고**,
`saveRoles()` 가 non-broadcast 를 hard-return 해서 **설정으로 켤 방법도 없다**.
`selectedRoles()`=[] → `forBroadcast()`=[] → cron 이 매일 «수신자 없음 — skip» 으로 **SUCCESS 종료**한다.

**실사고**: `erp_receivable_status` 가 BizM 승인 + tmplId 입력까지 끝났는데 이 줄이 없어
heymanerp 발송 **0건**이었다(운영 실측: 설정행 없음·수신자 0·스케줄 로그 0). jin 은 끝난 줄 알았다.

**🔎 「승인했는데 안 온다」 제보의 확인 순서**: ①`tmplId` ②**`isBroadcast`·`selectedRoles`·`forBroadcast` 수**
③`alimtalk_logs` 의 skip 사유. **②가 이 함정 자리**다.

⚠️ `alimtalk_roles_{code}_{set}` 설정행이 **빈 문자열('')** 이면 «명시적으로 전원 해제»다
(미설정과 다르다 — 미설정은 `DEFAULT_ROLES` 로 떨어진다). 심사 중 알림을 꺼둘 때 이 상태가 된다.

가드 = `AlimtalkRecipientTableTest`(누락·중복·유령 코드 정적 검사).

### 63. 📨 알림톡 **아이템명은 순한글**이어야 한다 — 라틴·슬래시는 「오탈자」로 반려 (2026-08-24)

카카오 검수가 `B/L대기` 를 반려했다:
> "오탈자가 기재되어 있는 것으로 확인됩니다. 하기 문구를 수정하지 않고 그대로 발송할 경우,
> 고정값으로 기재되어 수정이 불가능하게 됩니다. ▶ B/L대기"

**같은 파일로 함께 올린 채권현황(아이템명 전부 한글)은 승인**됐다 — 같은 제출·같은 검수자라
**통제된 비교**가 된다. 승인 이력 20여 종의 아이템명에 라틴·슬래시가 **하나도 없다**.
본문(E열)·템플릿명(C열)의 `ERP`·`ETA` 는 통과하므로 **아이템명에만** 걸리는 제약이다.

⇒ 아이템명은 **한글+숫자+공백**만. 뜻은 description 으로 옮긴다(`B/L대기` → `선하증권` +
`#{BL대기}대 발급 대기`). ⚠️ **6자 제한은 등록이 아니라 발송(K208)** 에서 걸리므로
정확히 6자는 여유가 없다. 가드 = `AlimtalkItemListSpecTest::test_every_item_title_is_plain_korean`.
📌 반려 유형 전체(양식·오입력·길이·오탈자 4종)는 메모리 `project_alimtalk_itemlist_registration`.

### 64. 🧮 값을 **분해해서** 밖으로 보낼 땐 「항 수」가 아니라 **닫힘**을 계약한다 (2026-08-25)

ssancar.com 포털에 차량별 미수를 발행하며 **두 세션이 각각 틀렸다.**
ERP 는 구성을 **3항**(`가격·운임·입금`)으로 제안했고, ssancar 가 **4항**(`+ savings_used`)으로 고쳤다.
**둘 다 짧았다** — 실제 항은 **8개**다(`app/Models/Vehicle.php:1697-1718`):
`sale_other_costs` · `commission` · `auto_loading` · `tax_dc` · **회수이력** · **완납 스냅**이 남아 있었다.

🔑 **틀린 이유가 같다.** 둘 다 *"안 맞는 사례를 관찰해서 항을 하나씩 되찾는"* 방법을 썼다.
그 방법은 **관찰된 만큼만** 찾는다. ssancar 는 8대가 안 맞는 걸 보고 `savings_used` 를 찾았지만,
`commission` 이 0 인 표본만 봤기 때문에 거기서 멈췄다.

**⇒ 항을 세지 말고 닫히게 만든다.**
```
sale_price + transport_fee + other_charges − paid − savings_used − written_off
    ≡  unpaid_amount − overpaid_amount            (오차 < 통화 1단위)
```
- **파생으로 만들면 공식 사본이 안 된다** — `other_charges = 총판매 − 가격 − 운임` ·
  `paid = 총판매 − 적립금 − 손실처리 − 미수`. 둘 다 단일 출처에서 **빼서** 만드므로 공식이 바뀌어도
  항등식이 자동으로 따라온다(#45 의 짝 — 거긴 «복제 금지», 여긴 «애초에 안 쓰기»).
- **스냅·음수까지 닫혀야 한다.** 세 갈래(미수>1 / 0<미수<1 완납 스냅 / 미수<0 과입금)를
  **전부** 테스트할 것. 하나만 밟으면 *"합계는 0 인데 줄을 더하면 0 이 아닌"* 화면이 남는다.
  ⚠️ 스냅 테스트는 **잔차가 실제로 저장됐는지 먼저 단언**할 것 — 소수가 잘리면 잔차 0 이 되어
  **아무것도 검사하지 않은 채 통과**한다.

**🚫 판매 전 차량에 `0` 을 보내지 말 것** — 받는 화면이 **「완납」으로 그린다.** `null` 이다.
**🚫 이름 하나에 두 뜻을 담지 말 것** — `paid` 는 *"확정 잔금"* 으로 읽으면 회수이력이 빠지고
*"모든 입금"* 으로 읽으면 **미확정 Draft 잔금**이 들어온다. 둘 다 운영에 실재한다.

⚠️ **곁다리 — `write_off`(손실처리)를 「낸 돈」에 섞지 말 것.** 바이어가 낸 게 아니라
**회사가 포기한 채권**이다. 섞으면 «당신이 낸 돈» 이 실제보다 커져 상대가 자기 송금 기록과
대조하다 어긋난다(jin 2026-08-25 → `written_off` 별도 줄). ⚠️ 쪼개도 **판매계약서 Balance 와는
다르다** — 계약서 Received 는 회수이력을 **통째로 제외**한다(#29 · jin 2026-07-29).
⇒ **밖으로 보내는 금액을 만들 땐 「어느 서류와 나란히 놓이는지」를 먼저 확인**하고, 다르면
화면이 **출처를 밝히게** 한다.

가드 = `tests/Feature/PortalVehicleApiTest`(닫힘 3갈래 · 재료가 `unpaid_components` 밖으로 새는지 · null · 통화).

### 65. 🚪 가드는 **「이번 저장이 만든 변화인가」와 「그 상태가 아직 유효한가」를 같이 봐야 한다** (2026-08-26)

**무담보 체크 강제**(2026-08-21)가 조건을 `계약금 > 0` 하나로 뒀다. 그런데 `save()` 는
**매 저장마다 전 게이트를 다시 평가**하고, 폼에는 **기존 계약금이 실려 있다** — 항상 참이다.
⇒ **판매 탭만 고쳐도 매입 가드가 떴다**(실사고 248가4049: 계약금 2,000만이 두 달 전 확정,
매입 완납·선적완료인데 판매 잔금 저장이 막힘).

🔑 **더 나빴던 건 「켜도 효과가 없다」는 것이다.** 무담보 사용액은
`Buyer::computeReceivableGauge` 가 **`! isShippingEntryMet()` 인 차의 계약금만** 더한다
(`app/Models/Buyer.php:195`). 선적 진입을 넘긴 차는 켜도 한도가 **1원도 안 줄어든다.**
효과 0인데 저장만 막고, 켜면 두 달 전 지급이 **「회사가 대신 낸 돈」이라는 거짓 기록**이 된다.

🚨 **결정적 단서 — 바로 위 「해제 가드」는 같은 `isShippingEntryMet()` 을 이미 보고 있었다.**
같은 불변식(«회사 돈이 아직 나가 있나»)을 **한쪽만 안 본 것**이다.
⇒ **짝을 이루는 가드(켜기/끄기, 진입/해제)를 만들 땐 조건을 나란히 놓고 대조할 것.**

**새 저장 가드를 만들 때 물어볼 것 3가지**
```
① 이번 저장이 만든 변화인가?   기존 값으로도 참이면 무관한 저장까지 막는다
② 그 상태가 아직 유효한가?     이미 해소된 건을 막으면 「효과 없는 차단」이 된다
③ 강제한 결과가 사실인가?      아니면 사람에게 거짓 기록을 남기라고 시키는 것이다
```
가드 = `UnsecuredCheckRequiredTest`(무관한 저장 통과 · 선적 진입 넘긴 차 통과 + 기존 7건).

### 66. 🔓 규칙을 「완화」할 때 — 옛 규칙을 검사하던 **테스트**와, 그 가드가 **대신 막아주던 것** (2026-08-26)

**원인**: 2026-07-24 정산 락 개편이 락 트리거를 `paid` → `secondary_status='closed'` 로 옮겼는데
**판매 입금 경로가 안 따라왔다**(#38·MEMORY 의 「완화도 같은 체크리스트」 3파). `FinalPayment::creating`
(큐 22-A-2)이 「paid 정산 존재」로 신규 판매 잔금을 차단했고 **예외구멍이 없었다**(super 도 불가).

**증상 — 문이 두 개 동시에 닫힌다**: 운임비처럼 **지급 후에 확정되는 매출**이 미수로 남으면
①잔금을 못 넣고 ②그 미수 탓에 2차 마감의 완납 게이트(`외화 && 미수>0`)도 못 넘는다.
**돈을 기록할 수도, 마감할 수도 없다.** 실사고 = heymanerp `248가4049`·`29마0712`(각 EUR 1,528,
2026-06 귀속). 매입 쪽은 07-24 에 같은 이유로 이미 완화됐다(54가6191) — 판매만 잔재였다.
⚠️ **가이드가 이 데드락을 우회하라고 가르치고 있었다** — 「정산 완료 차량의 운임비 미수는 회수 이력으로
정리」(`notion-guide-publish.php`). 버그가 업무 관행으로 굳으면 제보조차 안 올라온다.

**해결**: 판매 입금 3경로를 `hasClosedSecondarySettlement()` 로 통일 — `FinalPayment::creating` ·
`PaymentConfirmationService`(재무확정) · 채권관리 `deposit` 회수이력.
🔑 **재무확정을 같이 안 풀면 반쪽이다** — 미수 분자는 `confirmed_at` 있는 행만 센다(SKILLS §13).
잔금은 들어가는데 미수가 안 줄어 ②가 그대로 남는다. 가드 = `FreightAfterPaidSettlementDeadlockTest`
(잔금 → 미수 0 → 마감 **end-to-end**. 단계별 단위 테스트로는 「반쪽」이 안 잡힌다).
🚫 차량 간 자금 이체(`InterVehicleTransferService`)는 append-only 원장이라 `paid` 가드 유지.

**🧭 완화할 때 추가로 봐야 할 것 2가지 — 이번에 둘 다 밟았다.**

**① 옛 규칙을 「다른 문구」로 검사하던 테스트.** 예외 메시지 substring 으로 grep 하면 놓친다.
`'신규 판매 잔금'` 으로 훑었더니 `'paid 상태'` 를 단언하던 **2건이 그대로 남아** 전체 스위트에서야 드러났다
(`test_22a3b_paid_settlement_blocks_4_types` — 계약금·중도금 4항목까지 막고 있었다 /
`test_paid_settlement_fp_save_dispatches_notify` — 목적은 「예외→토스트」인데 트리거로 paid 를 쓰고 있었다).
⇒ **메시지가 아니라 「그 규칙을 만드는 컬럼·조건」(`settlement_status', 'paid'`)으로 grep**할 것.
⇒ 목적이 「무엇이 막히나」가 아닌 테스트(화이트스크린 방지 등)는 **트리거만 갈아끼우고 목적은 보존**한다.

**② 그 가드가 「대신 막아주던」 아래쪽 경로.** 채권관리 deposit 차단을 「신규 미러가 생기는 경우」로
좁혔더니(맞는 변경 — 안 좁히면 기존 행 수정에서 환율 가드의 정확한 안내를 덮는다), 그 아래엔
**환율 가드밖에 없었다**. `syncFinalPayment` 가 `FinalPayment::where('id',…)->update()` 로
확정 잔금을 고치는데 **query-builder update 라 모델 `updating` 락·잠금해제 토큰·`AuditLog` 가 전부 안 뜬다**
— 마감 차량의 금액·수금일이 **예외도 로그도 없이** 바뀔 수 있었다.
그 테스트 주석이 이미 힌트를 적어두고 있었다: *"paid 면 기존 paid-가드가 먼저 hMethod 로 막음 …
그 경로가 선점"*. ⇒ **선점하던 가드를 좁힐 땐 「선점이 사라지면 무엇이 남나」를 확인**할 것.
가드 = `ReceivableRateEditTest::test_amount_and_date_edit_blocked_when_secondary_settlement_closed`
(넣기 전 실패를 먼저 확인 — `Component has no errors`).

**💡 「숫자가 바뀝니다」는 조건까지 말해야 승인할 수 있다.** 운임비는 정산 base
(`sale_price + commission + auto_loading − tax_dc`) **밖**이라, **판매환율로 넣으면**
`settlement_exchange_rate` 가 미완납 폴백과 같은 값이 되어 `actual_payout` 불변 ⇒ **이월 0**.
움직이는 건 「잔금을 넣는 행위」가 아니라 **「다른 환율로 넣는 것」**이다. 둘 다 테스트로 박제했다.

### 67. 🚪 되돌릴 수 없는 일괄 작업은 **건너뛴 것을 사유까지** 보여준다 (2026-08-26 2차 일괄 마감)

`secondary_status='closed'` 는 회계 락의 **단일 트리거**이고 해제는 차량별 [회계 재조정]+관리 승인이다.
그런 걸 `wire:confirm` 한 줄로 일괄 처리하면 안 된다 — 08-06 월배치 제출에서 이미 겪은 형태다.

- **미리보기 모달**에 「닫을 것」과 「건너뛸 것 + 사유 + 차량번호」를 나란히 놓는다.
  `ok/fail` 카운터로 뭉뚱그렸다면 정작 손봐야 할 2대(운임비 미수)가 **숫자에 묻혔을 것**이다(#62 의 그 형태).
- **미리보기와 실행이 같은 판정 함수**(`secondaryCloseBlocker`)를 쓴다. 갈리면
  「목록엔 마감된다고 떴는데 안 닫히는」 행이 남는다(#44 의 «조건을 옮겨 적지 말 것»).
  인라인 단건 버튼도 같은 함수를 쓰게 리팩터했다.
- **대상 = 화면 필터 그대로.** 보이는 것만 닫힌다. 월 선택 필수.
- **버튼 노출은 대상 건수로 판정**(`[이 달 2차 일괄 마감 (27)]`, 0건이면 안 뜸).
  jin 제안은 「일괄 확정을 1회라도 누른 달에만」이었으나 **누른 기록이 없다**(`confirmMonth` 는 이벤트를
  안 남기고 인라인 확정도 결과가 같다). 그럴 필요도 없다 — `secondary_status='pending'` 은
  **이미 지급까지 끝난 달**에만 붙으므로 조건이 데이터에 이미 있다. ⇒ **달력 규칙 대신 건수**.
  실측 06월 27 · 07월 27 · 당월 0.
- ⚠️ 이 화면은 `wire:poll.30s` — **버튼 판정은 순수 `count()`**, accessor 를 도는 미리보기는 모달 안에서만.
  안 그러면 30초마다 전 대상의 미수·마진 accessor 가 돈다.

### 68. 📄 등록 양식 xlsx — **안 넣는 칸에 무엇이 남는지**를 보고, 판단은 「성공한 파일과 diff」로 (2026-08-26)

BizM 알림톡 등록본을 karaba 재등록용으로 만들며 **같은 자리에서 두 번 틀렸다.** 둘 다 「넣는 값」만 보다 났다.

**① 샘플 잔재가 그대로 나갔다.** 빌더가 메시지유형(D)·보안여부(H)를 「샘플 값 그대로 쓴다」는 전제였는데,
**샘플은 행마다 예시가 다르다** — 실측 13행 `MI` · 14행 `AD` · 15행 `EX`. 기본형 빌더는 6행만 써서 맞았고,
아이템리스트는 6~21행에 걸쳐 쓰는데 **1~2종만 넣던 시절엔 6·7행에서 끝나** 안 드러났다.
12종을 한 번에 넣자 verify 가 9건(3사 × 3행)을 잡았다.
⚠️ **화이트리스트가 있어도 KEEP 목록에 든 열은 안 지워진다** — 「안 넣는 칸」과 「비우는 칸」은 다르다.

**② 그걸 고치면서 멀쩡한 칸까지 비웠다.** H 를 「승인본도 비어 있다」고 판단해 지웠는데,
그 근거였던 덤프가 **내 리더 버그**였다 — 정규식 `<c r="..."([^>]*)>(.*?)</c>` 가 자기닫힘 셀
(`<c r="F6" s="15"/>`)을 만나면 **다음 셀 값까지 삼켜 열이 밀린다**. 실제 샘플은 6~21행 전부 `H='0'`.
비운 파일을 BizM 업로더가 **「6행이 틀렸다」로 거부**했다(jin 실측).

**🧭 그래서 이렇게 한다.**
- **성공한 파일과 직접 diff.** 「무엇이 다른가」를 추측하지 말 것 — 카라바 6행 ↔ **jin 이 실제로 업로드에
  성공한** 헤이맨 파일을 대조하니 다른 칸이 **H 하나뿐**이었다. 규격 검사(길이·변수)는 전부 통과하고 있었다.
- **행마다 다른 값만 못박고, 행마다 같은 값은 샘플 그대로 둔다.** D 는 못박고 H 는 건드리지 않는다.
- **xlsx 를 정규식으로 읽을 땐 자기닫힘 셀을 먼저 처리**할 것: `<c [^>]*?/>|<c [^>]*?>.*?</c>`.
  이 버그는 **값이 밀려도 그럴듯해 보여서** 잘못된 결론까지 끌고 간다.

⚠️ 이 부류는 **업로드는 되고 심사에서 반려**되거나, 업로더가 행 번호만 뱉는다. 로컬 테스트로는 안 잡힌다.

### 69. 🧩 공용으로 묶어도 **그게 놓인 자리**가 결과를 바꾼다 (2026-08-27 페이지네이션)

우하단 [통관서류 알람] 카드(`fixed bottom-4 right-4`)와 오른쪽 정렬 페이저가 겹쳐 누르기 어려웠다.
화면마다 땜질하지 않으려고(실제로 정산 화면은 `pb-28` 로 혼자 피해 있었다) Laravel 페이지네이션 뷰를
프로젝트로 가져와 가운데 정렬로 바꿨다 — `->links()` 를 쓰는 **17개 화면**이 한 파일을 공유하므로
호출부는 한 줄도 안 고쳤다.

**그런데 두 화면만 그대로였다.** jin 실측: 재고·채권은 됐는데 **차량관리(=수출통관 메뉴, 같은 화면)만**
안 됐다. 원인은 뷰가 아니라 **감싸는 컨테이너**였다 —

```blade
<div class="flex … sm:justify-between">   {{-- 103줄 위에서 열림 --}}
    <div>내려받기·명세서기입 버튼</div>
    <div>{{ $this->vehicles->links() }}</div>   {{-- 오른쪽 끝으로 밀림 --}}
</div>
```

`justify-between` 이 페이저를 오른쪽 끝에 붙여 놓으면, **nav 안에서 아무리 가운데 정렬을 해도
nav 상자 자체가 오른쪽에 있어 아무 일도 일어나지 않는다.** 재고·채권은 페이저가 독립 블록이라
전체 폭을 잡아 정상 동작했다.

**🧭 「공용으로 묶었으니 다 따라온다」가 아니다.** 레이아웃을 한 곳에서 고칠 땐 **각 호출부의 부모**를 본다.
- 고침 ① 공용 뷰의 nav 를 `flex w-full justify-center` 로 — 감싸는 게 블록이면 이것만으로 해결.
- 고침 ② 차량관리는 페이저를 그 flex 행 **밖으로** 빼 독립 블록으로.
- 가드 = `PaginationCenteredTest`(공용 뷰 가운데·전체폭 · 페이저가 `justify-between` 행 안이면 실패 ·
  차량관리 독립 블록). ⚠️ **화면은 어느 쪽이든 정상 렌더**되므로 기능 테스트로는 원리상 못 잡는다.

**곁다리 — 진단 순서.** 「서버가 안 바뀐 것 같다」는 제보에 ①배포 SHA ②파일 존재 ③뷰 네임스페이스
우선순위(`view()->getFinder()->getHints()`) ④**컴파일된 blade 내용** ⑤빌드된 CSS 순으로 확인했다.
⚠️ CSS 확인 때 **파일이 2개**(작은 것·232KB)인 걸 놓쳐 「클래스 없음」으로 잘못 읽었다 —
`public/build/assets/*.css` 는 **전부** 볼 것(SKILLS #50 의 그 확인, 파일 수까지).

### 70. 🔁 개편하면 **테스트·미리보기 경로**가 제일 먼저 뒤처진다 (2026-08-27 알림톡 테스트 발송)

기능설정의 테스트 발송이 `erp_daily_summary` 고정인 것도 불편했지만, 진짜 문제는 따로 있었다 —
2026-08-20 일일요약 개편 때 **테스트 발송만 안 따라와서** 옛 변수(`선적전건수`·`선적후금액`·`미수합계`)를
넘기고 있었다. 누르면 새 변수가 안 채워져 **카드가 깨진 채로 나갔다.** 예외도 실패도 아니라 아무도 몰랐다.
(#38 의 그 형태 — 바꾼 쪽의 소비자를 안 훑었다. 본 기능은 cron 이 매일 돌아 멀쩡했다.)

**🧭 그래서 나열을 없앴다.** 코드별로 변수를 손으로 적지 않고 **템플릿이 선언한 `vars` 를 읽어** 채운다
(`AlimtalkTestVars`). 새 템플릿이 늘어도 안 고쳐도 되고, 변수명이 바뀌면 자동으로 따라온다.
- 실데이터 빌더가 있는 5종(일일·주간·월결산·채권현황·자금보고)은 **대표가 받는 값 그대로** 보낸다.
- ⚠️ 그 빌더는 **빈/부분 배열**을 줄 수 있다(자금보고는 스냅샷 없으면 `[]`) → 빠진 것만 샘플로 메운다.
  이걸 안 하면 `#{기준일}` 이 치환 안 된 채 나간다(테스트가 실제로 잡았다).
- 드롭다운은 **tmplId 가 있는 템플릿만** 노출 — 없으면 보내봐야 반려다.
- 가드 = `AlimtalkTestSendTest`(선언 변수 전량 채움 · 본문 `#{}` 잔존 0 · 집계형은 실데이터 ·
  **폐기 변수 재등장 차단**(정적) · 드롭다운은 등록분만).

**🧭 규칙**: 본 기능을 개편하면 **그 기능의 미리보기·테스트·데모 경로**를 같이 훑을 것.
그쪽은 사람이 가끔만 눌러서 깨져도 한참 안 드러난다.

### 28. 2차 정산 비용 일괄 기입 — 잠금해제 자동 + 비용컬럼 봉인 패턴 (2026-07-01)
2차 정산 시 비용 정정 일괄 도구. 성격 다른 3비용: **말소비=24,000 고정 / 면허비=묶음당 한 덩어리 n/1 / 탁송비=건바이건(업체 월명세서)**.
- **공유 뒷단** `App\Services\BulkVehicleCostService::apply($column, [vehicleId=>금액], $user, $reason, $fleetWide)` — ⚠️**2026-07-24 정산 락 개편 후**: 마감(`closed`) 차량은 skip, 나머지는 락이 없어 토큰 없이 직접 `update`(구 `unlockForCostBulk` 토큰 자동발급·소비 흐름 제거). 값 변경은 `Vehicle::updated` recordChange 자동감사, 일괄 기입 사유는 `AuditLog(bulk_cost_applied)` 로 별도 보존. 반환 `[applied, unchanged, skipped]`. (상세=메모리 `project_settlement_lock_redesign`)
- **비용컬럼 봉인**: `Vehicle::BULK_COST_FIELDS`(비용 9개) 화이트리스트 — **fleet-wide여도 판매가·환율 등 민감 21필드 못 건드림**. `$column` 미포함이면 `InvalidArgumentException`.
- **권한 2축**: 면허비=`canUnlockLedger`(팀, `fleetWide=false`) / 탁송비=`canApprove`(전체, `fleetWide=true`, 명세서 한 장이 전 차량이라). 단일 🔓 버튼은 팀 스코프 그대로(안 건드림).
- **재업로드 안전(2중)**: ① 2차 마감(`secondary_status='closed'`) 차량은 절대 안 건드림(skip=`settlement_closed`, 값 달라도) — 소급 변경은 개별 🔓로만. ② 값 동일이면 잠금해제·감사 없이 skip(`unchanged`).
- **면허비 뷰(선적요청 「2차 비용」 탭)**: `secondary=pending` 묶음만, **월 그룹=정산 `created_at`(귀속월)** — 지급월(`paid_at`) 아님. 정산 화면 monthFilter와 동일 축("5월분→6/10 지급"). n/1=첫 차량에 나머지 원.
- **탁송비 도구(차량목록 「명세서 기입」)**: xlsx 업로드(`updatedCostImportFile` 자동 파싱) + 붙여넣기. `parseCostLine`(차량번호 `\d{2,3}[가-힣]\d{4}` + 차량번호 제외 마지막 숫자=합계). 차량번호 매칭, 미매칭은 빨강 표시만(기입 X, 유령데이터 방지).
- **거래처별 서식 파서 (2026-07-03, ✅운영배포 e1d434c)**: 「명세서 기입」에 **거래처 선택** — 탁송 위카/구천육/현대A1, 면허 뮤추얼/성지. 회사마다 xlsx 열 위치가 달라 **좌표 고정 파서**로 분기(`Vehicle::TOWING_IMPORT_LAYOUTS`: 구천육 R2~·번호 J·금액 F+G / 현대A1 R13~·번호 M·금액 I+J). ⚠️ 범용 `parseCostLine`(마지막 숫자)은 **차종 숫자(아우디 Q5→5)·비고 차번호꼴 오염** 위험 → 좌표회사는 붙여넣기 금지·xlsx 전용, 금액=성분열 직접 합(수식셀 의존 X). **성지 면허비**=서류 매핑 대신 선적요청 2차비용 탭 딥링크(`erp.shipping-requests.index?tab=cost`, `#[Url] $tab`). **같은 차번호 여러 줄→금액 합산**(취소 후 재진행, `applyCostRowsToPreview`). 거래처 화이트리스트 검증(`assertCostCompanyValid`, IDOR)·차량번호 공백 정규화. `Vehicle::COST_IMPORT_COMPANIES`. [[project_declaration_total_price]]
- **정산 연동**: 마진 computed라 비용 저장 즉시 정산처리 자동 재계산. 2차 완료(closeSecondarySettlement)만 수동. 상세=[[project_settlement_cost_bulk]] 메모리.

### 28-2. 월배치 조정은 「정산관리 제출 모달」 한 곳에서만 (jin 2026-08-06)

**구조적 함정**: `SettlementPayoutAdjustment` 는 `batch_id` 필수라 **배치가 있어야 조정을 만들 수 있다**.
그래서 예전엔 「제출 → 배치 생성 → **카톡 발송** → 그제서야 월배치 화면에서 조정」 순서였다.
- 카톡에 찍힌 총액은 **제출 시점 값** → 조정하면 **승인자가 본 숫자 ≠ 실제 지급액**.
- 승인자가 바로 승인하면 조정 기회 자체가 소멸.
- 매입취소 손실은 「반영 표시」를 사람이 눌러야 했다 — **반려된 배치에 잘못 누르면 차감하지도 않은 손실이 반영됨으로 사라져 영영 청구 불가**.

**현행**: 정산관리 「월배치 제출」 = 확인 모달. 매입취소 손실 체크박스 + 수동 조정을 여기서 확정하고,
`submitForMonth($user, $month, $adjustments)` 가 배치+조정을 **한 트랜잭션**으로 만든 뒤 그 총액으로 카톡을 보낸다.
- **월배치 화면엔 조정 입력이 없다**(읽기 전용). 고치려면 반려 → 정산관리에서 재제출.
- 최종 승인 시 `markCancelLossesSettled()` 가 도장을 찍는다. **반려면 안 찍힌다.**
- ⚠️ 도장 대상은 조정 행의 `cancel_vehicle_ids`(json)다. 승인 시점에 "그 담당자의 미반영 손실 전부"로
  다시 계산하면 **제출~승인 사이에 생긴 새 취소건까지 반영됨으로 찍혀 조용히 누락**된다.
- ⚠️ `unsettledCancelLossBySalesman()` 은 `vehicle_ids` 를 함께 준다 — **차량번호로 id 를 되찾지 말 것**
  (운영에 같은 차량번호가 여러 행 존재: 18누0304 실측 2행).
- 대상 정산 목록은 `eligibleSettlementIds()` **단일 출처** — 미리보기와 실제 제출이 갈리지 않게.
- 가드 = `SettlementBatchSubmitModalTest`(미리보기·차감·체크해제·지급없음·수동조정·최종승인 도장·반려 미도장)
  + `SettlementPayoutAdjustmentTest::test_payout_batch_screen_has_no_adjustment_input`(입력 경로 재유입 차단).

### 29. 판매계약서(sales_contract) + fillMulti `aggregates` 훅 (2026-07-01)
신규 서류 `sales_contract`(export 다중차량, 1바이어·단일통화 컨트롤러 가드). **fillMulti 에 `aggregates` 키 신설** — `header`(primary 1대만)·`footerAggregates`(per-row 컬럼 SUM 수식)로는 표현 불가한 **"선택 차량 전체 합산 스칼라 푸터"**(운임·기타·입금·합계·잔금 = 표에 없는 필드의 컬렉션 집계)용. resolver 가 `Collection` 받음. removeRow 前 footer 좌표에 **값**으로 기입(수식이면 cross-cell ref 가 removeRow 로 깨짐 — Subtotal 만 SUM 수식). ⚠**외국인 계약서 = 한글 금지**: Code=`romanizePlate`·Brand=`brandEn`·목적항=영문 `dischargePort`만(한글 국가명 fallback X). 상세=[[project_sales_contract_doc]].

> **2026-07-29 레이아웃 전면 개편** (jin 새 디자인, 3사 배포 `dc8e916`). 구 8열(A~H)·슬롯 23~52·푸터 53~59 → **신 26열(A~Z)·슬롯 22~51·푸터 52~57**. 재작성 = `scripts/build-sales-contract-template.php`(원본 `Sales Contract_Sample.xlsx`, git 미추적) → `generate-sales-contract-tenants.php`. 채택 시 밟은 함정 3종은 §8 #37.
> - **슬롯 컬럼(병합 앵커)**: A Code / E Brand / I Model / M Chassis / R FOB / U **SHIPPING** / X **TOTAL**(`=SUM(R:W)` per-row 수식). 운임비가 푸터 집계 → **차량별 컬럼**으로 내려왔다.
> - **푸터**: 52 Sub Total(R·U·X 3열 SUM = footerAggregates) / 53 Other Charge / 54 Total Amount(=Sub+Other) / 55 Received / 56 **Deposit** / 57 Balance. 53~57 은 `aggregates` 값 기입.
> - ⚠️ **Deposit = 적립금(`savings_used`)이지 계약금이 아니다.** 계약금은 확정 FP 라 Received 에 이미 들어간다 — Deposit 에 넣으면 이중계상. 원본 샘플의 `Balance = Total − Received + Deposit`(더하기)도 오류라 **빼기**로 교정.
> - **회사정보 좌표(테넌트 치환)**: Beneficiary E15 / Bank Name R15 / Swift E16 / Bank Address R16 / Account E17 / **Beneficiary Address R17** / 셀러 상호 B64 · 사업자 B66 · Tel·Email B67 · 주소 B68. 바이어 블록(매핑) P64·P66·P67·P68.
> - **Contract No = `DocValue::invoiceNo()`**(이니셜+차대번호 끝자리 숫자) — 인보이스와 같은 규칙(jin). 환율 행(USD/Euro Rate)은 **삭제**.
> - ⚠️ **Balance ≠ ERP 미수금**: 계약서 Total 엔 `sale_other_costs` 가 없고, Received 는 **확정 FP 만**(채권관리 회수이력 cash/offset/other/write_off 미포함). jin 2026-07-29 **현행 유지 결정** — 손실처리액을 바이어 계약서에 노출하지 않기 위함. 실측 heymanerp 215대 중 회수이력 보유 18대만 차이.

### 30. dev→master cherry-pick 후 auto-merge 가 상대세션 삭제분 조용히 드롭 (2026-07-01)
병렬 세션 A(배포)가 master 에서 세션 B의 미완성 코드(버튼·lang)를 제거 커밋한 뒤, B가 완성본을 dev 커밋→master cherry-pick 하면 **3-way auto-merge 가 "A가 지운 줄"을 지운 채로 유지** → B의 추가분이 조용히 누락(충돌 표시 없이). **교훈: cherry-pick 성공해도 push 전 `grep`/`git diff HEAD..dev -- <파일>` 로 반드시 재검증.** 누락 시 `git checkout dev -- <파일>`(dev 완전판) + `--amend`. (실측: 판매계약서 다중선택바 버튼·shipdoc lang 드롭 → 복원 후 배포.)

### 31. `Builder::when()` 에 Closure 를 조건으로 넘기면 그 클로저를 현재 빌더에 실행 (2026-07-26)
**원인**: Laravel 9+ 의 `when($value, $cb)` 는 **`$value` 가 Closure 면 조건값을 얻으려고 그 클로저를 `$value($query)` 로 호출**한다. 스코프 클로저(예: `fn($q)=>$q->orWhereHas('vehicles',...)`)를 `when()` 의 **조건 자리**에 그대로 넘기면, 엉뚱한 빌더(예: Consignee)에서 실행돼 `Consignee::vehicles() undefined` 같은 런타임 에러(뷰 렌더 시점 ViewException). 실측: 컨사이니 목록 바이어 스코프 IDOR fix 중.
**해결**: 클로저는 **콜백 안에서만** 쓰고 조건은 boolean 으로 — `->when($scope !== null, fn($q)=>$q->whereHas('buyer', $scope))`. (컨사이니 IDOR fix `a22ac9b`: 독립 컨사이니 목록에 소속 바이어 스코프 재인가(§8 #26 패턴) — `buyerScope()` 단일출처를 목록·드롭다운·openEdit/save/delete 에 적용, buyer 필수 검증 추가. 테스트 `ConsigneeIdorScopeTest`.)

### 32. 🚨 Volt public 프로퍼티 ↔ 메서드 **이름 충돌** = 버튼이 조용히 죽음 (2026-05 최초, 2026-07-28 재발)
**원인**: `public string $search` 와 `public function search()` 가 같은 이름이면, 브라우저에서 `$wire.search` 가 **메서드가 아니라 프로퍼티 값(문자열)** 로 잡힌다. → `wire:click="search"` · `wire:keydown.enter="search"` 가 **요청을 아예 안 보내고 실패**한다. **JS 에러도, PHP 예외도 없다.**
**증상 위장**: 화면은 `wire:poll.30s` 가 돌 때만 갱신되므로 **"검색이 30초 걸린다 = 느리다"** 로 보인다. 실제로 서버는 무관(실측 쿼리 3ms·컴포넌트 렌더 55ms·요청 73ms).
**결정적 단서**: 충돌 없는 버튼(초기화 `resetFilters` 등)은 멀쩡하다 → **"초기화는 빠른데 검색만 느리다"** 가 나오면 성능이 아니라 이 버그다.
**해결**: 메서드를 다른 이름으로. Enter·버튼 방식 = `searchNow()` / 필터형 = `applyFilters()` / **`wire:model.live` 바인딩이면 Livewire 표준 훅 `updatedSearch()`** (이 경우 `search()` 는 아무도 안 부르는 죽은 코드라 페이지 리셋도 안 되고 있었음 — 훅으로 바꾸면 함께 해소).
**🔒 재발 방지 = `tests/Feature/VoltPropertyMethodCollisionTest`** — `resources/views/livewire` 전체 정적 스캔으로 충돌 0건 강제. ⚠️ **이 부류는 일반 단위 테스트로 못 잡는다**(테스트는 PHP 메서드를 직접 호출해 통과해버림). 육안 점검도 놓친다 — 실제로 사람이 5개 찾고 이 테스트가 2개를 더 찾았다.
**이력**: 2026-05 차량목록에서 겪고 `applyFilters` 로 고쳤으나 **나머지 화면을 안 훑어** 7곳(재고관리·바이어·컨사이니·영업담당·정산·관리자항구·채권관리)에 남아 재발. fix 커밋 `311ee3a`.
**🧭 진단 교훈**: "느리다" 제보를 성능 문제로 단정하지 말 것. **서버 실측이 멀쩡한데 체감이 느리면, 성능이 아니라 배선(동작 안 함)을 먼저 의심.** 확인 순서 = ①어떤 조작 → 몇 초 뒤 무엇이 바뀌나 ②**Network 탭에 요청이 뜨긴 하나** ③뜬다면 그 Time. 요청 자체가 없으면 성능 얘기는 무의미하다.

### 33. Blade 컴포넌트 루트에 `{{ $attributes }}` + `class="..."` 를 따로 찍으면 뒤엣것이 통째로 죽는다 (2026-07-28)
**원인**: `<div {{ $attributes }} class="relative">` 처럼 두 번 찍으면, 호출측이 `class` 를 넘길 때 **같은 div 에 class 속성이 2개** 렌더된다. HTML 규칙상 **앞엣것만 적용**되므로 컴포넌트가 스스로 붙인 class 가 사라진다.
**증상**: `x-erp.combobox` 를 `class="w-44"` 로 부른 재고관리에서 `relative` 가 죽어 → 드롭다운의 `absolute` 가 엉뚱한 조상 기준으로 잡혀 **폭·위치가 화면 밖까지 벌어짐**(jin 제보 "엄청 큰데 버그같아"). class 를 안 넘기던 차량관리 호출부는 멀쩡해서 **호출부에 따라 되고 안 되는** 형태로 위장된다.
**해결**: 항상 `{{ $attributes->merge(['class' => 'relative']) }}`. merge 는 기본값을 앞에 붙여 `class="relative w-44"` 하나로 만든다.
**🔒 가드 = `tests/Feature/ComboboxAttributeMergeTest`** — 루트 class 속성이 정확히 1개 + 그 안에 기본 class 생존. **새 Blade 컴포넌트를 만들 때마다 이 형태인지 확인**(combobox 외 컴포넌트도 동일 위험).

### 34. Carbon 3 `diffInDays` 는 부호 있는 값 — 방향 뒤집으면 음수가 조용히 나간다 (2026-07-28)
**원인**: `$a->diffInDays($b)` = **`$b − $a`**. Carbon 2 까지는 기본 절대값이었으나 **Carbon 3(Laravel 11+)에서 부호 있는 값**으로 바뀌었다. 예전 감각으로 `now()->diffInDays($과거일)` 을 쓰면 **음수**가 나오는데 예외도 경고도 없다.
**증상**: 픽업 알림톡(`AlimtalkPickup`)이 `"-42일 경과 · 미완납"` 으로 발송. **실패건이 아니라 정상 발송 295건 전부**에 찍혀 나갔다 — 로그상 `sent` 라 아무도 못 봤다.
**해결**: 기준→대상 방향으로 쓰고 `(int)` 캐스팅(Carbon 3 은 float 반환). `$v->purchase_date->copy()->startOfDay()->diffInDays(now()->startOfDay())`.
**⚠️ 구분**: `diffInDays($x, false)` 처럼 **`false` 를 명시**해 부호를 의도적으로 쓰는 곳(알람 D-day 3곳)은 정상이다. 인자 없이 쓰면서 양수를 기대하는 코드만 버그.

### 35. 알림톡 아이템리스트 description 은 규격 초과 시 **발송 자체가 반려**된다 (2026-07-28)
**원인**: 카카오 아이템리스트의 description 길이를 넘기면 BizM 이 `K137:ExceedMaxItemDescriptionLength`(list) / `K140:InvalidItemSummaryDescription`(summary) 로 **접수 거부**한다. `AlimtalkTemplates::itemListPayload` 가 차량 데이터를 자르지 않고 그대로 넘기고 있었다.
**증상**: 픽업 알림톡 61건 반려. 구입처가 `업체명/담당자 010-…`(25~30자)인 **오래된 매입 건에서만** 실패 → 정작 독촉이 필요한 차만 알림이 안 갔다. 운영 실측 경계 = **20자 성공 / 25자 실패**.
**해결**: 조립 지점(`itemListPayload`)에서 `mb_substr(…, 0, 20)` 일괄 컷 — 전 템플릿이 통과하는 단일 지점이라 여기만 막으면 된다. 상수 `AlimtalkTemplates::ITEM_DESC_MAX`.
**⚠️ 값 길이를 예측할 수 없는 필드(구입처·바이어명 등)를 새 템플릿에 넣을 때 주의.** 템플릿 정의의 **리터럴**이 20자를 넘으면 잘 나가던 메시지가 잘리므로, 리터럴은 20자 이하로 유지할 것.

### 36. 🚨 enum 컬럼에 값을 추가하는 코드는 **로컬·CI 를 100% 통과하고 운영에서만 죽는다** (2026-07-29)
**원인**: 테스트는 **SQLite**, 운영은 **MySQL**([[project_db_tier_mismatch]]). **SQLite 는 enum 을 강제하지 않아** 코드 화이트리스트에 새 값을 넣고 DB enum 을 안 늘려도 테스트가 전부 초록으로 통과한다. 운영 MySQL 은 `SQLSTATE[01000] 1265 Data truncated for column 'x'` 를 낸다.
**실측 사고**: 2026-07-28 "적립금 채권관리 회수방법" 배포가 `ReceivableHistory` 코드에만 `'savings'` 를 넣고 `receivable_histories.method` enum(`deposit,cash,offset,other,write_off`)을 안 늘렸다 → **3사 전부 적립금 사용 전면 불능**. `Vehicle::saved` H6 가 만드는 `method='savings'` 회수이력 insert 가 실패하며 **차량 저장이 통째로 롤백**돼 `savings_used` 가 0 으로 남았다. 차량관리 판매탭·채권관리 양쪽 동일.
**위장**: 에러 토스트가 안 뜨고 값만 안 들어간다 → 사용자에겐 "적립금이 안 써진다"로 보인다. **로그(`storage/logs/laravel.log`)에만 남는다** — 이런 제보를 받으면 재현보다 **서버 로그 grep 이 빠르다**(실측: 로그 1줄로 3시간짜리 가설 3개가 정리됨).
**해결**: 값 목록을 모델 상수로 단일출처화(`ReceivableHistory::METHODS`) + 같은 커밋에 `ALTER TABLE … MODIFY COLUMN … ENUM(…)` 마이그레이션.
**🔒 가드 = `tests/Feature/ReceivableMethodEnumTest`** — DB 에 넣어보는 대신 **마이그레이션 파일의 enum 문자열을 정적으로 파싱해** 코드 상수와 대조한다(드라이버 무관이라 SQLite 에서도 잡힘). ⚠️ **다른 enum 컬럼에 값을 추가할 때도 같은 형태의 가드를 만들 것** — 기능 테스트로는 원리상 못 잡는다.

### 37. 양식 xlsx 를 새로 받을 때 — 통화 로케일 서식 · 유령값 · 도장 앵커 (2026-07-29 판매계약서 개편)
사람이 엑셀에서 만든 양식을 그대로 템플릿으로 채택하면 터지는 3가지. **새 양식을 받을 때마다 확인할 것.**

**① `[$€-2]` 류 통화 로케일 서식은 `applyCurrency` 를 깨뜨린다.** 치환 정규식이 `/\$(?!-)/` 라 `[$€-2]` 의 `$`(뒤가 `€`)를 잡아간다 → EUR 은 `[€€-2]`, JPY 는 `[¥€-2]` 라는 **깨진 서식**이 되고, USD 는 조기반환이라 **달러 계약서가 €로 인쇄**된다. 양식 제작자는 자기 통화로 셀서식을 잡기 마련이니 거의 항상 들어온다.
→ **금액칸은 `\$\ #,##0;[Red]\-\$\ #,##0` 형태로 통일**할 것. `\$` 여야 치환 후 백슬래시 제거까지 정상 동작(`€ 62,000`). 6통화 실측 = `$/€/¥/£/₩` 전부 정상.

**② 병합 안쪽 유령값.** 사람이 레이아웃을 바꾸면 옛 좌표의 값이 **병합에 가려진 채 남는다**(실측 12개). 화면에선 안 보여서 그냥 지나친다. 테넌트 파생 스크립트는 **앵커에만 쓰므로**, 안 지우면 heyman/karaba 양식 안쪽에 ssancar 은행정보가 박제된다. → build 스크립트에서 명시적으로 `setValue(null)`.

**③ baked 도장 앵커.** `DocumentFiller::removeDrawingsAt` 은 **정확히 같은 앵커**의 도장만 지운다. 양식에 박힌 도장 좌표가 바뀌었는데 `StampSlots` 앵커를 안 고치면 업로드 직인이 그 위에 겹쳐 **이중 도장**이 된다(판매계약서 B71 → C70). → 가드 = `SalesContractLayoutTest::test_stamp_anchor_matches_baked_drawing_in_every_tenant` (3사 양식의 실제 drawing 좌표 ↔ StampSlots 앵커 정적 대조, GD 불필요).

**🔒 검증은 매핑 배열이 아니라 생성물로.** `config()` 배열만 검사하는 테스트는 **푸터 좌표가 틀려도 통과한다**. `fillMulti` 는 footerAggregate 를 removeRow **전에** 쓰고 미사용 슬롯을 트림하므로, 푸터는 **(슬롯수 − 실제대수)만큼 위로 올라온다** — 템플릿 좌표를 그대로 단언하면 만차일 때만 맞는다. `SalesContractLayoutTest` 는 실제 생성 시트의 셀을 읽고 트림 시프트를 계산해 비교한다.

### 38. 🧹 기능을 지우면 그 훅이 **찍던 값**에 매달린 다른 기능이 조용히 죽는다 (2026-07-30)
**원인**: 2026-07-29 에 보증금 매입 선지급 승인 사다리를 제거하면서, 그 재무 확정 훅
(`confirmPurchaseFundingByFinance`)이 **유일하게 세팅하던** `vehicles.is_deposit_purchase` 도장 코드가 함께 사라졌다.
컬럼·모델·뱃지·cron·스케줄·테스트는 전부 그대로 살아 있었지만 **아무도 값을 안 찍으니 대상이 영구 0건**.
**증상**: 예외 0·로그 0·테스트 전부 초록. cron 은 매일 정상 실행되고 `대상 0건 — skip` 만 남긴다.
운영 실측으로 `is_deposit_purchase=true` 인 차량이 **0대(삭제분 포함)** 인 걸 확인하고서야 드러났다.
**해결**: 트리거를 사람 조작(매입 탭 「보증금으로 매입」 체크박스)으로 옮기고, 도장 시각은 `Vehicle::saving` 에서
찍어 진입점을 통합(UI·시드·tinker 동일). 가드 = `DepositPurchaseMarkerTest`(체크→도장→cron→발송 end-to-end 포함).
**🧭 교훈 — 삭제 PR 체크리스트**: 지우는 코드가 **write** 하던 컬럼·플래그·설정키를 `grep` 해서
**읽는 쪽이 남아 있는지** 확인할 것. 읽는 쪽은 대개 조용히 실패한다(빈 목록·0건 skip).
같은 커밋에서 소비자도 정리하거나, 대체 트리거를 함께 넣어야 한다.
> ⚠️ 도장류 플래그는 **최초 1회만** 찍고 이후 저장에서 갱신하지 말 것 — 경과일(D+N) 타이머의 기산점이면
> 저장할 때마다 갱신돼 **알림이 영원히 안 나간다**. 해제 시엔 시각도 비워 "플래그 ON 일 때만 시각 존재" 불변식 유지.

**🔁 재발 (2026-08-07, jin 제보 — 입력칸 이동판)**: 당사자 축소(2026-07-09)로 판매 탭 컨사이니 **입력칸만 옮기고**
읽는 쪽을 안 고쳐, `vehicles.consignee_id` 를 보던 4곳(차량목록·재고목록·**재고 컨사이니 필터**·엑셀 열)이
전부 빈값이 됐다. 실측 = ssancarerp 14/14 · heymanerp 07-09 이후 59/59. **서류만 3단 폴백을 써서 살아남았다**
(`DocValue::invoiceConsignee`) — 인쇄물 사고가 안 난 건 운이 아니라 그 폴백 덕이다.
- 필터는 **조용히 0건**이라 더 나쁘다. "고르면 아무것도 안 나온다"는 데이터가 없는 걸로 오해된다.
- 삭제뿐 아니라 **"쓰는 칸을 옮긴" 변경**도 같은 체크리스트 대상이다. 옛 칸을 `grep` 해서 읽는 쪽을 전부 옮길 것.
- 해소 = `Vehicle::effective_consignee`(통관→선적→레거시 판매) 단일 출처 + `DocValue` 위임.
  **마지막 폴백을 지우지 말 것** — 07-09 이전 차량(heymanerp 76대)은 옛 칸에만 값이 있다.
- 가드 = `EffectiveConsigneeFallbackTest`. 목록 뷰가 옛 칸을 직접 읽는 형태로 되돌아가면 실패하는 **정적** 검사 포함
  (값이 비어도 화면은 정상 렌더돼 '-' 만 뜨므로 기능 테스트로는 원리상 못 잡는다).

### 39. 📖 기능을 지우면 **사내 가이드·챗봇 카드**에 그대로 남아 "없는 기능"을 안내한다 (2026-07-30)
**원인**: #38 의 문서판. 2026-07-29 에 보증금 이체·매입 선지급을 삭제(−1,808줄)했는데, Notion 업무가이드의
「보증금 매입 선지급」 섹션(재무·관리 각 5블록)과 챗봇 카드 3장이 그대로 남았다. 코드에는 승인 타입 상수만
과거 이력 라벨용으로 남고 **신규 기안 지점은 0건**인데, 가이드는 그걸 "신청하세요"라고 안내하고 있었다.
**증상**: 예외 0·테스트 0·로그 0. 직원이 챗봇에 물으면 **존재하지 않는 메뉴를 찾으라고 답한다.**
코드만 보면 절대 안 드러난다 — 가이드는 repo 밖(Notion)에 있다고 착각하기 쉽다.
**해결**: 가이드도 repo 소스다(`CLAUDE.md` 「📖 사내 업무가이드·챗봇 동기화」). 기능을 지우거나 바꾸면
**같은 커밋에 가이드 소스도 고치고**, `--verify` 3줄로 라이브와 대조한다.
**🔒 가드 = `NotionGuideAudienceTest`** — audience 무결성·발행 함수의 마커 생성·`--verify` 존재 + **폐기 용어 잔존**을 정적 검사.
🗑️ **기능을 없앨 때 `RETIRED_TERMS` 에 그 화면 용어를 한 줄 추가**하면, 가이드·카드에 남은 설명을 테스트가 잡는다.
⚠️ 자동 판정(승인 TYPE_* 상수 참조 여부로 죽은 타입 추론)은 만들었다가 **버렸다** — 살아있는
`TYPE_UNPAID_EXPORT_OVERRIDE`('50% 룰 예외')가 상수명 대신 값으로만 쓰여 죽은 것으로 오판했다.
**오탐이 나는 가드는 곧 무시당해 무용지물이 되므로** 사람이 명시하는 목록을 택했다.
⚠️ `scripts/notion-*` 은 **master 에 없다**(dev 63 / master 16, notion-* 0). 테스트는 `.php` 라 master 로
가서 CI 배포 게이트에서 도는데, 파일이 없으면 red → **3사 배포가 통째로 막힌다**. → 없으면 자동 skip 하게 해뒀다.
**🚨 곁다리 위험**: 두 빌더 모두 `ASSISTANT_AUDIENCE` 마커를 만들지 않은 채 페이지를 통째 교체하고 있었다 —
`--apply` 하는 순간 6페이지 마커가 전부 사라져 **다음 03:00 색인이 fail-closed 로 멈추거나 전 청크가 staff**
가 될 뻔했다(권한 격리 해제). 발행 순서도 "삭제→삽입"이라 중간 실패 시 페이지가 통째로 비었다.
지금은 마커 자동 생성 + **삽입 선행** + running log 감지 시 중단으로 바뀌었다.

### 40. 📨 알림톡 카드는 **BizM 승인 등록본과 글자단위로 같아야** 한다 — 띄어쓰기 하나로 반려 (2026-07-31)
**원인**: 카카오는 아이템리스트 카드를 등록본과 대조하고, 각 칸마다 별도 규격을 건다. 코드(`AlimtalkTemplates::ITEMLIST`)와
등록본(`Desktop\알림톡\{회사}확정알림톡\upload_erp_*.xlsx`)이 어긋나면 **발송 시점에만** 반려된다 — 로컬·CI 는 100% 통과한다(#36 과 같은 형태).
**실측 사고**: 자금보고 첫 발송이 연속 3번 반려. `원금 대비 손익`(8자) → K208, `굴리는 총 자금 · #{기준일}`(치환 후 21자) → K135,
`+0.46억` → K140. jin 이 BizM 쪽에서 **띄어쓰기를 빼 재등록**했는데 코드가 안 따라온 게 원인이었다.
**규격 (반려코드까지 실측)**:
- 아이템·요약 **title ≤ 6자**(공백도 1자) — `K208`. 요약 title 은 **등록본과 정확히 일치**해야 함 — `K138`
- **요약 description 은 금액 표기만**(숫자·쉼표·`원`) — `억`·`+`·`−`·`(…)`·`△`·`₩-` 전부 `K140`.
  ⇒ **음수를 못 쓴다.** 손익 같은 부호 있는 값을 요약에 넣지 말 것(넣으면 손실 주에 발송이 통째로 죽거나, 절대값이 이익으로 오보고된다)
- 하이라이트 description ≤ 19자(치환 후) — `K135` / 아이템 description ≤ 20자(조립 지점 자동컷, #35) / 헤더 ≤ 16자
- **등록본에 버튼이 있으면 발송에도 반드시 포함** — 빼면 `K108`
**해결**: 값 길이를 예측 못 하는 description 은 조립 지점에서 컷하고(#35), **리터럴인 title 은 컷하면 뜻이 바뀌므로 정적 테스트로 막는다**.
**🔒 가드 = `tests/Feature/AlimtalkItemListSpecTest`** — 전 템플릿의 title/헤더 길이 + 카드가 쓰는 `#{변수}` 가 실제 vars 에 있는지 정적 검사.
**🧭 교훈**: 카드 문구를 건드리기 전에 **그 회사 승인본 xlsx 를 먼저 열어본다**(RichText 라 평문 덤프 필요). 상세·열 매핑 = 메모리 `reference_alimtalk_approved_templates`.

### 41. 🇰🇷 로그 화면은 DB 값을 **원문 그대로** 찍는다 — 새 액션·사유를 만들면 사전도 같이 (2026-07-31)
**원인**: 감사로그는 `action`·`column_name`·`auditable_type` 을, 알림톡 로그는 `error` 를 화면에 그대로 출력한다.
그래서 새 이벤트(`AuditLog::recordEvent($x,'foo_bar')`)나 새 skip 사유를 넣고 `config/column_labels.php` 를 안 고치면
**관리자 화면에 영문 식별자가 그대로 노출**된다. 예외도 로그도 안 나므로 아무도 모른다.
**실측(운영 heymanerp)**: 액션 6종(`assistant_query`·`purchase_gate_override`·`capital_report_viewed` …), 모델 3종
(`CashSnapshot`·`Setting`·`SettlementPayoutBatch`), 컬럼 20종, 알림톡 `disabled_or_unconfigured` 113건이 영문이었다.
**jin 이 같은 지적을 4번 했다** — 사람 기억으로는 못 막는다.
**규칙**:
- `recordEvent`/`recordChange` 로 **새 action 을 만들면 같은 커밋에 `column_labels.actions`** 에 한글 라벨을 넣는다.
- 새 모델을 감사 대상으로 삼으면 `models` + `ColumnLabel::resolveTable()` 의 표 매핑 + 그 테이블 컬럼 사전까지 3곳.
- 알림톡 skip 사유는 **처음부터 한글 문장**으로 넣는다(`'error' => '통장 잔액 미입력'`). 옛 영문 코드는 `AlimtalkLog::SKIP_REASONS` 가 번역한다.
- ⚠️ **BizM 오류코드(`K140` 등)는 지우지 말 것** — 코드를 남기고 뜻을 덧붙인다. 2026-07-31 에 반려 3건을 그 코드로 진단했다.
- `column_name` 에 컬럼이 아닌 값이 들어가는 자리가 있다 — 챗봇은 **질문 유형**(`assistant_intents` 사전), 바이어 변경은 `buyer:{이름}`(접두사 패턴 `ColumnLabel::dynamicKey`). 컬럼 사전에 넣으려 하지 말 것.
- 업무 용어라 영문이 맞는 것(`TAX D/C`·`B/L`·`ETA` …)은 `AdminUiKoreanLabelTest::ALLOWED_NON_HANGUL` 에만 추가한다.
**🔒 가드 = `tests/Feature/AdminUiKoreanLabelTest`** — `app/` 을 정적 스캔해 기록되는 모든 action 이 한글 라벨을 갖는지,
알림톡 사유가 한글로 렌더되는지, 사전 값 자체가 한글인지 검사한다. **기능 테스트로는 원리상 못 잡는다**(영문이어도 화면은 정상 렌더).
⚠️ 스캔 정규식은 범위를 좁힐 것 — `'error' => '...'` 를 전 파일에서 찾으면 API 응답까지 잡아 오탐이 난다(실제로 났다).

### 42. 🚨 Eloquent **scope 와 같은 이름의 static 메서드**를 만들면 조회가 생성 메서드로 간다 (2026-08-09)

**원인**: Eloquent 는 `scopeOpen()` 을 `Model::open()` 으로 노출한다. 그런데 **같은 이름의 실제 static 메서드가 있으면 그쪽이 이긴다**(`__callStatic` 은 진짜 메서드가 없을 때만 탄다).
`BoardRequest` 에 생성용 `public static function open(int $vehicleId, string $type, …)` 와 조회용 `scopeOpen()` 을 같이 두자, **`BoardRequest::open()->get()`(조회 의도)이 생성 메서드로 가서 `ArgumentCountError`** 로 죽었다. 내가 직접 밟았다.
**증상**: `Too few arguments to function … at least 3 expected`. 시끄럽게 죽으니 #32(Volt 프로퍼티↔메서드, 조용히 죽음)보다는 낫지만 원인이 안 보여 헤맨다. **인스턴스 빌더 경유(`Model::query()->open()`)는 정상 동작**해서 "어떤 데선 되고 어떤 데선 안 되는" 형태로 위장된다.
**해결**: 생성 메서드를 다른 이름으로(`raise()`). **조회 = `query()->open()` / 생성 = `raise()`.**
**🧭 규칙**: 새 모델에 `scopeX` 를 만들 때 **같은 이름의 static 메서드가 없는지 grep**. 특히 `open`·`active`·`pending` 처럼 동사이자 상태인 단어가 위험하다.

### 43. 🔇 `Vehicle::refreshCaches()` 는 **raw update** — `saved` 훅이 안 뜬다 (2026-08-09)

**원인**: `refreshCaches()` 는 `DB::table('vehicles')->where(...)->update([...])` 로 캐시 3컬럼을 쓴다(§2 의 "bulk update 는 모델 이벤트가 안 뜬다" 가 **내부 구현에도 해당**). 그래서 잔금 저장 → `PurchaseBalancePayment::saved` → `refreshCaches()` 경로에서는 **`Vehicle::saved` 훅이 한 번도 안 돈다**.
**실측 사고**: board [입금요청] 자동 해소를 `Vehicle::saved` 에만 걸었더니 전액 지급해도 뱃지가 안 꺼졌다. 완료 버튼이 없는 설계라 **수동 탈출구도 없었다**.
**해결**: 잔금 변화에 반응해야 하는 로직은 **`PurchaseBalancePayment::saved`/`FinalPayment::saved` 에 직접** 건다(기존 `purchase_balance_due` 알람 해소가 이미 그 자리에 있다 — 옆에 붙이면 된다).
**⚠️ 관계 캐시도 같이 조심**: 그 훅 안에서 `$p->vehicle` 은 **방금 만든 잔금을 모른다**. 미지급을 다시 계산하려면 `fresh()`/`load()` 로 리로드해야 한다. 안 하면 "전액 지급했는데 안 꺼진다"가 된다.
**🧭 규칙**: "저장하면 자동으로 …" 를 만들 때 **그 저장이 어느 모델의 어느 훅을 실제로 태우는지** 확인할 것. 테스트가 관계로 행을 만들면 운영 경로(서비스·컴포넌트)를 안 타므로, **운영 경로를 그대로 타는 테스트를 따로** 둔다.

### 44. 🪞 화면을 API 로 미러할 땐 **조건을 옮겨 적지 말고 scope 를 재사용** (2026-08-09)

board 포털이 `erp/inventory` 재고 분류를 미러할 때, 조건을 컨트롤러에 옮겨 적으면 갈리는 순간
**"ERP엔 재고인데 board엔 없다"** 가 된다. 사람이 눈으로는 못 잡는다.
- `scopeInStock()` 은 출고일만 보는 게 아니다 — **매입가>0 + 출고 전 + 거래완료 아님 + 매입 완납**(서브쿼리) 복합 조건이다. 요약해 옮기면 반드시 어긋난다.
- 가드 = 화면 scope 와 API 응답의 **id 집합을 직접 비교**하는 테스트(`BoardInventoryApiTest::test_categories_match_the_screen_scopes`).
- ⚠️ **미러 대상이 목적에 맞는지도 확인**할 것 — 재고 3분류를 미러했더니 **[입금요청]을 보낼 차(매입 미지급)가 어디에도 없었다**(미지급 = 입고 전 = 재고 아님). 실측으로 발견해 `awaiting_payment`(지급대기) 분류를 ERP 화면에 함께 신설했다. **board 전용 분류를 발명하지 말고 ERP 화면에도 같이 만든다** — 그래야 양쪽이 같은 화면을 본다.

### 45. 🧮 같은 SQL 식이 4곳에 복제돼 있었다 — 새로 쓰기 전에 `grep` (2026-08-09)

매입 미지급 식(`매입가 + 매도비 − 확정 PBP 합`)이 `scopeInStock`·`scopeAction('purchase_unpaid')`·`purchase_balance_due`·`deregistration_needed` **4곳에 같은 문자열**로 있었다(주석엔 "단일 출처"라고 적혀 있었는데도).
다섯 번째를 붙이는 대신 **`Vehicle::purchaseUnpaidRawExpr()`** 로 뽑고 전부 그걸 쓰게 했다. 부호만 바꿔 쓴다(`> 0` / `<= 0`).
**🔒 가드**: 그 문자열이 파일에 **1회만** 존재하는지 정적 검사. (회사이익 공식이 3곳에 복제돼 대표 보고만 틀렸던 사고와 같은 형태 — 하나만 고치면 화면마다 숫자가 갈린다.)

### 46. 🔁 어트리뷰트 중복 = 화면 통째 500 — `grep` 은 윗줄도 봐야 한다 (2026-08-09)

`tabType` 에 `#[Url]` 을 추가했는데 **이미 붙어 있었다**. `Attribute "Url" must not be repeated` 로 그 화면이 통째로 죽는다.
원인은 확인 방법이었다 — `grep "Url.*tabType"` 처럼 **같은 줄만** 찾으면 프로퍼티 **윗줄**에 있는 어트리뷰트를 못 본다.
**🧭 규칙**: 프로퍼티에 어트리뷰트를 추가하기 전엔 `grep -B2` 로 **앞줄을 함께** 보거나 해당 구간을 직접 읽을 것.


## 9. 구현 패턴

### 상태기반 조회 (차량목록 dateType)
ERP 차량 목록은 `dateType` 프로퍼티로 모드 전환:
```php
$dateColumn = match ($this->dateType) {
    'purchase' => 'purchase_date',
    'sale'     => 'sale_date',
    'shipping' => 'shipping_date',
    'bl'       => 'bl_issue_date',
    default    => 'created_at',
};
```
탭 클릭 시 `dateColumn`만 바뀌고 동일 검색 필드 재사용.

### 진행상태 필터 pill 3상태 순환 (전체 유지 + 다중 제외, 2026-07-02)

차량목록 진행상태 스트립 pill을 **단일선택 → 3상태 순환 토글**로 확장. "전체 켠 채 거래완료·통관완료 등 완료건을 빼고 진행중만 보기" 유스케이스.

**클릭 순환**: 회색(미선택) → 🟣보라(이것만 보기 = `progressFilter` 단일) → 🔴빨강+`line-through`(제외 = `excludeStatuses` 다중) → 회색.
- 제외는 다중, 포함(보라)은 단일. **상호 배타** — 보라 켜면 excludeStatuses 클리어.
- `[전체]`('') pill = 포함·제외 전부 리셋.

```php
#[Url(as: 'exclude')] public array $excludeStatuses = [];

public function cycleProgress(string $val): void
{
    if ($val === '') {                                   // 전체 → 리셋
        $this->progressFilter = '';
        $this->excludeStatuses = [];
    } elseif ($this->progressFilter === $val) {          // 보라 → 빨강(제외 추가)
        $this->progressFilter = '';
        $this->excludeStatuses = array_values(array_unique([...$this->excludeStatuses, $val]));
    } elseif (in_array($val, $this->excludeStatuses, true)) {  // 빨강 → 회색(제외 제거)
        $this->excludeStatuses = array_values(array_filter($this->excludeStatuses, fn ($s) => $s !== $val));
    } else {                                             // 회색 → 보라(이것만, 제외 클리어)
        $this->progressFilter = $val;
        $this->excludeStatuses = [];
    }
    unset($this->vehicles);
    $this->resetPage();
}
```

- 목록 쿼리: `->when($this->excludeStatuses, fn ($q) => $q->whereNotIn('progress_status_cache', $this->excludeStatuses))` (기존 인덱스 활용, 성능 영향 없음).
- **export 정합**: `vehicles/index` export JS가 `exclude` 파라미터 전달 → `VehicleExportController` 도 `whereNotIn` + `ExportLog` 감사 기록. 화면 필터 ↔ export 일치.
- pill 상태 판정: `''` = `progressFilter==='' && count(excludeStatuses)===0`, 그 외 = `progressFilter===$val`(보라) / `in_array($val,$excludeStatuses)`(빨강).

### 대시보드 → 차량목록 정합성 (action 파라미터 패턴)

**핵심 원칙**: 대시보드 카드의 카운트 산정 로직과 클릭 후 vehicles 목록의 SQL where가 **100% 일치**해야 한다. 단순히 `progressFilter` 같은 단일 컬럼 필터로 표현 안 되는 액션(예: "선적 처리 필요" = 수출통관완료 + 선적중 두 진행상태에 분포)을 전달하기 위해 `action` 파라미터를 사용한다.

**vehicles/index 라우트 #[Url] 파라미터**:
```php
#[Url] public string $action = '';        // 대시보드 액션 키
#[Url] public string $salesmanId = '';    // 담당자 컨텍스트
#[Url] public string $dateFrom = '';
#[Url] public string $dateTo = '';
#[Url] public string $channelFilter = '';
#[Url] public string $progressFilter = '';
```

**mount() 처리 — 액션 모드면 기본 날짜 필터 비움**:
```php
public function mount(): void
{
    if ($this->action !== '') {
        return;   // 액션 산정 로직과 충돌 방지
    }
    $this->dateFrom = $this->dateFrom ?: now()->subMonths(2)->format('Y-m-d');
    $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
}
```

**applyActionFilter() — 액션별 SQL where 매핑**:
```php
private function applyActionFilter($q)
{
    // ERP 대시보드 액션 5종은 active 차량 한정 (progress_status_cache != '거래완료')
    $userDashActions = ['purchase_unpaid','sale_unpaid','clearance_needed','shipping_needed','dhl_needed'];
    if (in_array($this->action, $userDashActions, true)) {
        $q = $q->where(fn ($q2) => $q2
            ->where('progress_status_cache', '!=', '거래완료')
            ->orWhereNull('progress_status_cache'));
    }
    return match ($this->action) {
        'purchase_unpaid'  => $q->where('purchase_price','>',0)->whereRaw('(매입 미지급 식) > 0'),
        'sale_unpaid'      => $q->where('sale_price','>',0)->where('sale_unpaid_amount_krw_cache','>',0),
        'clearance_needed' => $q->where('sale_price','>',0)->where('sale_unpaid_amount_krw_cache','<=',0)->whereNull('export_declaration_document'),
        'shipping_needed'  => $q->whereNotNull('export_declaration_document')->whereNull('bl_document'),
        'dhl_needed'       => $q->whereNotNull('bl_document'),
        'has_sale'         => $q->where('sale_price','>',0),
        'has_purchase'     => $q->where('purchase_price','>',0),
        default            => $q,
    };
}
```

**검증 (필수)**: 대시보드 collection 카운트 = vehicles SQL count. tinker로 카드별 비교.

### wire:model 선택 기준
- 자동계산 필드 (정산액, 미입금 미리보기): `wire:model.live` 또는 `wire:model.live.debounce.500ms`
- 일반 필드: `wire:model` (deferred, 저장 시 반영)
- 계층 드롭다운 (바이어→컨사이니): `wire:model.live`
- 통화/환율: `wire:model.live` (KRW 환산값 즉시 반영)

### 파이프라인 카운트 스트립 (2종)

**① 대시보드용 10단계 카운트** (`<x-erp.pipeline-strip>` 익명 컴포넌트):
- 10단계(매입중/매입완료/말소완료/판매중/판매완료/선적중/선적완료/통관중/통관완료/거래완료) 카운트 가로 스트립 (v4)
- 모바일 `overflow-x-auto` 가로 스크롤
- props: `counts` (배열), `urlBuilder` (callable, status→URL), `title`, `subtitle`
- 클릭 → `vehicles?progressFilter=N`. 영업 뷰는 본인 salesman 한정 / 통관·정산·admin은 전체
- 사용처: `erp/dashboard`(헤더 아래) + `admin/dashboard`(`w-progress` 위젯 교체)
- SQL 패턴: `Vehicle::selectRaw('progress_status_cache, COUNT(*) as cnt')->groupBy('progress_status_cache')->pluck('cnt','progress_status_cache')`

**② 차량 편집 패널 1대 흐름도 7노드** (vehicles/index 인라인):
- 매입 / 말소 / 판매 / 입금 / 통관 / 선적 / DHL — 7노드
- 상태: `done`(✓ green) / `warn`(! amber) / `progress`(⋯ blue) / `pending`(- gray)
- 노드 클릭 → Alpine `tab` 변경 (`@click="tab = '{{ $node['tab'] }}'"`). 패널 헤더 ↔ Tab Nav 사이 위치
- `vehicles/index::progressFlow()` computed에서 상태 계산. `editingId=null`이면 null 반환 → 신규 등록 모드엔 비노출

## 10. UI 디자인 시스템 (app.css 유틸)

UI 단계를 거치며 확립할 공통 유틸. **새 페이지·위젯 만들 때 원시 Tailwind 대신 이 유틸 우선** 사용해 일관성 유지.

### 색 변수 (`@theme`)
- `--color-primary: #7c6fcd` (보라 메인)
- `--color-primary-hover: #6b5dbd`
- `--color-primary-soft` / `--color-primary-light: #ece9f8` (pill 배경)
- `--color-primary-text: #4c3fb1` (pill 텍스트, 링크 강조)

### 박스 유틸
| 클래스 | 용도 |
|---|---|
| `.card` | 기본 카드 (bg-white + 1px border + 10px radius + 16px padding) |
| `.card-tight` | `.card`에 덧붙여 padding 12px |
| `.card-sm` | 작은 카드 (8px radius + 12px padding) |
| `.summary-card` | 요약 카드 4종용 (label/value/delta/breakdown 서브클래스) |
| `.total-summary` | 금액 합계 박스 (`.row` / `.row.total` / `.row.total .amount`) |

### 버튼 / 탭
| 클래스 | 용도 |
|---|---|
| `.btn-primary` | 기본 CTA (보라 배경) |
| `.tab-pill` / `.tab-pill.is-active` | 탭 pill 버튼 |
| `.pill-count` | 건수 pill (primary-light 배경 + primary-text) |

### 뱃지
기본 `.badge` + 색상 변형 병행 선언:
```html
<span class="badge badge-blue">라벨</span>
```
색상: `.badge-blue` / `.badge-teal` / `.badge-purple` / `.badge-amber` / `.badge-red` / `.badge-green` / `.badge-gray`

**도메인 고정 매핑 (car-erp)**:
- **차량 진행상태 5단계 그룹** (v4):
  - `매입중`/`매입완료`/`말소완료` = **`badge-blue`** (매입 단계)
  - `판매중`/`판매완료` = **`badge-purple`** (판매 단계)
  - `선적중`/`선적완료` = **`badge-amber`** (선적/반입 단계)
  - `통관중`/`통관완료` = **`badge-green`** (통관 단계)
  - `거래완료` = **`badge-gray`** (완료)
  - **v3 grandfather 호환**: `수출통관중`/`수출통관완료` = `badge-amber` 유지 (안전망)
- **세부 단계 구분**: 동일 색 안에서 `진행중`/`완료` 텍스트로 표현 (예: "통관중" vs "통관완료")
- **판매채널**: `export=badge-blue` / `heyman=badge-teal` / `carpul=badge-purple`
- **정산상태**: `pending=badge-blue` / `calculating=badge-amber` / `confirmed=badge-green` / `paid=badge-gray`
- **입금상태**: `완납=badge-green` / `부분입금=badge-amber` / `미입금=badge-red`
- **통화**: 다중통화 표시는 뱃지보다 텍스트 prefix(USD/JPY 등) 권장

### 섹션 헤더
카드 내부 섹션 구분 (탭 내부 그룹핑에 핵심):
```html
<div class="section-header">
    <span class="section-dot bg-emerald-500"></span>
    <span class="section-title">섹션 제목</span>
</div>
```
- `.section-dot` — 6×6 컬러 점. Tailwind `bg-*` 직접 부착
- `.section-title` — 10px uppercase gray-500
- `.section-divider` — 섹션 사이 점선 (`<hr class="section-divider">`)
- **ERP 색상 매핑 예**: 기본정보=보라(primary) / 매입=blue-500 / 판매=purple-500 / 통관=amber-500 / 선적=emerald-500 / DHL=teal-500 / 서류=gray-500

### 할일 dot 색 매핑 (긴급도 기준)
대시보드 "처리 필요 항목" 리스트의 좌측 dot 색은 **진행단계가 아닌 긴급도**로 통일.
- **`bg-red-500`** — 금액/회수 차단 (매입 미지급, 판매 미입금, 환율 미입력, 채권 위험)
- **`bg-amber-500`** — 정보 누락 (통관 바이어/일자 누락, 포워딩사 미지정)
- **`bg-blue-500`** — 일상 흐름 (수출통관 신청, 수출신고서 업로드, 정산 생성)
- **`bg-green-500`** — 일상 흐름 (선적 처리, B/L 업로드)
- **`bg-teal-500`** — 일상 흐름 (DHL 발송)
- **`bg-violet-500`** — 정산 흐름 (정산 대기/확정/지급)

`urgent: true` 플래그가 함께 있으면 우측 카운트 뱃지에 `bg-red-100 text-red-700` 적용. red·amber dot은 보통 urgent. blue/green/teal/violet은 비-urgent.

### 입력 / 폼
`.input-base` — 통일된 input 스타일 (1px gray → focus primary). 원시 Tailwind 대신 사용 권장.

### 레이아웃 래퍼 패턴
- **페이지 헤더**: 좌측 타이틀(`h2.text-xl.font-bold.text-gray-800`) + 우측 메타(뱃지/범례/네비)
- **필터 바**: `.card` 래핑 (1행 검색 + 2행 빠른필터 + 3행 채널 탭)
- **KPI 그리드**: `grid grid-cols-2 gap-4 xl:grid-cols-4` + `.card` 반복
- **요약 카드 스트립**: `grid grid-cols-2 md:grid-cols-4` + 아이콘 원(`flex h-10 w-10 items-center justify-center rounded-full bg-*-50`) + 숫자

## 11. 모바일 반응형 컨벤션

### 분기점 (Tailwind v4)
- `sm` = **640px** — 콘텐츠 분기 (테이블↔카드, 그리드↔리스트, 필터 layout, 모달)
- `md` = **768px** — 레이아웃 분기 (사이드바 drawer 모드 진입/이탈)

### 페어 렌더 패턴 (테이블/그리드 ↔ 카드 리스트)
같은 데이터를 두 벌 렌더. 데스크탑/모바일 각각에 최적화:
```blade
<div class="hidden sm:block">{{-- 데스크탑: 테이블 --}}</div>
<div class="block sm:hidden">{{-- 모바일: .card 리스트 --}}</div>
```
- 모바일 카드는 `<a href="..." wire:navigate>` 로 행 클릭 대체
- 차량 목록 `vehicles/index.blade.php`에서 70+ 필드 중 모바일은 핵심 6개만 노출 (차량번호 / 진행상태 / 채널 / 판매가 / 통화 / 담당자)

### 필터 / 폼 select
- `class="w-full sm:w-auto rounded border ..."` — 모바일 풀폭, 데스크탑 자연 폭
- 부가 안내 텍스트는 `hidden sm:block` (모바일 공간 절약)

### 페이지 패딩
- 최상위 컨테이너: `class="p-3 md:p-6"` — 모바일 좌우 여백 절반

### 슬라이드 패널 (차량 편집)
- 모바일: 풀화면 (`fixed inset-0 z-50` + translate-x)
- 데스크탑: 우측 사이드 패널 (50~70vw)
- 7탭은 모바일에서도 그대로 — 탭 헤더가 가로 스크롤 가능하도록 `overflow-x-auto`

### 사이드바 drawer (md 분기)
§7 참조. matchMedia(767px) + Alpine `isMobile`/`mobileOpen`/`open` 3상태 + `.sidebar-backdrop`.

## 12. 서류 자동 생성 — system xlsx 자동기입 (2026-05-24 전면 재구축)

> ⚠️ 기존 PDF(dompdf 5종) + CIPL(`VehicleCiplGenerator` 2종)은 **폐기**. 바탕화면 `system` 폴더 9종 xlsx 양식을 `resources/templates/system/` 으로 채택, **노란 배경 셀에만 차량 데이터 자동기입 + 노란 제거**(깔끔한 최종본). 라우트 `GET /erp/vehicles/{id}/documents/{type}` (`erp` 미들웨어) → 컨트롤러 `VehicleDocumentController::show()` 가 전 type 을 `DocumentFiller` 로 단일 처리.

| type | 서류 | 단계 | 채널 |
|---|---|---|---|
| `deregistration` | 자동차말소등록신청서 (별지17호) | 매입 | 전 채널 |
| `deregistration_contract` | 말소 계약서 | 매입 | 전 채널 |
| `poa` | 위임장 | 매입 | 전 채널 |
| `invoice` | Proforma Invoice | 판매 | export |
| `sales_contract` | 판매계약서 (다중차량, 1바이어·단일통화, 30슬롯 확장) | 판매 | export |
| `container_invoice_packing`/`container_contract` | 컨테이너 Invoice&Packing / Contract | 선적 | export |
| `roro_invoice_packing`/`roro_contract` | RORO Invoice&Packing / Contract | 선적 | export |
| `clearance` | 통관 SET (8시트, 구매리스트 마스터→6시트 자동연동) | 통관 | 전 채널 |

### 엔진 — `App\Services\Documents\DocumentFiller`
- `spreadsheet(type)`: 템플릿 로드 → 전 visible 시트 노란셀 정리 → 매핑 셀 기입 → Spreadsheet. `filename(type)` 다운로드명.
- **테넌트별 양식 세트 (멀티회사, 2026-06-13)**: 템플릿 경로 = `resources/templates/{config('company.template_set','system')}/`. 회사정보(상호·계좌·SWIFT·매매업번호)는 매핑이 아니라 **템플릿 셀에 인쇄**돼 있음(`config/company.php`는 dead — 안 읽힘). 회사별로 다르게 찍으려면 `.env COMPANY_TEMPLATE_SET=karaba` + `resources/templates/karaba/`에 9종 사본(회사정보 셀만 교체). ssancar=`system`(default). 재생성=`scripts/generate-karaba-templates.php`(셀별 명시 치환맵, stripHyperlinks+preCalc=false 로 저장). [[project_karaba_multicompany]]
- **노란셀 분기(핵심)**: 수식(`=`)→값 보존·fill만 제거(통관 `=구매리스트!` cascade 보존) / 리터럴+매핑→값 기입·fill 제거 / 매핑없음→**샘플값 비움**·fill 제거(옛 샘플 잔존 방지).
- 병합셀은 좌상단 앵커 기준 기입. numberFormat·스타일 보존. 날짜 `DateTimeInterface→PHPToExcel`. `stripHyperlinks`로 외부링크(WebDAV file://) 잔재 제거(writer 깨짐 방어).
- 저장 `(new Xlsx($ss))->save('php://output')` — preCalc 기본 true → 수식·cross-sheet 자동 재계산(통관 마스터 cascade 의 근거).

### 매핑 = 데이터 — `App\Services\Documents\Mappings\*Mapping.php`
- 각 `::config()` → `['template','sheet','label','cells'=>[좌표=>fn(Vehicle)]]`. 새 서류는 Mapping 추가만(엔진 무수정).
- 공유 resolver `DocValue`: carName(model)·carNameFull(brand+model)·invoiceBuyer/Consignee(export 우선)·consigneeIdValue·confirmedReceived(확정 FP 합)·niceRaw(data_get)·destinationCountry·consigneeBlock·romanizePlate(한글번호판→로마자 "19더9065"→"19DEO9065").

### NICE 연동 — 현재 상태
- `nice_raw`(JSON, cast array): 전용컬럼 없는 NICE 필드(resValidPeriod·resSpecControlNo·maxPower·mtrsFomNm·fomNm)+engineSpec 원본. `DocValue::niceRaw($v,$key)`.
- `deregistration_date`(말소일)·`nice_spec_cylinders`(기통). NICE 연동 완료(`698f0c9`). 기통수·검사종료는 nice_raw 에서 서류 생성 시 파싱(`DocValue::niceCylinders/niceInspectionStart/End`).
- 매매업등록번호(통관 D3·G3)는 NICE 비제공→공란(수기).

### 회사 정보 / 부호 주의
- `config/company.php` — 회사 고정정보(대부분 템플릿에 인쇄돼 있어 신규 매핑은 차량 데이터 위주).
- 인보이스 **TAX D/C 음수**(`-tax_dc`, 양식 SUM 에 더해지므로) / DEPOSIT 양수(확정입금 합) / Invoice No `SC{연월}-{id}` 자동.

### UI — 차량 편집 패널 "서류" 탭
- `editingId !== null` 조건. 그룹: 매입(3, 전채널)·판매(인보이스, export)·선적(4, export)·통관(SET). xlsx 즉시 다운로드. 미저장 차량 → 버튼 비활성.

### 다중차량 선적 서류 (#3, 2026-05-24 완료)
선적 4종은 **선택 N대 → 1서류** 지원. 차량목록 체크박스(export 차량)로 선택 → 상단 액션바 4버튼 → 1서류에 N대 자동 기입(최대 30대).
- **방식 = 오프라인 pre-extend + 런타임 removal-only**. 양식은 `scripts/extend_shipping_templates.php` 로 30슬롯 확장·커밋(1회 사람 검증). 런타임(`DocumentFiller::fillMulti`)은 N대 채우고 미사용 슬롯 `removeRow`+`garbageCollect` 로 트림 → 정확한 크기.
- **⚠️ removeRow 는 footer SUM range 자동축소 안 함**(실측). 그래서 footer 집계수식을 채운영역(`=SUM(I21:I<lastfilled>)`)으로 **removeRow 전에 명시 재기록** → 참조가 전부 제거구간 위라 안전. container_invoice 는 stride-3 명시리스트→range 변환. per-row 수식(`=H`·`=SUM(I,L)`·`=RIGHT(E,6)`·`=F+G`)은 슬롯에 박혀 자동 보존.
- **Mapping 스키마**: 선적 4종은 `header`(슬롯 위/아래 1회, primary 차량)+`multi`(`first`/`stride`/`count`/`footerAggregates`/`slotCells[offset][col]`). 비-선적 type 은 기존 `cells`(단일).
- 라우트 `GET /erp/vehicles/documents/{type}?ids=1,2,3` (`showMulti`, 선적 4종·export 한정·DocumentAccessLog 차량당 1행). 단일 라우트(`{id}/documents/{type}`)는 유지(1슬롯 트림본).
- 양식 슬롯 기하: container_invoice(stride3, footer I/J/K/L row111) / roro_invoice(stride1, footer row51) / contracts(stride1, footer F46·I46·I47). incoterms footer 좌표 확장본 기준: container D112, roro C52.

### 남은 작업
- **통관 SET 다중차량**: 마스터 수식(`=구매리스트!`) 구조라 선적과 별개. 미착수.

## 13. 핵심 비즈니스 로직 공식 (참조용)

### 미수율 분모 — 단일 출처 (회의록 v5 §12, 2026-05-14 확정)

⚠️ **미납률·미수율을 계산하는 곳은 5곳. 모두 아래 단일 출처를 사용한다.** 다른 분모를 새로 도입하지 말 것.

```
sale_total_amount  = sale_price + transport_fee + sale_other_costs
                   + commission + auto_loading - tax_dc
                   (Vehicle::getSaleTotalAmountAttribute)   ← 분모
```

> 💵 **한 글자 차이인데 뜻이 다른 세 합계 — 헷갈리면 돈이 틀어진다** (jin 2026-08-27 확인)
>
> | 이름 | 구성 | 뜻 | 단일 출처 |
> |---|---|---|---|
> | **총판매가** | 판매가 + **운임비** + **기타판매비용** + 커미션 + AL − TAX D/C | 바이어에게 **받을 돈**(미수 분모) | `sale_total_amount` |
> | **정산 기준액** | 판매가 + 커미션 + AL − TAX D/C | 담당자 **마진** 계산 | `Settlement::getSalesAmountKrwAttribute` |
> | **면장 기준액** | 판매가 + **운임비** + 커미션 + AL − TAX D/C | 세관에 신고하는 **물건 값** | `Vehicle::declaration_base_amount` |
>
> - **운임비·기타판매비용은 정산에 안 들어간다** — 회사가 대신 치르고 되받는 돈이지 마진이 아니다.
>   ⇒ B/L 재발급비(55·68·100달러) 같은 후발 비용은 `sale_other_costs` 에 넣으면 **미수만 늘고
>   정산액·면장은 안 움직인다**. 실증(272무4681 실데이터) = 「정산 해부」 문서.
> - **면장만 기타판매비용을 뺀다** — 운임비는 CIF 신고에 들어가므로 남긴다.
> - 🚫 이 셋을 서로 대신 쓰지 말 것. 가드 = `DeclarationExcludesOtherCostsTest`.

```

sale_unpaid_amount = sale_total_amount
                   - Σ finalPayments(confirmed_at IS NOT NULL).amount   ← ⚠️ **확정분만**. type 무관 전량 합산
                       (type = deposit_down 계약금 / interim 중도금 / advance_1 선수금1 /
                        fee 송금수수료(셀러 부담, 2026-05-28 구 'advance_2' 재용도화) / balance 잔금 N건)
                   - Σ receivableHistories(method NOT IN ('deposit','savings')).amount
                       ← 🚨 **'savings' 도 빼야 한다** (Vehicle::MIRRORED_RECEIVABLE_METHODS)
                   - savings_used (적립금 사용 — 크레딧으로 잔금 결제, 2026-07-09 jin)
                   → (0 < 미수 < 1) 이면 0 으로 스냅  ← 외화 소수점 잔차를 완납 처리. 음수(과입금)는 보존
                   (Vehicle::getSaleUnpaidAmountAttribute)  ← 분자
                   ✅ savings_used(적립금 사용) = 이 차량 통화 크레딧으로 잔금 결제 → 미수 차감(2026-07-09).
                      실입금KRW(getSaleReceivedKrwAccumulated)에도 판매환율(FX중립)로 포함 → 2차 환차 대칭.
                      구: "차감 안 함(별도 관리)"이었으나 적립금 돈이 어느 차량 입금에도 안 잡히는 빈틈이라 반영으로 전환.

unpaid_ratio       = sale_unpaid_amount / sale_total_amount  (0~1)
                   sale_total_amount ≤ 0 → null (게이지 비표시)
                   (Vehicle::getUnpaidRatioAttribute)
```

> 🔧 **2026-08-24 정정 — 위 분자 공식이 틀려 있었다.** ①`receivableHistories` 에서 **`deposit` 만** 빼는 것으로
> 적혀 있었으나 코드는 **`['deposit','savings']` 둘 다** 뺀다(`Vehicle::MIRRORED_RECEIVABLE_METHODS`, `Vehicle.php:588`).
> 문서대로 따라 쓰면 **적립금이 이중 차감**된다 — `savings` 회수이력은 `savings_used` 와 **같은 돈의 미러 행**이다
> (실측 heymanerp: `savings_used>0` 8대 중 5대가 미러 행 보유, 금액 정확히 일치).
> ②`finalPayments` 는 **`confirmed_at` 있는 것만** 센다(문서엔 없었다). ③`0<x<1` 스냅이 빠져 있었다.
> 🧭 **그래서 재집계하지 말 것** — 이 세 가지를 다 알고도 손으로 재현하니 16대짜리가 **17대**로 나왔다.
> 원문 = `Vehicle.php:1697-1718`. (ssancar.com 연동 협의 중 발견 — 그쪽은 21대가 나왔었다.)

**5곳 정합표** — 직접 SQL 합산 금지. 반드시 accessor 사용.

| 사용처 | 참조 출처 |
|---|---|
| 차량 목록 미납 배경 게이지 | `$vehicle->unpaid_ratio` |
| 차량 편집 판매 탭 미납률 % | `$vehicle->unpaid_ratio` |
| 채권관리 KPI / 위험도 행 | `sale_unpaid_amount` / `sale_unpaid_amount_krw_cache` |
| 관리자 대시보드 미수금 KPI | 동일 (`sale_unpaid_amount_krw_cache` 합산) |
| **G1 100% B/L 게이트** (2026-05-26 회의 — B/L 발급) | `unpaid_ratio > 0`(미완납) 차단. **B/L 발급은 잔금 100% 완납 필수**. grandfather + `unpaid_export_overrides`(**stage='bl'**, 2026-06-23 분리) 우회 — 관리/관리자 승인(`canApproveUnpaidExport`). ⚠ 'shipping'(선적 진입) 우회로는 안 뚫림. |
| **C5 50% 완화** (G 안건 2026-05-20 — 통관·선적 진입 게이트) | `unpaid_ratio > 0.5` 시만 차단. 외화 환율 미입력 → 별도 메시지. admin `unpaid_export_overrides` **진입 우회**(`Vehicle::hasEntryUnpaidOverride()` = stage∈{clearance,shipping} 중 1건) 우회. 입금률 ≥ 50% 자유 진입 (**B/L과 별개 — 50% 유지**) |

> **미입금 우회 stage — 진입(C5 50%) / B/L(G1 100%) 2계층**:
> - **진입 우회 = clearance ∪ shipping (2026-07-01 jin 통합)**: 통관·선적은 같은 50% 관문이라 **진입 우회 1건이면 둘 다 통과**(`Vehicle::hasEntryUnpaidOverride()`). 이전엔 게이트가 stage를 필드로 판정(반입지 있으면 shipping/없으면 clearance)하고 exact-match해서, **입력 순서에 따라 같은 미수인데 통관·선적 우회를 각각 2번** 승인하던 마찰이 있었음(서버 실증 heymanerp 145나1447). 승인 UI 드롭다운 = **「통관·선적 진입(50%)」 1개(값 `shipping`) + 「B/L 발행(100%)」**. 기존 clearance/shipping 저장행은 게이트가 그대로 인정(데이터 이관 불필요).
> - **B/L 우회 = `bl` (G1 100%, 별개 유지)**: B/L 발행은 진입 우회로 안 뚫림, `stage='bl'` 승인만 통과(2026-06-23 jin). ⇒ <50% 차량 B/L 발행 = 진입(shipping) + bl **2건 필요**(의도된 분리, 화물인도권).
> - `'dhl'` stage 폐기(enum엔 기존행 보존). `UnpaidExportOverride::STAGES=['clearance','shipping','bl']`. 승인 권한 = `canApproveUnpaidExport`(admin·관리).

> KRW 환산은 `sale_unpaid_amount_krw_cache` (Vehicle saving 훅 자동 갱신). 환율 미입력 외화 차량은 `null`로 캐시되며 위험도 평가에서 '환율 미입력 경고' 액션으로 분리.

**집계 미수율 (담당자별·바이어별 TOP10)**: 분자 = `Σ sale_unpaid_amount_krw_cache`, **분모 = `Σ sale_total_amount × exchange_rate`** (부대비용 포함). 환율 0 외화 차량은 분자·분모 둘 다 제외. ⚠️ `sale_price × exchange_rate`만 쓰면 분자(부대비용 포함)와 분모(미포함)가 비대칭 → 의미 없는 비율. 단일 정의 위반 금지.

### 판매 미입금액 (위 단일 출처 정의를 그대로 사용)

```
미입금액 = sale_total_amount - 총입금액
        ↑
        Vehicle::getSaleUnpaidAmountAttribute (분자)
```
✅ `savings_used`(적립금 사용) = **총입금액에 포함**(잔금 결제, 2026-07-09). Buyer×currency 잔액 추적(SavingsStatus USED/REFUND 자동 거래)은 `Vehicle::saved` 훅에서 그대로 유지 — 잔액 차감 + 미수 반영 둘 다 됨.

### 매입 미지급액
```
총매입액 = purchase_price + selling_fee
총지급액 = down_payment + selling_fee_payment + Σ(purchase_balance_payments.amount)
미지급액 = 총매입액 - 총지급액
※ payment_date <= today 만 반영
```

### 정산 (§5와 동일 — 2026-05-21 엑셀 v2)
```
판매금원화        = (sale_price + commission + auto_loading - tax_dc) × exchange_rate
정산판매금원화    = 판매금원화 - cost_total
판매마진          = 정산판매금원화 - (purchase_price + selling_fee)
부가세마진        = purchase_price × 0.09     ← 매도비 제외
총마진            = (판매마진 + 부가세마진) × 0.9   ← 부가세 10% 차감

정산액:
  - 프리랜서 (ratio)    = 총마진 × (settlement_ratio / 100)    default 50
  - 사내직원 (per_unit) = per_unit_amount                       default 100,000

실지급액          = 정산액 - 서류비 - other_deduction
서류비:
  - 프리랜서 = 50,000  (Settlement::FREELANCE_DOCUMENT_FEE)
  - 사내직원 = 0
```

### cost_total
```
cost_total = cost_deregistration + cost_license + cost_towing + cost_carry
           + cost_shoring + cost_insurance + cost_transfer + cost_extra1 + cost_extra2
```

### 적립금 잔액
```
EARNED / REFUND       → balance += savings
USED                  → balance -= savings  (음수 검증 — DB CHECK)
ADJUSTMENT / CANCELLED → balance += savings  (양/음수 모두 가능)
```

## 14. 최근 UX/업무 규칙 (2026-07-06)

### 금액 입력 공통 UX
- `resources/js/app.js` 의 `input[data-money]` 문서 위임 핸들러가 금액칸에 실시간 콤마를 붙인다. Livewire morph/wire:navigate 뒤에도 동작해야 하므로 개별 input init 대신 문서 위임 유지.
- 금액칸에서 `+` 키는 정수부를 `×1000`, `-` 키는 `÷1000`(floor) 처리한다. 즉 5,000 → `+` → 5,000,000, 5,000,000 → `-` → 5,000.
- 적용 대상은 실제 금액 필드(`*_str`, 잔금 `amount`)만이다. 연식·주행거리·cc·환율·ID 같은 숫자 입력에 `data-money`를 붙이지 말 것.

### 날짜 입력 공통 UX
- 차량 편집의 날짜칸은 `input[data-date]` + flatpickr이다. `20260717`처럼 8자리 입력 후 Enter/blur 하면 `2026-07-17`로 정규화되고, 달력 선택도 가능하다.
- 슬라이드 패널/동적 잔금 행은 나중에 렌더되므로 `focusin` 지연 init + `morph.updated` 재스캔 패턴을 유지한다.

### 차량목록 검색
- 차량목록 검색은 차량번호·브랜드·차종·소유자·수출신고번호·차대번호 끝 6자리와 함께 `vessel_name`, `container_number`도 검색한다. 선적 문의는 VSL/컨테이너 번호로도 찾을 수 있어야 한다.

### 채권 결제대기 10일 유예 + 선적전/후 미수 pivot=출고일 (jin 2026-07-18)

> 🔀 **2026-08-20 갱신 — 「이미 떠났나」의 단일 출처는 `Vehicle::departed()` = 출고일 **또는** B/L 이다**
> (`app/Models/Vehicle.php:2536` / `notDeparted()` `:2544`). 아래 절은 **07-18 판**이라 출고일만 말한다.
> 거래완료면 재고에서 빠지는데 **출고일은 재고관리에서만 찍혀서 찍을 화면이 없었다**(heymanerp 거래완료 100대 중 **92대 공란**).
> 🚫 **조건을 옮겨 적지 말고 그 스코프를 쓸 것.** 상세 = 메모리 `project_departed_pivot`.
> ⚠️ 자동채움된 출고일은 **선적일 복사**라 실제보다 평균 10일 늦다(`Vehicle.php:675-679`) — **불리언으로만 쓰고 날짜로 쓰지 말 것.**

- `Vehicle::RECEIVABLE_GRACE_DAYS = 10`.
- **선적전/후 미수 pivot = `warehouse_out_date`(출고일)** — 구 pivot=`bl_loading_location`(반입지). 사용자 규칙: 반입지 입력했어도 출고 전이면 **항구 주차장 대기 = 선적전 미수**. 실제 출항(출고일 찍힘) = 선적후 미수.
- 선적 전(출고 전, `warehouse_out_date` 없음) 미수는 `sale_date + 10일` 전까지 `grace`(결제대기)로 보고 채권/선적전 미수 알림에서 제외한다.
- 선적 후(출고 = 출항) 미수는 유예 없이 즉시 채권이다.
- **단일출처 반영 지점(전부 출고일 pivot)**: `Vehicle::getReceivableRiskComputedAttribute` · `scopeExcludeReceivableGrace` · `scopeOnlyReceivableGrace` · `scopeAction('receivable_before_shipping'/'receivable_after_shipping')` · 채권관리 `receivables/index`(classification 인라인) · 관리자 대시보드 `receivableKpis`(classification 인라인) · **`AlimtalkDepositCash`(보증금 독촉 대상, 2026-07-30 합류)**. 알림톡 daily/weekly·InternalPortal은 scope 경유(자동).
- 🚢 **"선적됐나"를 `bl_loading_location`(반입지)으로 판정하지 말 것 — 돈 관점에선 항상 출고일.** 반입지는 **항구 주차장에 세우려고 먼저 찍는다**(RORO 「선적대기 허용 항로」 = `Port::allow_shipping_wait`, 현재 DURRESS/ALBANIA). 2026-07-23 에 만든 `AlimtalkDepositCash` 가 `whereNull('bl_loading_location')` 을 써서 **입금 0원인 차의 독촉이 "주차했다"는 이유로 조용히 꺼져 있었다**(실측 heymanerp 9대 중 7대, 5대가 입금 0%). 2026-07-30 출고일로 교정. ⚠️ **pivot 을 07-18 에 정했는데 5일 뒤 만든 코드가 안 따라온 사례** — 새 쿼리를 쓸 때 이 목록을 먼저 볼 것. 반입지는 **진행상태(v4 cascade)** 판정에만 쓴다.
- 시간 경과에 따른 `grace` → 채권 전환은 야간 `vehicles:rebuild-caches`(05:00)로 반영된다. **⚠️ pivot 변경 배포 직후 = 기존 데이터 1회 cache rebuild 필요**(`receivable_risk` 캐시가 옛 pivot 기준). 또한 이미 출항했지만 `warehouse_out_date` 미입력인 기존 차량은 출고일을 채워야 선적후로 잡힌다(미입력이면 선적전으로 표시 — 규칙상 정상).
- 채권관리 위험도 필터에 `grace` 옵션이 있으므로 결제대기 차량만 따로 확인 가능하다.

### 재고 2분류 (jin 2026-07-18)
- `erp/inventory` = `Vehicle::scopeInStock`(매입완납 + 출고일 없음) 기반. 카테고리 필터:
  - **일반재고**(`scopeGeneralStock`) = 재고 중 미판매(`sale_price ≤ 0`) — 바이어 미정 투기매입. 등록=바이어 없이 매입만(A안, chk_sale_required는 sale_price>0일 때만 buyer 강제라 자연 통과). `?create=1`로 신규 매입 등록 패널 진입.
  - **선적전 재고**(`scopePreShippingStock`) = 재고 중 판매됨(`sale_price > 0`) — 항구 대기, 출고 전.
  - 판매 시 sale_price>0 되면 일반→선적전 자동 편입. 출고일 찍히면 재고 이탈.
- 표시: 입고일(매입완납일 computed)·선적일(`shipping_date`)·출고일(`warehouse_out_date`) 컬럼. 일반재고 권장 초과 뱃지(표시만): `GENERAL_STOCK_PRICE_CAP`=2억 초과 / `GENERAL_STOCK_SELL_MONTHS`=입고 3개월 경과.
- **출고완료**(`category=shipped_out`, 2026-07-28) = `whereNotNull('warehouse_out_date')`. `inStock()` 과 배타적이라 스코프를 분기한다(매입완납 조건 없음 — 이미 재고를 떠난 차).

### 재고 보관위치 + 표시컬럼 (jin 2026-07-28)
- **보관위치** = `vehicles.stock_location`(string 20, indexed) + `stock_location_note`. **enum 아님**(야적장은 늘어난다, §8 #4).
  - 🏷️ **회사별로 다르다 — 단일 출처 = `Vehicle::stockLocations()`**(jin 2026-08-19). 공통 `['홈플','화물','야드']` / **karaba `['쇼링','항입고','야드']`**(`Setting::isKaraba()` 분기, `defaultPurchaseCosts()` 와 같은 패턴).
  - 🚫 상수(`STOCK_LOCATIONS`)를 화면에서 **직접 참조하지 말 것** — 버튼·필터·저장 검증 중 한 곳만 상수를 보면 «버튼엔 있는데 저장이 안 되는» 형태로 갈린다. 가드 = `InventoryStockLocationTest::test_karaba_*`.
  - ⚠️ 목록에서 값을 **빼는** 변경은 그 값이 이미 저장된 차량을 확인할 것(필터·표시에서 사라진다). 2026-08-19 karaba 전환 시엔 보관위치 보유 차량이 0대라 무영향이었다.
- **저장은 출고일과 같은 「적용」 1회** (`applyWarehouseOut` — 이름은 출고일 시절 그대로). 즉시저장 아님 = 오클릭 방지 원칙 유지. 위치·비고·출고일이 한 트랜잭션.
- ⚠️ **draft 폴백 필수**: 위치 draft(`stockLocation[$id]`)는 목록 렌더(`inventoryVehicles` computed)에서 DB 값으로 채워진다. 클릭 판정을 draft 만 보면 **2페이지 이후처럼 아직 안 채워진 차량에서 해제가 안 된다**(이미 '야드'인 차의 [야드]를 눌러도 다시 야드로 설정됨). 키가 없으면 DB 값을 조회해 판단할 것. **같은 구조의 draft 를 새로 만들 때도 동일 주의.**
- **필터는 다중선택** — `#[Url(as:'loc')] array $locationFilters`. 고른 위치들의 OR + `'__none'`(미지정)도 함께 선택 가능. 전부 해제 = 전체.
- **표시컬럼 선택** = 차량관리와 같은 방식(Alpine + `localStorage`, 키 `car_erp_inventory_columns_v1`). 차량관리 리스트 컬럼 대부분을 이식했고 기본 표시는 종전과 동일, 나머지는 off. **차량번호·담당자·진행상태·출고일·보관위치는 항상 표시**(작업 대상이라 끌 이유 없음). ⚠️ 판매총액·미수금액·미수율은 **일부러 제외** — 행마다 무거운 accessor 라 목록 렌더가 잦은 재고관리에 부적합.

### 차량목록 「건수만」 + 선적일·ETA 일괄 (jin 2026-07-28)
- **건수만** = `perPage = 0`(`PER_PAGE_COUNT_ONLY`). 빈 `LengthAwarePaginator([], $count, …)` 를 돌려줘 `total()`·`links()` 쓰는 기존 뷰가 그대로 동작하고, 행 로드·eager load 5종·행별 computed 가 통째로 빠진다. 용도 = **선박 300대를 필터로 추려 엑셀로 받는 흐름**(화면은 건수만 알면 됨).
  - ⚠️ `perPage` 는 `#[Url]` 이라 `?perPage=999` 로 직접 들어온다. **mount 에서도 정규화**할 것 — `updatedPerPage` 는 화면 조작 때만 돈다. 0 이 의미를 가진 뒤로는 잘못된 값이 "이유 없는 빈 목록"이 된다.
  - 엑셀 내보내기는 원래부터 **필터 전체**를 내보낸다(페이지 무관). 단 범위를 「전체」로 바꾸면 `$mirror=false` 라 **검색어·기간·담당자가 전부 무시**되므로 「현재 조건」 유지가 필수.
- **선적일·ETA 일괄** = `BulkVehicleShippingDateService`. 대상은 화면이 준 ID 가 아니라 **`filteredVehicleQuery()` 로 서버에서 재도출**(§8 #26 — 수백 건이라 IDOR 피해가 큼) + 차량별 `canScopeVehicle` 재인가 + 컬럼 화이트리스트(`FIELDS`).
  - `shipping_date`·`eta_date` 를 **`AUDITED_COLUMNS` 에 추가**했다(수백 대를 한 번에 바꾸는데 추적이 없었다). 일괄 출처는 `AuditLog(bulk_shipping_date_applied)` 로 별도 보존.
  - 두 컬럼은 v4 cascade 에 없으므로 `progress_status_cache` 불변. 그래도 bulk update 대신 **모델 update** 를 쓴다(감사·캐시 훅 정상 경로).
  - **8자리(20260801) 서버 정규화 필수** — 그대로 새면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 **1970** 이 된다. 화면(app.js focusout)만 믿지 말 것. 날짜 입력칸은 프로젝트 표준 `data-date`(§14) 를 쓸 것.

## 15. 외부 연동 — 상태 요약

- **NICE API** ✅ 완료 (`698f0c9`, 2026-05-25) — ssancar-erp 미들웨어 경유, `app/Services/NiceApiService.php`. fallback: API 실패해도 모든 NICE 필드 수동 입력 가능. 캐싱 5분. `.env NICE_PROVIDE_URL/TOKEN`. 미구현 2건(기통수·검사종료)은 nice_raw 에서 서류 생성 시 파싱.
  - **게이트웨이 컷오버(PHP)**: 3사 ERP `NICE_PROVIDE_URL` 전부 `https://heymancar.com/provide/api/nice-lookup/` → `54.116.7.83` 박스 `ProvideNiceLookupController` → `NiceDirectClient`. Django 대체(트래픽 0). CSRF 예외 `provide/*`(bootstrap/app.php).
  - **전역 동시 조회 상한**(`ce48448`, 2026-07-26): 조회 1건이 워커를 55~90초 점유 → `ProvideNiceLookupController` 에 슬롯 락 세마포어(cache_locks, DB 원자적). 기본 동시 4(`NICE_MAX_CONCURRENT`, `config services.nice.max_concurrent`), 초과 시 즉시 429(워커 반납), TTL 120s(락 누수 방지). board 가 ERP 경유 조회 시 429 = 재시도 신호. 상세 인프라 = `docs/operations/carerp-infra-2026-07-26.md`.
- **포워딩사 이메일** ❌ 영구 제거 (사용자 결정). 옛 구현 패턴은 archive `SKILLS.md.full` §14 참조.
- **DHL API** — 1단계 스코프 외 (수동 입력만).
- **S3** ✅ 완료 — 버킷 `heysellcar-erp-docs`, IAM, `league/flysystem-aws-s3-v3`, 서명URL. 차량 사진(`vehicle_photos`) + 서류 파일 저장.
- **배포** — AWS Lightsail (`52.79.200.151`). dev→master 머지 시 자동 SSH 배포. 전체 기록 = `docs/operations/aws-deployment-record.md`.
