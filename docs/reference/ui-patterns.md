# 화면·UI 구현 패턴 (SKILLS §3·§4·§7·§9·§10·§11)

> 차량 패널 탭·잔금 행·사이드바·목록/대시보드 패턴·디자인 유틸·모바일 규약. **화면을 새로 만들거나 고치기 전에 읽는다.**
> 2026-11-23 `SKILLS.md` 에서 옮겨 왔다(본문 그대로, 절 번호도 그대로 — 다른 문서의 「SKILLS §N」은 여기의 같은 번호다).

## 3. 슬라이드 패널 + 7탭 차량 편집

차량 70+ 필드를 한 화면에 펼치지 않고 **우측 슬라이드 패널** 내부 7개 탭으로 분산.

| 탭 | 주요 필드 |
|---|---|
| `기본정보` | 차량번호, 주행거리, brand/type/cc/kg, NICE API 등록정보 12개, 제원정보 12개, 차량 사진/첨부(사진·PDF·Excel·HWP 등, 최대 10건 — `vehicle_photos`) |
| `매입` | 매입일, 매입담당자, 구입처, 소유자, 매입가, 매도비, **비용 10개** (말소/면허/탁송/캐리/쇼링/보험/이전비/**주차료**/기타1/기타2 — 단일 출처 `Vehicle::DISPLAY_COST_FIELDS`), 계약금, 잔금 N건, 송금메모 |
| `판매` | 판매일, 통화/환율, 바이어/컨사이니, 판매가, TAX D/C, Commission, 운임비, 입금현황(계약금 + 중도금 + 잔금 N건 + 선수금1/2 + 적립금 사용) |
| `수출`(key `export`, 🔀 2026-10-20 구 선적+수출통관 합본) | 바이어(판매에서)·컨사이니 한 줄 → ①반입(반입지·포워딩사·서류마감·RORO/CONTAINER·선적항·인코텀즈·도착항) → ②수출신고(번호·면장금액·신고서) → ③선적(선적일·VSL·컨테이너·선박사진) → ④운항(ETA) → ⑤통관(체크). 메모 2칸(선적·통관) |
| `B/L` | B/L번호, 발행일, B/L 방식, 체크빌, B/L 문서 업로드 |
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
