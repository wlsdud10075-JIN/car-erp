# SKILLS - car-erp 기술 문서

재구현 시 필수 구현 패턴·재발 버그 회피·UI 디자인 규약 모음. 환경·권한·도메인 용어는 `CLAUDE.md` 참고.

> 본 문서의 §1·§7·§9·§10·§11·§12 패턴은 GPU CRM(`my-crm/SKILLS.md`)에서 검증된 것을 ERP에 맞게 이식한 것. ERP 신규 패턴은 §2~§6.

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

`progress_status_rule_version` 분기 — v1=단일 트리거(grandfather), **v2=이중 트리거** (기본값, 신규 row).

```php
public function getProgressStatusAttribute(): string
{
    // 큐 17 — 폐기 컨셉 제거. 10단계.
    // 큐 2.6 — v2 이중 트리거 (캐스케이드 — 다음 단계 = 이전 단계 트리거 AND 현재 단계 트리거).
    $v2 = ((int) ($this->progress_status_rule_version ?? 2)) >= 2;

    if ($v2) {
        if ($this->dhl_request && $this->bl_document) return '거래완료';
        if ($this->bl_document && $this->bl_loading_location) return '선적완료';
        if ($this->bl_loading_location && $this->is_export_cleared) return '선적중';
        if ($this->is_export_cleared && $this->export_declaration_document) return '수출통관완료';
        if ($this->export_buyer_id && $this->shipping_date) return '수출통관중';
    } else {
        // v1 grandfather — 큐 2.6 마이그 이전 row. 단일 트리거.
        if ($this->dhl_request) return '거래완료';
        if ($this->bl_document) return '선적완료';
        if ($this->bl_loading_location) return '선적중';
        if ($this->export_declaration_document) return '수출통관완료';
        if ($this->export_buyer_id && $this->shipping_date) return '수출통관중';
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

차량 70+ 필드를 한 화면에 펼치지 않고 **우측 슬라이드 패널** 내부 7개 탭으로 분산. my-crm `service-orders/edit.blade.php`의 패턴(§10 UI 단계 5) 차용.

| 탭 | 주요 필드 |
|---|---|
| `기본정보` | 차량번호, 주행거리, brand/type/cc/kg, NICE API 등록정보 12개, 제원정보 12개 |
| `매입` | 매입일, 매입담당자, 구입처, 소유자, 매입가, 매도비, **비용 9개** (말소/면허/탁송/캐리/쇼링/보험/이전비/기타1/기타2), 계약금, 잔금 N건, 송금메모 |
| `판매` | 판매일, 통화/환율, 바이어/컨사이니, 판매가, TAX D/C, Commission, 운임비, 입금현황(계약금 + 중도금 + 잔금 N건 + 선수금1/2 + 적립금 사용) |
| `수출통관` | 통관 바이어/컨사이니, 포워딩사, **면장금액(USD)**, 선적일, 도착일자(ETA), RORO/CONTAINER, Port of Loading, 수출신고서 업로드 |
| `선적(B/L)` | 선적 바이어/컨사이니, B/L번호, 컨테이너 No, 반입지, VSL, B/L 문서 업로드 |
| `DHL` | 수취인 정보, 발송인 정보, 중량/크기, DHL 발송신청 체크 |
| `서류` | 말소신청서 / 계약서 / 등록증신청서 / 양도증명서 / Invoice / RO·con CIPL 자동 생성 (별도 단계 구현) |

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

## 5. 정산 마진 computed 패턴

마진은 `settlements` 테이블에 **저장하지 않고 PHP property로 매번 계산**. 환율·면장금액·매입가 변경 시 갱신 로직 불필요.

```php
public function getSalesAmountKrwAttribute(): int
{
    $exportUsd = $this->vehicle->export_declaration_amount ?? 0;
    $transportUsd = $this->vehicle->transport_fee ?? 0;
    $rate = $this->vehicle->exchange_rate ?? 0;
    return (int) (($exportUsd - $transportUsd) * $rate);
}

public function getSettlementSalesKrwAttribute(): int
{
    return $this->sales_amount_krw - $this->vehicle->cost_total;
}

public function getSalesMarginAttribute(): int
{
    return $this->settlement_sales_krw - $this->vehicle->purchase_price;
}

public function getVatMarginAttribute(): int
{
    return (int) ($this->vehicle->purchase_price * 0.09);  // ⚠️ Python의 sales_margin × 0.1 아님
}

public function getTotalMarginAttribute(): int
{
    return $this->sales_margin + $this->vat_margin;
}
```

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

전역 레이아웃은 **단일 사이드바**. 파일: `resources/views/components/layouts/app/sidebar.blade.php` (구현 예정).

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

## 8. 자주 발생한 버그와 해결방법 (my-crm 검증분 이식)

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

### 16. dompdf v3 @font-face — `format('truetype')` 외 모두 무시
**원인**: `vendor/dompdf/dompdf/src/Css/Stylesheet.php::_parse_font_face`가 `format('truetype')`만 등록. `format('opentype')` / `format('woff')` 등은 silent skip → 폰트 미등록 → 한글이 빈 박스
**해결**: `format()` 자체를 빼면 default가 truetype 처리되어 OTF/TTF 모두 등록 가능
```css
/* ❌ 무시됨 */
src: url("...subset.ttf") format('opentype');
/* ✅ 등록됨 */
src: url("...subset.ttf");
```

### 17. dompdf CSS `url()` Windows 백슬래시 미파싱
**원인**: `storage_path()`가 반환하는 `C:\xampp\...` 경로에 백슬래시가 그대로 들어가면 `url()` 파서가 못 읽음 → 폰트 등록 실패
**해결**: Blade에서 `str_replace('\\', '/', storage_path('fonts/...'))`로 forward slash 치환. 또는 `file://` prefix 추가 (Windows 드라이브 문자 `C:`가 protocol로 오인되는 별개 이슈는 facade 사용 시 자동 해결)

### 18. dompdf 한글 동적 서브셋팅 깨짐 → 사전 서브셋이 답
**원인**: `enable_font_subsetting=true`로 켜면 한글 글리프 누락 (cmap mapping 손상). 영문/숫자만 정상 표시
**해결**: 사전 서브셋 폰트(KS X 1001 영역만 추출한 .subset.ttf) 사용 + `enable_font_subsetting=false`. 풀 OTF 4.6MB×2 → 서브셋 TTF 1.55MB×2 (PDF 8.3MB → 1.88MB)
```bash
python -m fontTools.subset NotoSansKR-Regular.otf \
  --unicodes='U+0020-007E,U+00A0-00FF,U+2010-2027,U+3001-3003,U+3008-3011,U+3131-318E,U+AC00-D7A3,U+FF01-FF5E' \
  --output-file=NotoSansKR-Regular.subset.ttf \
  --no-hinting --desubroutinize
```

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

> ERP 신규 발견 버그는 본 §8 하단에 추가 기록 (#21+).

## 9. 구현 패턴

### 상태기반 조회 (차량목록 dateType)
my-crm 접수목록 패턴 차용. ERP 차량 목록은 `dateType` 프로퍼티로 모드 전환:
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
    // ERP 대시보드 액션 5종은 active 차량 한정 (dhl_request=false)
    // 큐 17 — is_disposed 컬럼 제거 (폐기 컨셉 없음).
    $userDashActions = ['purchase_unpaid','sale_unpaid','clearance_needed','shipping_needed','dhl_needed'];
    if (in_array($this->action, $userDashActions, true)) {
        $q = $q->where('dhl_request', false);
    }
    return match ($this->action) {
        'purchase_unpaid'  => $q->where('purchase_price','>',0)->whereRaw('(매입 미지급 식) > 0'),
        'sale_unpaid'      => $q->where('sale_price','>',0)->where('sale_unpaid_amount_krw_cache','>',0),
        'clearance_needed' => $q->where('sale_price','>',0)->where('sale_unpaid_amount_krw_cache','<=',0)->whereNull('export_declaration_document'),
        'shipping_needed'  => $q->whereNotNull('export_declaration_document')->whereNull('bl_document'),
        'dhl_needed'       => $q->whereNotNull('bl_document'),
        // 관리자 대시보드 액션 — active 제한 없이 전체 차량
        'has_sale'         => $q->where('sale_price','>',0),
        'has_purchase'     => $q->where('purchase_price','>',0),
        default            => $q,
    };
}
```

**대시보드 href 빌더**:
```php
$vehiclesUrl = function (string $action) use ($selectedSalesmanId) {
    $url = route('erp.vehicles.index').'?action='.$action;
    if ($selectedSalesmanId) $url .= '&salesmanId='.$selectedSalesmanId;
    return $url;
};
```

**검증 (필수)**: 대시보드 collection 카운트 = vehicles SQL count. tinker로 카드별 비교.
- ERP 대시보드: 김영업/최매입/정수출 × 5액션 = 15케이스 정합성 확인 (커밋 b3a37e4)
- 관리자 대시보드: KPI 4 + 채널 3 + 진행 11 = 18카드 정합성 확인 (커밋 11d9a69)

### 대시보드 → 차량목록 연동 (쿼리 파라미터, 일반)
단순 필터 전달이면 #[Url] 그대로 활용:
```php
// vehicles/index.blade.php
#[Url] public string $dateFrom = '';
#[Url] public string $channelFilter = '';
#[Url] public string $progressFilter = '';
```

### wire:model 선택 기준
- 자동계산 필드 (정산액, 미입금 미리보기): `wire:model.live` 또는 `wire:model.live.debounce.500ms`
- 일반 필드: `wire:model` (deferred, 저장 시 반영)
- 계층 드롭다운 (바이어→컨사이니): `wire:model.live`
- 통화/환율: `wire:model.live` (KRW 환산값 즉시 반영)

### 파이프라인 카운트 스트립 (2종)

큐 2번 (2026-05-12) 회의 결과로 도입한 2가지 스트립 패턴. 두 스트립 모두 `progress_status_cache` 컬럼을 활용해 N+1 없이 1 SQL로 카운트.

**① 대시보드용 10단계 카운트** (`<x-erp.pipeline-strip>` 익명 컴포넌트):
- 10단계(매입중/매입완료/말소완료/판매중/판매완료/수출통관중/수출통관완료/선적중/선적완료/거래완료) 카운트 가로 스트립
- 큐 17 — 폐기 컨셉 제거 (운영상 없음). 11단계 → 10단계.
- 모바일 `overflow-x-auto` 가로 스크롤
- props: `counts` (배열), `urlBuilder` (callable, status→URL), `title`, `subtitle`
- 클릭 → `vehicles?progressFilter=N`. 영업 뷰는 본인 salesman 한정 / 통관·정산·admin은 전체
- 사용처: `erp/dashboard`(헤더 아래) + `admin/dashboard`(`w-progress` 위젯 교체)
- SQL 패턴: `Vehicle::selectRaw('progress_status_cache, COUNT(*) as cnt')->groupBy('progress_status_cache')->pluck('cnt','progress_status_cache')`

**② 차량 편집 패널 1대 흐름도 7노드** (vehicles/index 인라인):
- 매입 / 말소 / 판매 / 입금 / 통관 / 선적 / DHL — 7노드
- 상태: `done`(✓ green) / `warn`(! amber) / `progress`(⋯ blue) / `pending`(- gray)
- 큐 17 — `disabled` 상태 사용처 없어짐 (폐기/채널 분기 모두 제거됨)
- 노드 클릭 → Alpine `tab` 변경 (`@click="tab = '{{ $node['tab'] }}'"`). 패널 헤더 ↔ Tab Nav 사이 위치
- `vehicles/index::progressFlow()` computed에서 상태 계산. `editingId=null`이면 null 반환 → 신규 등록 모드엔 비노출

### JSON 컬럼 활용
- `vehicles.dhl_dimensions` 같은 단순 문자열은 string. 진짜 구조화 데이터(예: `bl_meta`)가 생기면 JSON cast 도입

## 10. UI 디자인 시스템 (app.css 유틸)

UI 단계를 거치며 확립할 공통 유틸. **새 페이지·위젯 만들 때 원시 Tailwind 대신 이 유틸 우선** 사용해 일관성 유지. my-crm `app.css` 색 변수·박스·뱃지 그대로 차용.

### 색 변수 (`@theme`)
- `--color-primary: #7c6fcd` (보라 메인 — my-crm 동일. 회사 BI 결정 시 교체 가능)
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
- **차량 진행상태 5단계 그룹** (큐 17 — 폐기 그룹 제거):
  - `매입중`/`매입완료`/`말소완료` = **`badge-blue`** (매입 단계)
  - `판매중`/`판매완료` = **`badge-purple`** (판매 단계)
  - `수출통관중`/`수출통관완료` = **`badge-amber`** (통관 단계)
  - `선적중`/`선적완료` = **`badge-green`** (선적 단계)
  - `거래완료` = **`badge-gray`** (완료)
- **세부 단계 구분**: 동일 색 안에서 `진행중`/`완료` 텍스트로 표현 (예: "수출통관중" vs "수출통관완료")
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
대시보드 "처리 필요 항목" 리스트의 좌측 dot 색은 **진행단계가 아닌 긴급도**로 통일. 진행단계 색(섹션 헤더 매핑)과 분리해 사용자가 "지금 손대야 할 항목"을 한눈에 식별. (`role기획보안_수정.md` 큐 1번 2026-05-12 회의 결정)
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

## 12. 서류 자동 생성 (단계 11 완료)

라우트: `GET /erp/vehicles/{id}/documents/{type}` — `erp` 미들웨어. 컨트롤러: `VehicleDocumentController::show()`. PDF는 dompdf, Excel은 PhpSpreadsheet 직접 호출 (Service 클래스).

| type | 서류 | 출력 | 채널 분기 |
|---|---|---|---|
| `deregistration` | 자동차말소등록신청서 (별지 17호) | PDF | 모든 채널 |
| `registration_application` | 자동차 등록증 재발급 신청서 | PDF | 모든 채널 |
| `transfer_certificate` | 자동차양도증명서 (별지 16호 매도인용) | PDF | 모든 채널 |
| `invoice` | Proforma Invoice | PDF | **export만** |
| `sales_contract` | EXPORT SALES CONTRACT | PDF | **export만** |
| `ro_cipl` | RO_CIPL (RORO 선적용 CIPL) | .xlsx | **export만** |
| `con_cipl` | con_CIPL (Container 선적용 CIPL) | .xlsx | **export만** |

### 회사 정보 단일 출처
`config/company.php` — 영문/한글 명칭, 주소, TEL/FAX, 사업자번호, 대표 한·영, 자동차매매업자 등록번호, Invoice 입금 은행 정보. **모든 PDF/Excel 템플릿이 여기서 참조** → 향후 변경 시 한 곳만 수정.

서명/도장은 현재 텍스트 placeholder (`seller_signature_text='(Signature)'`). PNG 도입 시 `seller_signature_path`만 storage 경로로 채우면 모든 템플릿이 자동 이미지 출력.

### PDF (dompdf) — 5종 공통 패턴
- `documents/_korean_fonts.blade.php` partial로 폰트 등록 통일 — `@include`로 모든 템플릿이 재사용
- 사전 서브셋 폰트 사용: `storage/fonts/NotoSansKR-{Regular,Bold}.subset.ttf` (§8 #16~#18 참조)
- `enable_font_subsetting=false` (config/dompdf.php) — dompdf 내장 한글 서브셋팅 깨짐 회피
- `Pdf::loadView(...)->setPaper('a4', 'portrait')->download($filename)` — facade 통일 호출
- 1페이지 수렴 필요 시 `page-break-inside: avoid` + 폰트/마진 축소

### Excel (PhpSpreadsheet) — 템플릿 기반 출력
원본 xlsm 디자인(병합·테두리·색·SUM 수식)을 유지하기 위해 **템플릿 사전 추출 → 런타임 셀 채우기** 방식:

1. `resources/templates/{ro,con}_cipl_template.xlsx` — 원본 xlsm에서 시트 1장씩 깨끗하게 추출 (VLOOKUP/TODAY 수식만 제거, SUM은 유지)
2. **`wb.defined_names = DefinedNameDict()` 필수** — 외부 워크북 참조 50+개 잔재 제거 (§8 #19 참조)
3. `App\Services\VehicleCiplGenerator` — `IOFactory::load()` → 셀 직접 쓰기 → `Response::streamDownload()`
4. RO_CIPL은 차량 1행, con_CIPL은 차량 1대당 3행(메인/연료·배기량/공백) — `setCellValue()`로 좌표 직접 지정
5. `=H21`, `=SUM(I21,L21)` 같은 self-ref/SUM은 템플릿에 그대로 둠 → PhpSpreadsheet pre-calc로 cached value 자동 갱신

### Vehicle 모델 — 서류용 신규 컬럼
- `nice_reg_owner_rrn` (string 20, nullable) — 소유자 주민(법인)등록번호. 말소·등록증·양도 3종 PDF가 참조. NICE API에서 자동 채워질 수도, 수동 입력도 가능
- 차량 편집 패널 기본정보 탭에 입력 필드 추가됨

### UI — 차량 편집 패널 "서류" 탭
- `editingId !== null` AND `sales_channel === 'export'` 두 조건으로 7종 노출 분기
- 새 차량(미저장) → 모든 버튼 비활성 + "차량을 먼저 저장" 안내
- 카풀/헤이맨 채널 → 국문 3종만 + 영문/Excel 자리에 "수출 채널만 가능" 안내
- PDF는 `target="_blank"` (새 탭 다운로드, 편집 패널 유지), Excel은 즉시 다운로드

## 13. 핵심 비즈니스 로직 공식 (참조용)

### 미수율 분모 — 단일 출처 (회의록 v5 §12, 2026-05-14 확정)

⚠️ **미납률·미수율을 계산하는 곳은 5곳. 모두 아래 단일 출처를 사용한다.** 다른 분모를 새로 도입하지 말 것.

```
sale_total_amount  = sale_price + transport_fee + sale_other_costs
                   + commission + auto_loading - tax_dc
                   (Vehicle::getSaleTotalAmountAttribute)   ← 분모

sale_unpaid_amount = sale_total_amount
                   - deposit_down_payment        (계약금)
                   - interim_payment             (중도금)
                   - advance_payment1·2          (선수금)
                   - Σ finalPayments.amount      (잔금 N건)
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
| **G1 50% 룰** (큐 9 확장 — B/L 잠금) | `unpaid_ratio > 0.5` 단일 게이트 |

> KRW 환산은 `sale_unpaid_amount_krw_cache` (Vehicle saving 훅 자동 갱신). 환율 미입력 외화 차량은 `null`로 캐시되며 위험도 평가에서 '환율 미입력 경고' 액션으로 분리.

**집계 미수율 (담당자별·바이어별 TOP10)**: 분자 = `Σ sale_unpaid_amount_krw_cache`, **분모 = `Σ sale_total_amount × exchange_rate`** (부대비용 포함). 환율 0 외화 차량은 분자·분모 둘 다 제외. ⚠️ `sale_price × exchange_rate`만 쓰면 분자(부대비용 포함)와 분모(미포함)가 비대칭 → 의미 없는 비율. 단일 정의 위반 금지.

### 판매 미입금액 (위 단일 출처 정의를 그대로 사용)

```
미입금액 = sale_total_amount - 총입금액
        ↑
        Vehicle::getSaleUnpaidAmountAttribute (분자)
```
⚠️ `savings_used`(적립금 사용)는 **총입금액에서 제외** — 별도 관리. Buyer×currency 잔액 추적(SavingsStatus USED/REFUND 자동 거래)은 `Vehicle::saved` 훅에서 그대로 유지. UI 분리는 별도 안건.

### 매입 미지급액
```
총매입액 = purchase_price + selling_fee
총지급액 = down_payment + selling_fee_payment + Σ(purchase_balance_payments.amount)
미지급액 = 총매입액 - 총지급액
※ payment_date <= today 만 반영
```

### 정산 (§5와 동일)
```
판매금원화        = (export_declaration_amount - transport_fee_usd) × exchange_rate
정산판매금원화    = 판매금원화 - cost_total
판매마진          = 정산판매금원화 - purchase_price
부가세마진        = purchase_price × 0.09
총마진            = 판매마진 + 부가세마진
정산액(비율)      = 총마진 × (settlement_ratio / 100)
정산액(건당)      = per_unit_amount
실지급액          = 정산액 - other_deduction
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

## 14. 외부 연동 패턴

### NICE API — 차량정보 자동조회 ★ (필수)

차량번호 입력 시 NICE 자동차정보 서비스 API 호출 → 24개 필드 자동 채움 (Registration 12 + Detail Spec 12).

**Service 클래스 골격**:
```php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NiceApiService
{
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.nice.key', ''),
            apiSecret: config('services.nice.secret', ''),
        );
    }

    /**
     * @return array{registration: array<string, mixed>, spec: array<string, mixed>}|null
     */
    public function lookupVehicle(string $vehicleNumber): ?array
    {
        return Cache::remember(
            "nice_vehicle_{$vehicleNumber}",
            300,  // 5분 캐시 — 동일 차량번호 반복 호출 방지
            fn () => $this->fetch($vehicleNumber),
        );
    }

    private function fetch(string $vehicleNumber): ?array
    {
        try {
            $reg = Http::timeout(5)->post('...registration endpoint...', [...])->json();
            $spec = Http::timeout(5)->post('...spec endpoint...', [...])->json();

            return [
                'registration' => $this->mapRegistration($reg),
                'spec' => $this->mapSpec($spec),
            ];
        } catch (\Throwable $e) {
            Log::warning('NICE API failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function mapRegistration(array $raw): array { /* res_* 12개 필드 매핑 */ }
    private function mapSpec(array $raw): array { /* cbd_lt 등 12개 필드 매핑 */ }
}
```

**`config/services.php`**:
```php
'nice' => [
    'key' => env('NICE_API_KEY'),
    'secret' => env('NICE_API_SECRET'),
],
```

**Livewire 차량 등록 폼에서 호출**:
```php
public function lookupNiceApi(): void
{
    if (empty($this->vehicle_number)) {
        return;
    }

    $result = NiceApiService::fromConfig()->lookupVehicle($this->vehicle_number);

    if ($result === null) {
        $this->dispatch('notify', message: 'NICE API 조회 실패 — 수동 입력 가능', type: 'warning');

        return;
    }

    foreach ([...$result['registration'], ...$result['spec']] as $key => $value) {
        if (property_exists($this, $key)) {
            $this->{$key} = $value;
        }
    }
}
```

**필수 fallback 규칙**:
- API 실패해도 모든 NICE 필드는 **수동 입력 가능**해야 함 (폼 disable 금지)
- API 키 미설정 환경(local 초기)에선 조용히 빈 결과 반환 → 수동 입력 흐름 유지
- 호출은 **명시적 트리거**(버튼 클릭 또는 `vehicle_number` blur)만 — `wire:model.live`로 매 keystroke 호출 금지

### 포워딩사 이메일 자동 발송

수출통관 완료 시 포워딩사 담당자에게 차량정보 + 수출신고서 자동 메일.

**Mailable 클래스**:
```php
namespace App\Mail;

use App\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Attachment, Content, Envelope};
use Illuminate\Queue\SerializesModels;

class ForwardingNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Vehicle $vehicle) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[수출통관] 차량 {$this->vehicle->vehicle_number} 발송 안내",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.forwarding-notice', with: ['vehicle' => $this->vehicle]);
    }

    public function attachments(): array
    {
        if (! $this->vehicle->export_declaration_document) {
            return [];
        }

        return [Attachment::fromPath(storage_path('app/'.$this->vehicle->export_declaration_document))];
    }
}
```

**트리거 — Vehicle 모델 saving 이벤트**:
```php
// app/Models/Vehicle.php
protected static function booted(): void
{
    static::saving(function (Vehicle $vehicle) {
        $becameCleared = $vehicle->is_export_cleared
            && ! $vehicle->getOriginal('is_export_cleared');  // 이번에 막 true로 전환

        if (! $becameCleared) {
            return;
        }
        if ($vehicle->forwarding_email_sent || ! $vehicle->forwarding_company_id) {
            return;
        }

        $email = $vehicle->forwardingCompany?->email;
        if (! $email) {
            return;
        }

        Mail::to($email)->queue(new ForwardingNoticeMail($vehicle));
        $vehicle->forwarding_email_sent = true;  // 재발송 방지
    });
}
```

**`.env` SMTP 설정**:
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="erp@ssancar.com"
MAIL_FROM_NAME="${APP_NAME}"
```

**중요 규칙**:
- `Mail::to()->queue()` 사용 — 발송 실패가 저장 트랜잭션을 깨지 않음 (DB job table 활용 → `php artisan queue:work` 별도 실행 필요)
- `forwarding_email_sent` 한 번 true → **재발송 차단**. 의도된 재발송은 별도 액션(예: 관리자 "재전송" 버튼)에서 명시적으로 false 후 재저장
- 첨부 누락 시에도 메일은 발송 (본문에 "수출신고서 별도 송부" 표기)
- `getOriginal()` 비교로 "막 true로 전환된 건"만 트리거 — 이미 cleared 상태에서 다른 필드만 수정해도 재발송되지 않음

### DHL — 현재 수동 입력만

Python ERP/엑셀 모두 DHL 필드는 **수동 입력 + `dhl_request` 체크** 방식. DHL API 직접 연동 코드는 확인되지 않았음. 1단계 스코프 외 — 차후 도입 시 `app/Services/DhlApiService.php` 추가 예정.

### 배포 (AWS Lightsail)

XAMPP는 로컬 개발 한정. 운영은 AWS Lightsail 권장.

```
로컬 개발                      AWS 배포
─────────────────────────────────────────────────
XAMPP (Apache+PHP+MySQL)  →  Nginx + PHP-FPM + MySQL
git push                  →  git pull + composer install
npm run dev               →  npm run build
.env (local)              →  .env (server, secrets)
                             php artisan queue:work --daemon (포워딩 메일용)
```
