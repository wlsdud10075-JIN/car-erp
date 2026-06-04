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

```php
// 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × exchange_rate
//   ※ 면장(export_declaration_amount)은 매출 검증용. 정산 공식엔 미포함 (엑셀 AH = SUM(AJ+AM+AN-AO)×AL)
public function getSalesAmountKrwAttribute(): int
{
    $v = $this->vehicle;
    $base = ($v->sale_price ?? 0) + ($v->commission ?? 0) + ($v->auto_loading ?? 0) - ($v->tax_dc ?? 0);
    return (int) ($base * ($v->exchange_rate ?? 0));
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

### 23. 매입 자동 PBP Draft phantom 중복 (2026-06-01)
**원인**: `Vehicle::saved` 훅이 매입가 저장 시 **전액·confirmed_at=NULL 자동 잔금 Draft**를 만드는데($vehicle->update 시점, 폼 동기화 前), 같은 저장에서 계약금/잔금 확정 행이 추가되면 자동 Draft(전액, 대기)가 **중복**으로 재무처리 대기에 잔존 → 확정 시 이중 계상. (salesman.type은 거래완료 시 default 채움에만 쓰이고, 정산 계산은 settlement 자체 type만 봄 — 사내직원을 비율제로 바꾸면 그대로 비율 계산되며 서류비·환차도 ratio 기준으로 따라옴.)
**해결**: 폼 동기화 직후 `canConfirmFinance` 사용자면 자동 Draft를 확정 입금 합과 대조 → 전액 커버 시 삭제, 일부면 남은 미지급으로 축소, 확정 0(순수 Draft)이면 대기 유지. 합산 필터는 미지급 accessor(§13: `payment_date <= now() AND confirmed_at IS NOT NULL`)와 정합. (`vehicles/index::save()`)

### 24. 판매당사자 자동전파 × C5/C4 게이트 회귀 (2026-06-01)
**원인**: 판매 바이어+컨사이니 둘 다 지정 시 `propagateSaleParty()`가 통관(export) 당사자까지 자동 전파했는데, **`export_buyer_id`는 `guardStageOrderForExport`의 `$hasExportInput`(통관 진입 신호)** 이라 — 판매 시점 자동 채움이 <50% 입금 차량의 판매 저장을 C5로 통째 차단(`ManagementWorkflowChecklistTest:375`가 export_buyer_id 단독으로 C4 발동 검증).
**해결**: 자동전파에서 **통관(export) 당사자 제거**, B/L 당사자(bl_buyer_id — 게이트 트리거 아님)만 전파 유지. 통관 바이어는 실제 통관 단계에서 입력. **교훈: export_buyer_id에 값 넣는 건 "통관 진입"으로 간주됨 — 판매 단계에서 자동으로 채우지 말 것.**

### 25. chk_sale_required — sale_price>0이면 sale_date·buyer_id·exchange_rate>0 필수 (MySQL CHECK)
**원인**: 운영 MySQL CHECK `chk_sale_required = (sale_price=0 OR (sale_date NOT NULL AND buyer_id NOT NULL AND exchange_rate>0))`. 판매가만 넣고 나머지 누락하면 INSERT/UPDATE 실패(4025).
**해결**: 판매가 입력 시 sale_date·buyer·환율 항상 동반. (엑셀 일괄적재처럼 sale_date 없으면 선적일/구입일로 대체, 셋 중 하나라도 못 채우면 판매가 보류=매입만.)

> ERP 신규 발견 버그는 본 §8 하단에 추가 기록 (#26+).

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
| `container_invoice_packing`/`container_contract` | 컨테이너 Invoice&Packing / Contract | 선적 | export |
| `roro_invoice_packing`/`roro_contract` | RORO Invoice&Packing / Contract | 선적 | export |
| `clearance` | 통관 SET (8시트, 구매리스트 마스터→6시트 자동연동) | 통관 | 전 채널 |

### 엔진 — `App\Services\Documents\DocumentFiller`
- `spreadsheet(type)`: 템플릿 로드 → 전 visible 시트 노란셀 정리 → 매핑 셀 기입 → Spreadsheet. `filename(type)` 다운로드명.
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

sale_unpaid_amount = sale_total_amount
                   - Σ finalPayments(type='deposit_down').amount    (계약금)
                   - Σ finalPayments(type='interim').amount         (중도금)
                   - Σ finalPayments(type='advance_1').amount       (선수금1)
                   - Σ finalPayments(type='fee').amount             (송금 수수료 — 셀러 부담, 2026-05-28 구 'advance_2' 재용도화)
                   - Σ finalPayments(type='balance').amount         (잔금 N건)
                   - Σ receivableHistories(method ≠ 'deposit').amount
                   (Vehicle::getSaleUnpaidAmountAttribute)  ← 분자
                   ⚠️ savings_used(적립금 사용)는 차감하지 않음 — 별도 관리

unpaid_ratio       = sale_unpaid_amount / sale_total_amount  (0~1)
                   sale_total_amount ≤ 0 → null (게이지 비표시)
                   (Vehicle::getUnpaidRatioAttribute)
```

**5곳 정합표** — 직접 SQL 합산 금지. 반드시 accessor 사용.

| 사용처 | 참조 출처 |
|---|---|
| 차량 목록 미납 배경 게이지 | `$vehicle->unpaid_ratio` |
| 차량 편집 판매 탭 미납률 % | `$vehicle->unpaid_ratio` |
| 채권관리 KPI / 위험도 행 | `sale_unpaid_amount` / `sale_unpaid_amount_krw_cache` |
| 관리자 대시보드 미수금 KPI | 동일 (`sale_unpaid_amount_krw_cache` 합산) |
| **G1 100% B/L 게이트** (2026-05-26 회의 — B/L 발급) | `unpaid_ratio > 0`(미완납) 차단. **B/L 발급은 잔금 100% 완납 필수**. grandfather + `unpaid_export_overrides`(stage='shipping') 우회 — 관리/관리자 승인(`canApproveUnpaidExport`). |
| **C5 50% 완화** (G 안건 2026-05-20 — 통관 게이트) | `unpaid_ratio > 0.5` 시만 차단. 외화 환율 미입력 → 별도 메시지. admin `unpaid_export_overrides` 우회. 입금률 ≥ 50% 자유 통관 (**B/L과 별개 — 50% 유지**) |

> KRW 환산은 `sale_unpaid_amount_krw_cache` (Vehicle saving 훅 자동 갱신). 환율 미입력 외화 차량은 `null`로 캐시되며 위험도 평가에서 '환율 미입력 경고' 액션으로 분리.

**집계 미수율 (담당자별·바이어별 TOP10)**: 분자 = `Σ sale_unpaid_amount_krw_cache`, **분모 = `Σ sale_total_amount × exchange_rate`** (부대비용 포함). 환율 0 외화 차량은 분자·분모 둘 다 제외. ⚠️ `sale_price × exchange_rate`만 쓰면 분자(부대비용 포함)와 분모(미포함)가 비대칭 → 의미 없는 비율. 단일 정의 위반 금지.

### 판매 미입금액 (위 단일 출처 정의를 그대로 사용)

```
미입금액 = sale_total_amount - 총입금액
        ↑
        Vehicle::getSaleUnpaidAmountAttribute (분자)
```
⚠️ `savings_used`(적립금 사용)는 **총입금액에서 제외** — 별도 관리. Buyer×currency 잔액 추적(SavingsStatus USED/REFUND 자동 거래)은 `Vehicle::saved` 훅에서 그대로 유지.

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

## 14. 외부 연동 — 상태 요약

- **NICE API** ✅ 완료 (`698f0c9`, 2026-05-25) — ssancar-erp 미들웨어 경유, `app/Services/NiceApiService.php`. fallback: API 실패해도 모든 NICE 필드 수동 입력 가능. 캐싱 5분. `.env NICE_PROVIDE_URL/TOKEN`. 미구현 2건(기통수·검사종료)은 nice_raw 에서 서류 생성 시 파싱.
- **포워딩사 이메일** ❌ 영구 제거 (사용자 결정). 옛 구현 패턴은 archive `SKILLS.md.full` §14 참조.
- **DHL API** — 1단계 스코프 외 (수동 입력만).
- **S3** ✅ 완료 — 버킷 `heysellcar-erp-docs`, IAM, `league/flysystem-aws-s3-v3`, 서명URL. 차량 사진(`vehicle_photos`) + 서류 파일 저장.
- **배포** — AWS Lightsail (`52.79.200.151`). dev→master 머지 시 자동 SSH 배포. 전체 기록 = `docs/operations/aws-deployment-record.md`.
