<?php

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    // ── URL 파라미터 ───────────────────────────────────────
    // 큐 16 — channel 파라미터 제거 (sales_channel 단일화 후 채널 탭 무의미).
    #[Url] public string $search = '';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public string $salesmanFilter = '';
    #[Url] public string $buyerFilter = '';
    #[Url] public string $progressFilter = '';
    #[Url] public string $riskFilter = '';        // safe/caution/danger/critical
    #[Url] public string $unpaidRatioMin = '';    // 30 / 50 / 70
    // 큐 10 확장 — G3 미수 분류 (회의록 v5 §G3, 사용자 결정 2026-05-18).
    // '' 전체 / 'before_shipping' 선적전 / 'after_shipping' 선적후 / 'deposit' 디파짓(적립금 사용분).
    #[Url] public string $classification = '';
    // 매입취소 필터 (jin 2026-07-18) — '' 전체 / 'cancelled' 매입취소만 / 'normal' 정상만
    //   ➕ 'overpaid' 과입금만 (jin 2026-08-25). 실측 5대 전부 cancel=none 이라 매입취소와 안 겹친다.
    //   ⚠️ 과입금은 미수가 **음수**라 「완납」 탭에만 있다 — 고르면 탭을 같이 바꿔 준다(updatedCancelFilter).
    #[Url] public string $cancelFilter = '';

    // KPI 카드 통화 표시 — '' 전체(₩ 환산 합계) / 'USD'·'JPY'·'KRW'… 그 통화 차량만 판매시점 원금액 합계(재환산 없음).
    //   목록은 그대로 두고 상단 KPI 카드만 통화별로 본다 (jin 2026-07-16).
    #[Url] public string $displayCurrency = '';

    #[Url] public int $perPage = 10;

    // 판매탭 잠금 잔금 '채권관리에서 수정' 진입 시 해당 차량 패널 자동 오픈 (jin 2026-07-07).
    #[Url] public ?int $openVehicle = null;

    // ── 슬라이드 패널 (회수 이력) ──────────────────────────
    public bool $showPanel = false;
    public ?int $selectedVehicleId = null;

    // 채권담당자 지정
    public string $managerIdInput = '';

    // 과입금 전환 사유 — **2차 정산 마감 차량에서만** 요구한다(jin 2026-08-26).
    //   마감 전엔 자유 정정이 원칙이라(정산 락 개편 2026-07-24) 묻지 않는다.
    public string $overpayReason = '';

    // 회수 이력 입력 폼
    public ?int $historyEditId = null;
    public string $hCollectedAt = '';
    public string $hCollectorId = '';
    public string $hMethod = 'deposit';
    public string $hAmount = '';
    public string $hExchangeRate = '';   // 입금(deposit) 환율 편집 (Phase 3, 외화만)
    public string $hNote = '';

    public function mount(): void
    {
        // 큐 14-2 보강 — admin + 정산/관리 role 접근 허용 (모니터링 광범위 + 회수 책임자).
        if (! auth()->user()?->canViewReceivables()) {
            abort(403, __('receivable.forbidden'));
        }

        // 🚫 기간 기본값을 주지 않는다 (jin 2026-08-20). 미수는 "지금 못 받고 있는 돈"(재고)이라
        //    기간으로 자를 대상이 아니다. 게다가 자르는 컬럼이 매입일이라 **오래 못 받은 돈일수록
        //    먼저 화면에서 사라졌다**(실측 heymanerp: 3개월 기본값 때문에 미수 3대 834만원이 목록에서 누락).
        //    기본값이 없어야 채권관리 = 관리자 대시보드 = 대표 알림톡 세 곳의 숫자가 같아진다.
        //    필터 자체는 남겨 둔다 — 사용자가 명시적으로 넣으면 그때만 좁혀진다.

        // 판매탭 잠금 잔금 → '채권관리에서 수정' 진입: 해당 차량 수정 패널 바로 오픈 (재검색 불필요).
        if ($this->openVehicle) {
            try {
                $this->openPanel($this->openVehicle);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * 검색어 변경 시 페이지 리셋 (Livewire 자동 훅 — 이 화면은 wire:model.live 바인딩).
     * ⚠️ 이름을 search() 로 두면 $search 프로퍼티와 충돌해 호출조차 안 된다(wire:click 도 죽음).
     *    상세 = tests/Feature/VoltPropertyMethodCollisionTest.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * 담당자를 바꾸면 바이어 선택을 검사한다 — 남겨두면 「A 담당자 + B 의 바이어」 조합이 되어
     * **조용히 0건**이 나온다. 사용자는 필터를 건드린 적이 없으니 이유를 못 찾는다.
     */
    /**
     * 과입금을 고르면 탭도 「완납」으로 옮긴다.
     *
     * 🧭 과입금은 미수가 음수라 기본 탭(채권 전체 = 미수 > 0)과 **조건이 충돌해 0건**이 된다.
     *    조용히 조건만 덮어쓰면 「왜 여기선 다르지」가 되므로, **눈에 보이는 탭을 바꿔** 화면이 스스로 설명하게 한다.
     */
    public function updatedCancelFilter(): void
    {
        if ($this->cancelFilter === 'overpaid') {
            $this->classification = 'paid_up';
        }
        $this->resetPage();
    }

    public function updatedSalesmanFilter(): void
    {
        if ($this->buyerFilter !== '' && ! $this->buyers->contains('id', (int) $this->buyerFilter)) {
            $this->buyerFilter = '';
        }
        unset($this->buyers);
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    // 큐 16 — setChannel() 제거 (채널 단일화).

    #[Computed]
    public function vehicles()
    {
        return $this->buildQuery()
            ->with(['exportBuyer', 'buyer', 'salesman', 'receivableManager'])
            ->orderByDesc('sale_unpaid_amount_krw_cache')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function summary(): array
    {
        // 결제대기(grace) 제외 — 총 채권금액(총판매액·총입금·총미수)은 grace 미포함 (jin 2026-07-06).
        //   목록(vehicles)에는 grace 차량이 결제대기 뱃지로 계속 보이되, 채권 총액 집계에서만 빠진다.
        //   총미수만 빼면 total_paid = 총판매-총미수 가 grace 만큼 부풀어 안 맞으므로 base 전체에서 제외.
        // ⚠️ **KPI 는 분류 탭을 타지 않는다** — `buildQuery(false)` (jin 2026-08-20).
        //    「전체」탭을 채권(미수>0)으로 좁힌 뒤에도 미수율 분모는 **완납 포함 판매총액**이어야 한다.
        //    완납을 분모에서 빼면 17.1% 가 **49.5%** 로 튄다(실측) — 그게 바로 대표 알림톡이 이상했던 원인이다.
        //    탭을 바꿔도 KPI 가 안 흔들리는 편이 "어느 화면에서 보든 같은 숫자" 라는 목적에도 맞는다.
        $base = $this->buildQuery(false)->excludeReceivableGrace();
        $cur = $this->displayCurrency;   // '' = 전체(₩ 환산) / 통화코드 = 그 통화 차량만 원금액

        // 총미수는 **미수>0 인 행만** 합산한다. 초과입금(미수 음수)까지 더하면 탭 합계와 어긋난다
        //   (실측 heymanerp: 초과입금 5대 −308만원 때문에 9.61억이 9.58억으로 찍혔다). 초과입금은 완납 탭에서 따로 본다.
        $unpaidOnly = fn ($q) => (clone $q)->where('sale_unpaid_amount_krw_cache', '>', 0);


        // 통화별 보기: 재환산 없이 그 통화 차량의 판매시점 원금액 합산 (jin 2026-07-16 — "그때 찍힌 금액 그대로").
        //   전체 보기: 기존대로 행 단위 KRW 환산 후 합산.
        if ($cur !== '') {
            $base = (clone $base)->where('currency', $cur);
            $totalSale = (clone $base)->get()->sum(fn ($v) => $v->sale_total_amount);
            $totalUnpaid = $unpaidOnly($base)->get()->sum(fn ($v) => $v->sale_unpaid_amount);
        } else {
            $totalSale = (clone $base)->get()->sum(function ($v) {
                $total = $v->sale_total_amount;

                return $v->currency === 'KRW' ? $total : $total * ($v->exchange_rate ?: 0);
            });
            $totalUnpaid = (int) $unpaidOnly($base)->sum('sale_unpaid_amount_krw_cache');
        }
        // 비중(미수 총액 대비) — 대표 알림톡과 **같은 계산**. 통화 필터 시엔 그 통화 차량끼리의 비중이 된다.
        //   ⚠️ $totalUnpaid 가 정해진 뒤에 정의한다(값 캡처).
        $shareOf = function ($q) use ($cur, $totalUnpaid): ?float {
            if ($totalUnpaid <= 0) {
                return null;
            }
            $q = (clone $q)->excludeReceivableGrace();
            $part = $cur !== ''
                ? (float) (clone $q)->where('currency', $cur)->get()->sum(fn ($v) => $v->sale_unpaid_amount)
                : (float) (clone $q)->sum('sale_unpaid_amount_krw_cache');

            return round($part / $totalUnpaid * 100, 1);
        };

        $totalPaid = max(0, (int) $totalSale - (int) $totalUnpaid);
        $riskCount = (clone $base)->whereIn('receivable_risk', ['danger', 'critical'])->count();

        // 결제대기(grace) — 채권 총액에선 제외됐지만 별도 카드로 보여줘 정합 확인 (jin 2026-07-06).
        //   base 는 grace 제외본이라, grace 는 buildQuery(목록·grace 포함) 에서 따로 집계.
        $graceQuery = $this->buildQuery(false)->onlyReceivableGrace()->where('sale_unpaid_amount_krw_cache', '>', 0);
        if ($cur !== '') {
            $graceRows = (clone $graceQuery)->where('currency', $cur)->get();
            $graceUnpaid = $graceRows->sum(fn ($v) => $v->sale_unpaid_amount);
            $graceCount = $graceRows->count();
        } else {
            $graceUnpaid = (int) (clone $graceQuery)->sum('sale_unpaid_amount_krw_cache');
            $graceCount = (clone $graceQuery)->count();
        }

        // 키 이름은 하위호환(_krw) 유지 — 전체 모드는 KRW 값(테스트·기존 동작), 통화 모드는 원금액.
        //   'currency' 로 카드 포맷을 분기한다(fmtSummaryMoney).
        return [
            'currency' => $cur !== '' ? $cur : 'KRW',
            'total_sale_krw' => (int) $totalSale,
            'total_paid_krw' => (int) $totalPaid,
            'total_unpaid_krw' => (int) $totalUnpaid,
            'risk_count' => $riskCount,
            'grace_unpaid_krw' => (int) $graceUnpaid,
            'grace_count' => $graceCount,
            // 미수율·입금률 (jin 2026-08-06) — 위에서 이미 구한 합계를 나누기만 한다.
            //   ⚠️ 분모를 새로 만들지 말 것. $totalSale 은 SKILLS §13 집계식(Σ sale_total_amount × 환율,
            //   환율 0 이면 0 기여 = 사실상 제외)이고 $totalUnpaid 는 그 짝(캐시 null 은 SUM 에서 자동 제외)이라
            //   분자·분모 모집단이 이미 맞춰져 있다. 다른 조합으로 계산하면 의미 없는 비율이 된다.
            //   ⚠️ grace_unpaid_krw 는 base(=grace 제외) 밖의 별도 모수라 이 분모로 나누면 안 된다 → % 없음.
            'unpaid_ratio_pct' => $totalSale > 0 ? round($totalUnpaid / $totalSale * 100, 1) : null,
            'paid_ratio_pct' => $totalSale > 0 ? round($totalPaid / $totalSale * 100, 1) : null,
            // 모수 — "17.1% 가 뭐에 대한 비율인가" 를 화면에 적기 위한 값 (jin 2026-08-20).
            //   이게 없으면 완납이 분모에 들어간다는 사실이 안 보여서, 같은 수치를 계속 다르게 읽게 된다.
            'sold_count' => (clone $base)->count(),
            'unpaid_count' => $unpaidOnly($base)->count(),
            // 미납률 — **미수 차량만** 놓고 본 비율(jin 2026-08-20: "완납은 빼고 잔금 남은 것들끼리
            //   나눠야 채권관리에 의미가 있지 않나"). 미수율(완납 포함)과 나란히 둬야 둘 다 뜻이 산다.
            //   ⚠️ 라벨 없이 두 % 를 붙여 두면 또 섞여 읽힌다 — 각 카드에 모수를 함께 적는다.
            'unpaid_only_sale_krw' => $unpaidOnlySale = (int) round($unpaidOnly($base)->get()->sum(
                fn ($v) => $cur !== '' ? $v->sale_total_amount
                    : ($v->currency === 'KRW' ? $v->sale_total_amount : $v->sale_total_amount * ($v->exchange_rate ?: 0))
            )),
            'default_ratio_pct' => $unpaidOnlySale > 0 ? round($totalUnpaid / $unpaidOnlySale * 100, 1) : null,
            // 선적전·선적후 **비중**(미수 총액 대비) — 대표 알림톡이 보내는 바로 그 두 % 다.
            //   알림톡에서 「선적후 74%」 를 보고 화면을 열면 같은 숫자가 있어야 한다.
            'before_share_pct' => $shareOf(self::applyClassification($this->buildQuery(false), 'before_shipping')),
            'after_share_pct' => $shareOf(self::applyClassification($this->buildQuery(false), 'after_shipping')),
            // 초과입금(미수 음수) = 돌려줘야 할 돈. 완납에 묻혀 있어 아무도 못 보던 것.
            'overpaid_count' => (clone $base)->where('sale_unpaid_amount_krw_cache', '<', 0)->count(),
            'overpaid_krw' => abs((int) (clone $base)->where('sale_unpaid_amount_krw_cache', '<', 0)->sum('sale_unpaid_amount_krw_cache')),
        ];
    }

    /** 채권관리 KPI 카드에 나타나는 통화 옵션(실제 데이터에 존재하는 통화만, 전체 pill 은 blade). */
    #[Computed]
    public function currencyOptions(): array
    {
        // KRW 는 '전체(₩)' 가 기본 환산 기준이라 제외 — 외화만 pill 로 (jin 2026-07-16).
        return (clone $this->buildQuery())
            ->select('currency')->distinct()->pluck('currency')
            ->filter(fn ($c) => $c && $c !== 'KRW')->sort()->values()->all();
    }

    /**
     * KPI 카드 금액 표시 — displayCurrency 에 따라 포맷.
     *   전체('')·KRW → 기존 @krw 축약(억/만) + '원'. 그 외 통화 → 통화코드 + 정확 금액.
     */
    public function fmtSummaryMoney(int|float $amount): \Illuminate\Support\HtmlString
    {
        $cur = $this->displayCurrency;
        if ($cur === '' || $cur === 'KRW') {
            $tag = \App\Support\Money::krwTag($amount);

            return new \Illuminate\Support\HtmlString($tag.'<span class="ml-1 text-sm font-normal text-gray-500">'.e(__('receivable.unit_won')).'</span>');
        }
        // 판매탭과 정합 — 소수점 버림(0자리) 표시 (jin 2026-07-20). KRW·외화 동일.
        return new \Illuminate\Support\HtmlString('<span>'.e($cur.' '.number_format($amount, 0)).'</span>');
    }

    #[Computed]
    public function buyers()
    {
        // 담당자를 고르면 그 담당자의 바이어만 (jin 2026-08-25).
        //   실측 heymanerp — 바이어 23명 전원 `salesman_id` 보유, 차량 담당자와 258/261 일치.
        //   ⚠️ 남는 3대는 엑셀 임포트 잔재라 그 담당자에 배정된 바이어가 아직 없다(jin: 지정되면 그때부터 잡힌다).
        //      그 사이엔 「목록엔 차가 있는데 드롭다운은 비는」 상태가 되는데, 그건 배정으로 풀 일이다.
        return Buyer::where('is_active', true)
            ->when($this->salesmanFilter !== '', fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function staff()
    {
        // 회수 담당자 / 채권담당자 셀렉트용 — 모든 활성 사용자
        return User::orderBy('name')->get(['id', 'name', 'permission']);
    }

    #[Computed]
    public function selectedVehicle(): ?Vehicle
    {
        if (! $this->selectedVehicleId) {
            return null;
        }

        return Vehicle::with(['receivableHistories.collector', 'receivableManager', 'salesman', 'buyer', 'exportBuyer'])
            ->find($this->selectedVehicleId);
    }

    public function openPanel(int $vehicleId): void
    {
        $vehicle = Vehicle::find($vehicleId);
        if (! $vehicle) {
            return;
        }

        $this->selectedVehicleId = $vehicleId;
        $this->managerIdInput = $vehicle->receivable_manager_id ? (string) $vehicle->receivable_manager_id : '';

        $this->resetHistoryForm();
        $this->showPanel = true;
    }

    public function closePanel(): void
    {
        $this->showPanel = false;
        $this->selectedVehicleId = null;
        $this->resetHistoryForm();
        unset($this->selectedVehicle);
    }

    public function assignManager(): void
    {
        $vehicle = $this->selectedVehicle;
        if (! $vehicle) {
            return;
        }

        $vehicle->update([
            'receivable_manager_id' => $this->managerIdInput !== '' ? (int) $this->managerIdInput : null,
        ]);

        unset($this->selectedVehicle, $this->vehicles, $this->summary);
        session()->flash('panel_success', __('receivable.manager_assigned'));
    }

    public function editHistory(int $historyId): void
    {
        $h = ReceivableHistory::find($historyId);
        if (! $h || $h->vehicle_id !== $this->selectedVehicleId) {
            return;
        }

        $this->historyEditId = $h->id;
        $this->hCollectedAt = $h->collected_at?->format('Y-m-d') ?? '';
        $this->hCollectorId = (string) $h->collector_id;
        $this->hMethod = $h->method;
        $this->hAmount = (string) $h->amount;
        $this->hExchangeRate = $h->exchange_rate !== null ? (string) (float) $h->exchange_rate : '';
        $this->hNote = $h->note ?? '';
    }

    public function saveHistory(): void
    {
        $this->validate([
            'hCollectedAt' => ['required', 'date'],
            'hCollectorId' => ['required', 'exists:users,id'],
            'hMethod' => ['required', 'in:'.implode(',', ReceivableHistory::METHODS)],
            'hAmount' => ['required', 'numeric', 'min:0'],
            'hExchangeRate' => ['nullable', 'numeric', 'min:0'],
            'hNote' => ['nullable', 'string', 'max:500'],
        ], [], [
            'hCollectedAt' => __('receivable.field.date'),
            'hCollectorId' => __('receivable.field.collector'),
            'hMethod' => __('receivable.field.method'),
            'hAmount' => __('receivable.field.amount_attr'),
            'hExchangeRate' => __('receivable.field.rate'),
            'hNote' => __('receivable.field.memo'),
        ]);

        $vehicle = $this->selectedVehicle;
        if (! $vehicle) {
            return;
        }

        $payload = [
            'vehicle_id' => $vehicle->id,
            'collected_at' => $this->hCollectedAt,
            'collector_id' => (int) $this->hCollectorId,
            'method' => $this->hMethod,
            'amount' => (float) $this->hAmount,
            'exchange_rate' => $this->hExchangeRate !== '' ? (float) $this->hExchangeRate : null,
            'note' => $this->hNote ?: null,
        ];

        // paid 정산 차량엔 '입금(deposit)' 추가 불가 — 미러링이 신규 잔금(FinalPayment) 생성을 시도해
        // FinalPayment::creating(paid) 가드에 막혀 500 + 고아 RH 가 됨. 현금/상계/기타로 안내.
        if ($this->hMethod === 'deposit' && $vehicle->settlements()->where('settlement_status', 'paid')->exists()) {
            $this->addError('hMethod', __('receivable.err_paid_no_deposit'));

            return;
        }

        // 적립금 회수 (2026-07-28) — 바이어 잔액 초과 사용 차단. DB CHECK 로도 막히지만 그건 QueryException
        //   일반 메시지라, 여기서 남은 잔액을 알려준다. 수정 시엔 기존 금액만큼은 이미 빠져 있으므로 되돌려 계산.
        if ($this->hMethod === 'savings') {
            if (! $vehicle->buyer_id) {
                $this->addError('hMethod', __('receivable.savings.no_buyer'));

                return;
            }
            $balance = (float) (\App\Models\SavingsStatus::where('buyer_id', $vehicle->buyer_id)
                ->where('currency', $vehicle->currency)
                ->orderByDesc('id')->first()?->balance ?? 0);
            $prev = $this->historyEditId
                ? (float) (ReceivableHistory::find($this->historyEditId)?->method === 'savings'
                    ? ReceivableHistory::find($this->historyEditId)?->amount ?? 0
                    : 0)
                : 0.0;
            if ((float) $this->hAmount - $prev > $balance + 0.01) {
                $this->addError('hAmount', __('receivable.savings.insufficient', [
                    'balance' => number_format($balance, 2),
                    'currency' => $vehicle->currency,
                ]));

                return;
            }
        }

        // 2차 마감(closed) 정산 차량은 환율 소급 변경 차단 — 프리랜서(ratio) 환차가 이미 확정됐을 수 있음(회계 무결성).
        //   per_unit 사내직원은 환차 미반영이라 실질 영향은 없으나, 프리랜서 대비 방어 가드(2차 마감 차량만 막음).
        if ($this->historyEditId && $this->hMethod === 'deposit'
            && $vehicle->settlements()->where('secondary_status', 'closed')->exists()) {
            $origRate = (float) (ReceivableHistory::find($this->historyEditId)?->exchange_rate ?? 0);
            $newRate = $this->hExchangeRate !== '' ? (float) $this->hExchangeRate : 0.0;
            if (abs($newRate - $origRate) > 0.0001) {
                $this->addError('hExchangeRate', __('receivable.err_closed_no_rate_edit'));

                return;
            }
        }

        // 미러링(saved 훅 → FinalPayment 생성)이 가드 예외를 던지면 RH 도 함께 롤백 → 고아 RH 방지.
        try {
            DB::transaction(function () use ($payload) {
                if ($this->historyEditId) {
                    ReceivableHistory::find($this->historyEditId)?->update($payload);
                } else {
                    ReceivableHistory::create($payload);
                }
            });
        } catch (\DomainException $e) {
            $this->addError('hMethod', $e->getMessage());

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            // DB 제약(금액 overflow 등)이 미러링을 뚫고 온 경우 — 500 대신 친절 안내.
            \Log::warning('ReceivableHistory save QueryException', ['msg' => $e->getMessage()]);
            $this->addError('hMethod', __('receivable.save_failed'));

            return;
        }

        session()->flash('panel_success', $this->historyEditId ? __('receivable.saved_edit') : __('receivable.saved_add'));
        $this->resetHistoryForm();
        unset($this->selectedVehicle, $this->vehicles, $this->summary);
    }

    public function deleteHistory(int $historyId): void
    {
        $h = ReceivableHistory::find($historyId);
        if (! $h || $h->vehicle_id !== $this->selectedVehicleId) {
            return;
        }

        // 미러 삭제 cascade — 연결된 final_payment 가 재무확정(confirmed)이면 FinalPayment::deleting 이
        //   DomainException 을 던진다. try/catch 없으면 500 Ignition(jin 2026-07-08 채권 500 정체).
        //   ⚠️ RH::deleted 훅은 RH 삭제 後 FP 삭제 → 트랜잭션으로 감싸야 FP 실패 시 RH 도 롤백(고아 방지).
        try {
            DB::transaction(function () use ($h) {
                $h->delete();   // saved/deleted 이벤트가 final_payment 미러링 + 캐시 갱신 처리
            });
        } catch (\DomainException $e) {
            session()->flash('panel_error', $e->getMessage());

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::warning('ReceivableHistory delete QueryException', ['id' => $historyId, 'msg' => $e->getMessage()]);
            session()->flash('panel_error', __('receivable.delete_failed'));

            return;
        }
        unset($this->selectedVehicle, $this->vehicles, $this->summary);
        session()->flash('panel_success', __('receivable.deleted'));
    }

    /**
     * 과입금 → 적립금 전환 (jin 2026-07-09).
     *
     * 과입금(음수 미수)된 차량의 초과분을 바이어 적립금(EARNED)으로 옮기고 미수를 0으로 만든다.
     *   ① 초과분만큼 확정 잔금(FinalPayment) 감액 → received 감소 → 미수 0.
     *      정산 paid 여부와 무관하게 회계 잠금을 시스템 우회($allowConfirmedMutation)로 감액한다.
     *      감액 자체(amount old→new)는 FinalPayment::updated 훅이 AuditLog 로 자동 기록.
     *   ② 초과분을 buyer×currency 적립금 풀에 EARNED +초과분 (syncSavingsDeposit).
     *   ③ 전환 사실을 Vehicle 단위 AuditLog(overpay_converted_to_savings)로 기록.
     *
     * 권한 = canConfirmFinance (관리·재무·업무관리자·admin/super) — 채권관리 진입 권한과 동일 범위.
     * 메모: 정산 마진은 판매가 기준이라 과입금 전환은 이미 지급된 정산금에 영향 없음.
     * 안전장치: 초과분이 확정 잔금 총액을 넘으면(기타회수 등 다른 출처 과입금) 자동 처리 대신 차단(수동 확인).
     */
    public function convertOverpayToSavings(): void
    {
        $user = auth()->user();
        abort_unless((bool) $user?->canConfirmFinance(), 403);

        $vehicle = $this->selectedVehicle;
        if (! $vehicle) {
            return;
        }

        $isForeign = $vehicle->currency !== 'KRW';
        $excess = round(-$vehicle->sale_unpaid_amount, $isForeign ? 2 : 0);

        if ($excess <= 0) {
            session()->flash('panel_error', __('receivable.overpay.not_overpaid'));

            return;
        }
        if (! $vehicle->buyer_id) {
            session()->flash('panel_error', __('receivable.overpay.no_buyer'));

            return;
        }

        // 2차 정산 마감(closed) 후 — **사유를 쓰면 통과**한다 (jin 2026-08-26).
        //
        // 🔑 원래는 무조건 차단이었다. 그건 이 버튼을 만든 2026-07-09 의 규칙이고,
        //    **2026-07-24 정산 락 개편에서 「마감 후엔 사유를 남기고 정정」으로 완화**됐는데
        //    이 소비자만 안 따라왔다(`FinalPayment::updating` 은 그때 토큰 방식으로 갱신됐다).
        //    그래서 잠금을 풀어도 이 버튼은 안 열렸다 — 여긴 토큰을 안 보기 때문이다.
        //
        // ⚠️ 마감 전에는 사유를 묻지 않는다. 개편의 요지가 «마감 전 자유 수정»이라
        //    거기에 마찰을 더하면 취지에 역행한다.
        // 🚫 승인 사다리는 두지 않는다(jin) — 사유만 남기고 본인이 진행한다.
        //    권한 `canApprove` = admin·업무관리자·role'관리' — jin 이 지목한 그 그룹 그대로다.
        $closed = $vehicle->hasClosedSecondarySettlement();
        $reason = trim($this->overpayReason);
        if ($closed) {
            if (! $user->canApprove()) {
                session()->flash('panel_error', __('receivable.overpay.closed_denied'));

                return;
            }
            if (mb_strlen($reason) < VehicleLedgerUnlockService::MIN_REASON_LENGTH) {
                session()->flash('panel_error', __('receivable.overpay.reason_required',
                    ['n' => VehicleLedgerUnlockService::MIN_REASON_LENGTH]));

                return;
            }
        }

        // 확정 잔금(최근 입력분부터)으로 초과분 커버 — 마지막 입금이 초과분인 게 일반적.
        //   초과분이 확정 잔금 총액을 넘으면(기타회수/이체 등 다른 출처) 안전 차단.
        //   transfer 연결 잔금(append-only)은 감액 대상 제외 — 커버 못 하면 exceeds_confirmed 로 걸림.
        $confirmedFps = $vehicle->finalPayments()
            ->whereNotNull('confirmed_at')
            ->whereNull('transfer_id')
            ->where('amount', '>', 0)
            ->orderByDesc('id')
            ->get();
        if ($excess > (float) $confirmedFps->sum('amount') + 0.001) {
            session()->flash('panel_error', __('receivable.overpay.exceeds_confirmed'));

            return;
        }

        try {
            DB::transaction(function () use ($vehicle, $confirmedFps, $excess, $user, $closed, $reason) {
                // ① 초과분만큼 확정 잔금 감액 (큰 것부터, 회계 잠금 시스템 우회)
                $remaining = $excess;
                FinalPayment::$allowConfirmedMutation = true;
                try {
                    foreach ($confirmedFps as $fp) {
                        if ($remaining <= 0.001) {
                            break;
                        }
                        $cut = min((float) $fp->amount, $remaining);
                        $newAmount = (float) $fp->amount - $cut;
                        $fp->update(['amount' => $newAmount]);
                        // 미러 deposit 회수이력 금액도 동기화 (query builder — 이벤트/루프 없음, 목록 표시 정합).
                        ReceivableHistory::where('final_payment_id', $fp->id)
                            ->where('method', 'deposit')
                            ->update(['amount' => $newAmount]);
                        $remaining -= $cut;
                    }
                } finally {
                    FinalPayment::$allowConfirmedMutation = false;
                }

                // ② 바이어 적립금 EARNED +초과분
                $vehicle->syncSavingsDeposit($excess);

                // ③ 전환 사실 감사로그 (잔금 감액 old→new 는 FinalPayment::updated 가 별도 기록)
                AuditLog::create([
                    'user_id' => $user->id,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $vehicle->id,
                    'action' => 'overpay_converted_to_savings',
                    'column_name' => 'savings_earned',
                    // 마감 후 전환이면 사유를 남긴다 — 「왜 지급 끝난 차를 건드렸나」가 여기서만 보인다.
                    'old_value' => $closed ? mb_substr($reason, 0, 500) : null,
                    'new_value' => $vehicle->currency.' '.$excess,
                    'ip_address' => request()?->ip(),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::warning('convertOverpayToSavings failed', ['vehicle' => $vehicle->id, 'msg' => $e->getMessage()]);
            session()->flash('panel_error', __('receivable.overpay.failed'));

            return;
        }

        $this->overpayReason = '';
        unset($this->selectedVehicle, $this->vehicles, $this->summary);
        session()->flash('panel_success', __('receivable.overpay.done', ['amount' => $vehicle->currency.' '.number_format($excess, $isForeign ? 2 : 0)]));
    }

    public function resetHistoryForm(): void
    {
        $this->historyEditId = null;
        $this->hCollectedAt = now()->format('Y-m-d');
        $this->hCollectorId = (string) (auth()->id() ?? '');
        $this->hMethod = 'deposit';
        $this->hAmount = '';
        $this->hExchangeRate = '';
        $this->hNote = '';
        $this->resetValidation();
    }

    /**
     * 분류 탭 SQL — 단일 출처 (jin 2026-08-20).
     * 목록·탭 카운트·대시보드 카드가 **같은 정의**를 쓰게 한다. 조건을 옮겨 적으면 갈린다(SKILLS §8 #44).
     *
     * ⚠️ '' (채권 전체) 의 뜻이 바뀌었다 — 구: 판매된 차 전부(완납 포함 250대) / 신: **잔금 남은 차 전부**(76대).
     *    채권 화면의 「전체」가 완납까지 세면 선적전+선적후와 안 맞아 보인다(jin 지적). 완납은 별도 탭으로 뺐다.
     */
    public static function applyClassification($q, string $classification)
    {
        return match ($classification) {
            // 채권 전체 = 유예 + 선적전 + 선적후. 세 탭의 합이 이 숫자와 정확히 맞는다.
            '' => $q->where('sale_unpaid_amount_krw_cache', '>', 0),

            // 결제대기(유예) — 잔금은 남았지만 판매일+유예일 미경과. 채권 총액에선 빠지지만 행방은 보여야 한다.
            'grace' => $q->where('sale_unpaid_amount_krw_cache', '>', 0)->onlyReceivableGrace(),

            // 미수 분류 — pivot=「이미 떠났나」 = 출고일 또는 B/L (jin 2026-07-18 → 08-20).
            //   ⚠️ 조건을 여기 옮겨 적지 말 것 — Vehicle::scopeDeparted/notDeparted 단일 출처(SKILLS §8 #45).
            'before_shipping' => $q->notDeparted()
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->excludeReceivableGrace(),
            'after_shipping' => $q->departed()
                ->where('sale_unpaid_amount_krw_cache', '>', 0),

            'deposit' => $q->where('savings_used', '>', 0),

            // 완납 — 회수이력 조회용(실측 heymanerp 174대 중 81대가 회수이력 보유)+ **초과입금 5대**가
            //   여기 묻혀 있다(미수 음수 = 돌려줘야 할 돈). 목록에서 빨강으로 표시한다.
            'paid_up' => $q->where(fn ($q2) => $q2
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->orWhereNull('sale_unpaid_amount_krw_cache')),

            default => $q,
        };
    }

    /**
     * 공통 쿼리 빌더 — 목록 / 필터링.
     *
     * @param  bool  $withClassification  false = 분류 탭을 빼고(탭 카운트·KPI 분모용)
     */
    private function buildQuery(bool $withClassification = true)
    {
        return Vehicle::query()
            // 큐 16 — sales_channel 단일화로 채널 필터 제거.
            // 채권관리는 판매단계 이후 차량만 (sale_price > 0)
            ->where('sale_price', '>', 0)
            // 선박명(VSL)·컨테이너번호로도 찾을 수 있어야 한다 (jin 2026-07-29) —
            // "그 배에 실린 차들 미수" 처럼 선적 단위로 채권을 묻는 흐름. 차량목록 검색과 같은 기준.
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('vessel_name', 'like', "%{$this->search}%")
                ->orWhere('container_number', 'like', "%{$this->search}%")
            ))
            ->when($this->dateFrom, fn ($q) => $q->where('purchase_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('purchase_date', '<=', $this->dateTo))
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->buyerFilter, fn ($q) => $q->where(function ($q2) {
                $q2->where('buyer_id', $this->buyerFilter)
                   ->orWhere('export_buyer_id', $this->buyerFilter);
            }))
            ->when($this->progressFilter, fn ($q) => $q->where('progress_status_cache', $this->progressFilter))
            ->when($this->riskFilter, fn ($q) => $q->where('receivable_risk', $this->riskFilter))
            // 매입취소 필터 — 취소차도 위약금(sale_price)이 채권으로 잡히므로 기본은 함께 노출, 필터로 분리.
            ->when($this->cancelFilter === 'cancelled', fn ($q) => $q->where('cancel_status', '!=', Vehicle::CANCEL_NONE))
            ->when($this->cancelFilter === 'normal', fn ($q) => $q->where('cancel_status', Vehicle::CANCEL_NONE))
            // 과입금 = 돌려줄 돈. 채권관리 배너·전환 버튼과 같은 기준(`< 0`)을 쓴다.
            //   🚫 차량 편집 판매탭의 `<= -1` 을 쓰지 말 것 — 두 화면이 -0.5 를 다르게 판정하게 된다.
            ->when($this->cancelFilter === 'overpaid', fn ($q) => $q->where('sale_unpaid_amount_krw_cache', '<', 0))
            // 미납률 30/50/70%↑ 필터는 receivable_risk 캐시 컬럼 매핑으로 대신.
            // 정확한 ratio 슬라이더 필요 시 raw SQL로 확장 가능 (현재는 카테고리 매핑으로 충분).
            ->when($this->unpaidRatioMin === '30', fn ($q) => $q->whereIn('receivable_risk', ['caution', 'danger', 'critical']))
            ->when($this->unpaidRatioMin === '50', fn ($q) => $q->whereIn('receivable_risk', ['danger', 'critical']))
            ->when($this->unpaidRatioMin === '70', fn ($q) => $q->where('receivable_risk', 'critical'))
            // 미수 분류 탭 — applyClassification 단일 출처(위). 조건을 여기 옮겨 적지 말 것.
            ->when($withClassification, fn ($q) => self::applyClassification($q, $this->classification));
    }

    /**
     * 큐 10 확장 — G3 분류별 카운트 (탭 라벨 N건 표시).
     * buildQuery 전 단계의 base (sale_price > 0)에서 분류 SQL만 분기.
     */
    public function getClassificationCountsProperty(): array
    {
        // 🐛 구버전은 자체 base(필터 없음)를 써서 **탭 숫자와 목록이 어긋났다** (jin 2026-08-20 발견).
        //    탭엔 「선적전 23」이 떠 있는데 눌러 들어가면 20건 — 기간 필터를 탭만 안 탔기 때문.
        //    이제 buildQuery(false) = 분류만 뺀 같은 필터를 공유한다. 눌렀을 때 그 숫자가 그대로 나온다.
        $counts = [];
        foreach (['', 'grace', 'before_shipping', 'after_shipping', 'deposit', 'paid_up'] as $key) {
            $counts[$key === '' ? 'all' : $key] = self::applyClassification($this->buildQuery(false), $key)->count();
        }

        return $counts;
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 — 모바일 세로 스택, 데스크탑 좌우 분리 --}}
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('receivable.title') }}</h2>
            <p class="text-xs text-gray-500 mt-1">{{ __('receivable.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
                <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
                <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
                <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
            </select>
            <div class="hidden whitespace-nowrap text-xs text-gray-400 sm:block">{{ __('receivable.admin_only') }}</div>
        </div>
    </div>

    {{-- 큐 16 — 채널 탭 제거 (단일 채널). --}}

    {{-- 큐 10 확장 — G3 미수 분류 탭 (회의록 v5 §G3, 사용자 결정 2026-05-18) --}}
    @php $cc = $this->classificationCounts; @endphp
    <div class="card -mb-1 flex flex-wrap items-center gap-2 overflow-x-auto py-2">
        <button wire:click="$set('classification', '')"
                class="tab-pill {{ $classification === '' ? 'is-active' : '' }}">
            {{ __('receivable.tab.all') }} <span class="pill-count">{{ $cc['all'] }}</span>
        </button>
        <button wire:click="$set('classification', 'grace')"
                class="tab-pill {{ $classification === 'grace' ? 'is-active' : '' }}">
            {{ __('receivable.tab.grace') }} <span class="pill-count">{{ $cc['grace'] }}</span>
        </button>
        <button wire:click="$set('classification', 'before_shipping')"
                class="tab-pill {{ $classification === 'before_shipping' ? 'is-active' : '' }}">
            {{ __('receivable.tab.before_shipping') }} <span class="pill-count">{{ $cc['before_shipping'] }}</span>
        </button>
        <button wire:click="$set('classification', 'after_shipping')"
                class="tab-pill {{ $classification === 'after_shipping' ? 'is-active' : '' }}">
            {{ __('receivable.tab.after_shipping') }} <span class="pill-count">{{ $cc['after_shipping'] }}</span>
        </button>
        <button wire:click="$set('classification', 'deposit')"
                class="tab-pill {{ $classification === 'deposit' ? 'is-active' : '' }}">
            {{ __('receivable.tab.deposit') }} <span class="pill-count">{{ $cc['deposit'] }}</span>
        </button>
        {{-- 완납 — 채권은 아니지만 회수이력 조회 + 초과입금(돌려줄 돈) 확인용 (jin 2026-08-20) --}}
        <button wire:click="$set('classification', 'paid_up')"
                class="tab-pill {{ $classification === 'paid_up' ? 'is-active' : '' }}">
            {{ __('receivable.tab.paid_up') }} <span class="pill-count">{{ $cc['paid_up'] }}</span>
        </button>
    </div>

    {{-- 탭 합계가 눈으로 닫히게 (jin 2026-08-20) — 「채권 전체 = 결제대기 + 선적전 + 선적후」.
         구 「전체」는 완납까지 세서 250 이었고, 선적전·선적후와 아귀가 안 맞아 보였다. --}}
    <p class="-mt-2 mb-1 text-[11px] text-gray-400">
        {{ __('receivable.tab_math', [
            'all' => $cc['all'], 'grace' => $cc['grace'],
            'before' => $cc['before_shipping'], 'after' => $cc['after_shipping'],
        ]) }}
        <span class="ml-1">· {{ __('receivable.period_note') }}</span>
    </p>

    {{-- 초과입금 — 완납에 묻혀 아무도 못 보던 「돌려줄 돈」. 있을 때만 뜬다 (jin 2026-08-20). --}}
    @if($this->summary['overpaid_count'] > 0)
    <button type="button" wire:click="$set('classification', 'paid_up')"
            class="card-sm -mt-1 mb-1 flex w-full items-center gap-2 border-red-200 bg-red-50/50 text-left text-[12px] text-red-700 transition hover:bg-red-50">
        <span class="badge badge-red">{{ __('receivable.overpaid_badge') }}</span>
        {{ __('receivable.overpaid_note', [
            'count' => number_format($this->summary['overpaid_count']),
            'amount' => number_format($this->summary['overpaid_krw']),
        ]) }}
    </button>
    @endif

    {{-- 통화 선택 — 재환산 없이 그 통화 차량의 판매시점 원금액 (전체=₩ 환산). 목록은 그대로 (jin 2026-07-16). --}}
    {{-- 외화가 1종이어도 「전체(₩) ↔ 그 통화」는 서로 다른 값이라 고를 이유가 있다(jin 2026-08-06).
         구 조건은 > 1(외화 2종 이상)이라 USD 만 쓰는 회사에서는 줄 자체가 안 보였다. --}}
    @if(count($this->currencyOptions) >= 1)
    <div class="mb-2 flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs text-gray-500">{{ __('receivable.currency_label') }}</span>
        <button wire:click="$set('displayCurrency', '')"
                class="tab-pill {{ $displayCurrency === '' ? 'is-active' : '' }}">{{ __('receivable.currency_all') }}</button>
        @foreach ($this->currencyOptions as $c)
        <button wire:click="$set('displayCurrency', '{{ $c }}')"
                class="tab-pill {{ $displayCurrency === $c ? 'is-active' : '' }}">{{ $c }}</button>
        @endforeach
    </div>
    @endif

    {{-- KPI 5개 (미수는 결제대기 제외, 결제대기는 별도 카드로 정합 표시 — jin 2026-07-06) --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.total_sale') }}</div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{!! $this->fmtSummaryMoney($this->summary['total_sale_krw']) !!}</div>
            {{-- 모수 명시 (jin 2026-08-20) — 미수율의 분모에 **완납 차도 들어간다**는 걸 안 적으면
                 같은 17.1% 를 매번 다르게 읽게 된다. 실제로 대표 알림톡·화면이 어긋난 원인이었다. --}}
            <div class="mt-0.5 text-[11px] text-gray-400">
                {{ __('receivable.kpi.basis_note', [
                    'sold' => number_format($this->summary['sold_count']),
                    'unpaid' => number_format($this->summary['unpaid_count']),
                ]) }}
            </div>
        </div>
        @php
            // 통화 필터를 걸면 KPI 가 원화 환산이 아니라 그 통화 원금액이라(위 summary 주석),
            // % 도 "그 통화끼리" 의 비율이 된다. 숫자는 맞지만 원화 기준으로 오해하지 않게 기준을 붙인다(jin 2026-08-06).
            $ratioBasis = $this->summary['currency'] !== 'KRW'
                ? ' '.__('receivable.kpi.ratio_basis', ['cur' => $this->summary['currency']])
                : '';
        @endphp
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.total_paid') }}</div>
            <div class="mt-1 text-2xl font-bold text-blue-600">{!! $this->fmtSummaryMoney($this->summary['total_paid_krw']) !!}</div>
            @if($this->summary['paid_ratio_pct'] !== null)
                <div class="mt-0.5 text-[11px] text-gray-400">{{ __('receivable.kpi.paid_ratio', ['pct' => $this->summary['paid_ratio_pct']]) }}{{ $ratioBasis }}</div>
            @endif
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.total_unpaid') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-600">{!! $this->fmtSummaryMoney($this->summary['total_unpaid_krw']) !!}</div>
            @if($this->summary['unpaid_ratio_pct'] !== null)
                <div class="mt-0.5 text-[11px] font-medium text-red-400">{{ __('receivable.kpi.unpaid_ratio', ['pct' => $this->summary['unpaid_ratio_pct']]) }}{{ $ratioBasis }}</div>
            @endif
            {{-- 미납률 — 완납을 뺀 「미수 차량끼리」의 비율 (jin 2026-08-20).
                 미수율(완납 포함)과 뜻이 달라 라벨에 모수를 함께 적는다. 안 적으면 두 % 가 섞여 읽힌다. --}}
            @if($this->summary['default_ratio_pct'] !== null)
                <div class="mt-0.5 text-[11px] text-gray-400">
                    {{ __('receivable.kpi.default_ratio', [
                        'pct' => $this->summary['default_ratio_pct'],
                        'count' => number_format($this->summary['unpaid_count']),
                    ]) }}
                </div>
            @endif
            {{-- 대표 알림톡이 보내는 바로 그 두 % — 여기 없으면 카톡을 받고도 화면에서 대조할 수가 없다. --}}
            @if($this->summary['before_share_pct'] !== null && $this->summary['after_share_pct'] !== null)
                <div class="mt-0.5 text-[11px] text-gray-400">
                    {{ __('receivable.kpi.share_split', [
                        'before' => $this->summary['before_share_pct'],
                        'after' => $this->summary['after_share_pct'],
                    ]) }}
                </div>
            @endif
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.grace') }}</div>
            <div class="mt-1 text-2xl font-bold text-gray-500">{!! $this->fmtSummaryMoney($this->summary['grace_unpaid_krw']) !!}</div>
            <div class="mt-0.5 text-[11px] text-gray-400">{{ __('receivable.kpi.grace_hint', ['count' => $this->summary['grace_count']]) }}</div>
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.risk_count') }}</div>
            <div class="mt-1 text-2xl font-bold text-orange-600">{{ $this->summary['risk_count'] }}<span class="ml-1 text-sm font-normal text-gray-500">{{ __('receivable.unit_count') }}</span></div>
        </div>
    </div>

    {{-- 필터 바 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input type="text" wire:model.live.debounce.500ms="search" placeholder="{{ __('receivable.search_ph') }}"
               class="input-filter w-52" />
        <input type="date" wire:model.live="dateFrom" class="input-filter" />
        <span class="text-xs text-gray-400">~</span>
        <input type="date" wire:model.live="dateTo" class="input-filter" />
        <select wire:model.live="salesmanFilter" class="input-filter">
            <option value="">{{ __('receivable.all_salesman') }}</option>
            @foreach ($this->salesmen as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <select wire:model.live="buyerFilter" class="input-filter">
            <option value="">{{ __('receivable.all_buyer') }}</option>
            @foreach ($this->buyers as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
        </select>
        <select wire:model.live="progressFilter" class="input-filter">
            <option value="">{{ __('receivable.all_progress') }}</option>
            @foreach (['판매중','판매완료','선적중','선적완료','통관중','통관완료','거래완료'] as $s)
            <option value="{{ $s }}">{{ __('domain.progress.'.$s) }}</option>
            @endforeach
        </select>
        <select wire:model.live="riskFilter" class="input-filter">
            <option value="">{{ __('receivable.all_risk') }}</option>
            <option value="grace">{{ __('receivable.risk.grace') }}</option>
            <option value="safe">{{ __('receivable.risk.safe') }}</option>
            <option value="caution">{{ __('receivable.risk.caution') }}</option>
            <option value="danger">{{ __('receivable.risk.danger') }}</option>
            <option value="critical">{{ __('receivable.risk.critical') }}</option>
        </select>
        <select wire:model.live="cancelFilter" class="input-filter">
            <option value="">{{ __('receivable.cancel.all') }}</option>
            <option value="cancelled">{{ __('receivable.cancel.only') }}</option>
            <option value="normal">{{ __('receivable.cancel.normal') }}</option>
            <option value="overpaid">{{ __('receivable.cancel.overpaid') }}</option>
        </select>
        <select wire:model.live="unpaidRatioMin" class="input-filter">
            <option value="">{{ __('receivable.ratio_all') }}</option>
            <option value="30">{{ __('receivable.ratio_min', ['percent' => 30]) }}</option>
            <option value="50">{{ __('receivable.ratio_min', ['percent' => 50]) }}</option>
            <option value="70">{{ __('receivable.ratio_min', ['percent' => 70]) }}</option>
        </select>
    </div>

    {{-- 테이블 (데스크탑) --}}
    <div class="card overflow-x-auto hidden sm:block">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                <tr>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.no') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.vehicle_no') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.brand_type') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.vin') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.salesman') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.buyer') }}</th>
                    <th class="py-2 pr-3 text-right">{{ __('receivable.col.sale_total') }}</th>
                    <th class="py-2 pr-3 text-right">{{ __('receivable.col.unpaid') }}</th>
                    <th class="py-2 pr-3 text-right">{{ __('receivable.col.unpaid_ratio') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.progress') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.bl') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.risk') }}</th>
                    <th class="py-2 pr-3 text-left">{{ __('receivable.col.manager') }}</th>
                    {{-- 큐 16 — 계산서1/2 컬럼 제거 (헤이맨/카풀 폐기). --}}
                </tr>
            </thead>
            <tbody>
                @forelse ($this->vehicles as $v)
                @php
                    $rowBg = match($v->receivable_risk) {
                        'critical' => 'bg-red-50',
                        'danger'   => 'bg-orange-50',
                        'caution'  => 'bg-yellow-50',
                        'safe'     => 'bg-blue-50',
                        default    => '',
                    };
                    $riskBadge = match($v->receivable_risk) {
                        'safe'     => 'badge-blue',
                        'caution'  => 'badge-amber',
                        'danger'   => 'badge-amber',
                        'critical' => 'badge-red',
                        default    => 'badge-gray',
                    };
                    // SKILLS §13 단일 출처 — unpaid_ratio accessor 사용 (0~1 또는 null).
                    $unpaidRatio = $v->unpaid_ratio !== null ? round($v->unpaid_ratio * 100, 1) : 0;
                    // 큐 16 — sales_channel 단일 (export) → exportBuyer 우선, fallback buyer.
                    $primaryBuyer = $v->exportBuyer ?? $v->buyer;
                @endphp
                {{-- 큐 14-2 보강 — vehicles와 동일한 미납 게이지 + 호버 툴팁 (data-* 속성으로 JS 자동 처리) --}}
                @php $gaugeRatio = $v->unpaid_ratio; @endphp
                <tr class="cursor-pointer border-b border-gray-100 {{ $gaugeRatio === null ? $rowBg : '' }} hover:bg-violet-50"
                    wire:click="openPanel({{ $v->id }})"
                    @if($gaugeRatio !== null)
                        data-ratio="{{ number_format($gaugeRatio, 6, '.', '') }}"
                        data-unpaid="{{ (int) round($v->sale_unpaid_amount) }}"
                        data-total="{{ (int) round($v->sale_total_amount) }}"
                        data-currency="{{ $v->currency }}"
                    @endif>
                    <td class="py-2 pr-3 text-gray-500">{{ $v->id }}</td>
                    <td class="py-2 pr-3 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-2 pr-3 text-gray-600">{{ trim(($v->brand ?? '').' '.($v->model_type ?? '')) ?: '-' }}</td>
                    <td class="py-2 pr-3 font-mono text-xs text-gray-500">{{ $v->nice_reg_vin ?: '-' }}</td>
                    <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '-' }}</td>
                    <td class="py-2 pr-3 text-gray-600">{{ $primaryBuyer?->name ?? '-' }}</td>
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $v->currency }} {{ number_format($v->sale_total_amount, 0) }}</td>
                    <td class="py-2 pr-3 text-right font-medium text-red-600">{{ $v->currency }} {{ number_format($v->sale_unpaid_amount, 0) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $unpaidRatio }}%</td>
                    <td class="py-2 pr-3">
                        <span class="badge badge-gray">{{ $v->progress_status_cache ? __('domain.progress.'.$v->progress_status_cache) : '-' }}</span>
                        @if($v->isPurchaseCancelled())<span class="badge {{ $v->cancel_status === \App\Models\Vehicle::CANCEL_CLOSED ? 'badge-gray' : 'badge-red' }}">{{ $v->cancel_status_label }}</span>@endif
                    </td>
                    <td class="py-2 pr-3 text-center text-xs">{{ $v->bl_document ? '✓' : '-' }}</td>
                    <td class="py-2 pr-3"><span class="badge {{ $riskBadge }}">{{ $v->receivable_risk ? __('receivable.risk.'.$v->receivable_risk) : '-' }}</span></td>
                    <td class="py-2 pr-3 text-gray-600">{{ $v->receivableManager?->name ?? '-' }}</td>
                    {{-- 큐 16 — tax_invoice 컬럼 제거 --}}
                </tr>
                @empty
                <tr>
                    <td colspan="13" class="py-8 text-center text-gray-400">{{ __('receivable.empty') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 카드 리스트 (모바일) --}}
    <div class="block sm:hidden space-y-2">
        @forelse ($this->vehicles as $v)
        @php
            $rowBg = match($v->receivable_risk) {
                'critical' => 'bg-red-50 border-red-200',
                'danger'   => 'bg-orange-50 border-orange-200',
                'caution'  => 'bg-yellow-50 border-yellow-200',
                'safe'     => 'bg-blue-50 border-blue-200',
                default    => 'bg-white border-gray-200',
            };
            $riskBadge = match($v->receivable_risk) {
                'safe'     => 'badge-blue',
                'caution'  => 'badge-amber',
                'danger'   => 'badge-amber',
                'critical' => 'badge-red',
                default    => 'badge-gray',
            };
            // SKILLS §13 단일 출처 — unpaid_ratio accessor 사용 (0~1 또는 null).
            $unpaidRatio = $v->unpaid_ratio !== null ? round($v->unpaid_ratio * 100, 1) : 0;
            // 큐 16 — sales_channel 단일 (export) → exportBuyer 우선, fallback buyer.
            $primaryBuyer = $v->exportBuyer ?? $v->buyer;
        @endphp
        <div wire:click="openPanel({{ $v->id }})"
             class="cursor-pointer rounded-lg border px-3 py-3 transition hover:bg-violet-50 {{ $rowBg }}">
            {{-- 상단: 차량번호 + 위험도 --}}
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400">#{{ $v->id }}</span>
                        <span class="text-sm font-medium text-gray-800">{{ $v->vehicle_number }}</span>
                    </div>
                    @if($v->nice_reg_vin)
                    <span class="block truncate font-mono text-[11px] text-gray-400" title="{{ $v->nice_reg_vin }}">{{ $v->nice_reg_vin }}</span>
                    @endif
                </div>
                <span class="badge {{ $riskBadge }} shrink-0">{{ $v->receivable_risk ? __('receivable.risk.'.$v->receivable_risk) : '-' }}</span>
            </div>
            {{-- 중간: 바이어 + 담당자 --}}
            <div class="mt-1 flex items-center justify-between gap-2 text-xs text-gray-500">
                <span class="truncate" title="{{ $primaryBuyer?->name }}">{{ $primaryBuyer?->name ?? __('receivable.buyer_none') }}</span>
                <span>{{ $v->salesman?->name ?? '-' }}</span>
            </div>
            {{-- 하단: 미납금 + 미납률 --}}
            <div class="mt-2 flex items-end justify-between gap-2">
                <div class="text-xs text-gray-500">
                    {{ __('receivable.mobile_unpaid') }} <span class="font-medium text-red-600">{{ $v->currency }} {{ number_format($v->sale_unpaid_amount, 0) }}</span>
                </div>
                {{-- 데스크탑엔 컬럼 헤더가 있어 무슨 숫자인지 알지만 모바일엔 없어서 라벨을 붙인다 --}}
                <div class="text-xs text-gray-700">
                    <span class="text-gray-400">{{ __('receivable.col.unpaid_ratio') }}</span> {{ $unpaidRatio }}%
                </div>
            </div>
        </div>
        @empty
        <div class="rounded-lg border border-dashed border-gray-200 px-3 py-8 text-center text-sm text-gray-400">{{ __('receivable.empty') }}</div>
        @endforelse
    </div>

    {{-- 페이지네이션 --}}
    <div class="mt-2">{{ $this->vehicles->links() }}</div>
</div>

{{-- ── 슬라이드 패널: 회수 이력 ────────────────────────── --}}
@if ($showPanel && $this->selectedVehicle)
@php $sv = $this->selectedVehicle; @endphp
<div class="fixed inset-0 z-50 flex justify-end" wire:keydown.escape="closePanel">
    {{-- backdrop --}}
    <div class="absolute inset-0 bg-black/40" wire:click="closePanel"></div>

    {{-- panel --}}
    <div class="relative ml-auto flex h-full w-full max-w-[640px] flex-col overflow-y-auto bg-white shadow-xl">
        {{-- 헤더 --}}
        <div class="sticky top-0 z-10 border-b border-gray-200 bg-white px-5 py-4">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400">{{ __('receivable.history') }}</div>
                    <div class="mt-0.5 text-lg font-bold text-gray-800">{{ $sv->vehicle_number }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">
                        {{ $sv->brand }} {{ $sv->model_type }} · {{ __('receivable.col.salesman') }} {{ $sv->salesman?->name ?? '-' }}
                    </div>
                </div>
                <button type="button" wire:click="closePanel" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- 미납 요약 --}}
            @php
                $rb = match($sv->receivable_risk) {
                    'safe'     => 'badge-blue',
                    'caution'  => 'badge-amber',
                    'danger'   => 'badge-amber',
                    'critical' => 'badge-red',
                    default    => 'badge-gray',
                };
            @endphp
            <div class="mt-3 grid grid-cols-3 gap-2">
                <div class="card-sm">
                    <div class="text-xs text-gray-500">{{ __('receivable.col.sale_total') }}</div>
                    <div class="mt-0.5 text-sm font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($sv->sale_total_amount, 0) }}</div>
                </div>
                <div class="card-sm">
                    <div class="text-xs text-gray-500">{{ __('receivable.col.unpaid') }}</div>
                    <div class="mt-0.5 text-sm font-semibold text-red-600">
                        {{ $sv->currency }} {{ number_format($sv->sale_unpaid_amount, 0) }}
                        {{-- 차량 1대라 §13 accessor 를 그대로 쓴다(집계 분모 계산 불필요). null = 판매가 미입력 --}}
                        @if($sv->unpaid_ratio !== null)
                            <span class="ml-1 text-[11px] font-normal text-red-400">{{ round($sv->unpaid_ratio * 100, 1) }}%</span>
                        @endif
                    </div>
                </div>
                <div class="card-sm">
                    <div class="text-xs text-gray-500">{{ __('receivable.col.risk') }}</div>
                    <div class="mt-0.5"><span class="badge {{ $rb }}">{{ $sv->receivable_risk ? __('receivable.risk.'.$sv->receivable_risk) : '-' }}</span></div>
                </div>
            </div>
        </div>

        @if (session('panel_success'))
        <div class="mx-5 mt-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-700">{{ session('panel_success') }}</div>
        @endif
        @if (session('panel_error'))
        <div class="mx-5 mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ session('panel_error') }}</div>
        @endif

        {{-- 과입금 → 적립금 전환 (음수 미수 = 과입금 시에만) --}}
        @if ($sv->sale_unpaid_amount < 0 && auth()->user()?->canConfirmFinance())
        @php
            $overpayLabel = $sv->currency.' '.number_format(-$sv->sale_unpaid_amount, 0);
            // 2차 마감 차량은 사유를 받는다. 🚫 화면에서 감추지 말 것 — 왜 막혔는지 안 보이면
            //    「눌러도 아무 일이 없다」가 되고, 그게 07-24 이후 실제로 벌어진 상태였다.
            $overpayClosed = $sv->hasClosedSecondarySettlement();
            $overpayAllowed = ! $overpayClosed || auth()->user()?->canApprove();
        @endphp
        <div class="mx-5 mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs font-semibold text-amber-800">{{ __('receivable.overpay.title') }} <span class="font-bold">{{ $overpayLabel }}</span></div>
                    <div class="mt-0.5 text-[11px] text-amber-600">{{ __('receivable.overpay.hint') }}</div>
                </div>
                <button type="button" wire:click="convertOverpayToSavings"
                        wire:confirm="{{ __('receivable.overpay.confirm', ['amount' => $overpayLabel]) }}"
                        @disabled(! $overpayAllowed)
                        class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                    {{ __('receivable.overpay.btn') }}
                </button>
            </div>
            @if ($overpayClosed)
            <div class="mt-2 border-t border-amber-200 pt-2">
                <div class="text-[11px] text-amber-700">{{ __('receivable.overpay.closed_note') }}</div>
                @if ($overpayAllowed)
                <input type="text" wire:model="overpayReason" maxlength="500"
                       placeholder="{{ __('receivable.overpay.reason_ph', ['n' => \App\Services\VehicleLedgerUnlockService::MIN_REASON_LENGTH]) }}"
                       class="input-base mt-1.5 w-full text-xs" />
                @else
                <div class="mt-1 text-[11px] font-semibold text-amber-800">{{ __('receivable.overpay.closed_denied') }}</div>
                @endif
            </div>
            @endif
        </div>
        @endif

        {{-- 채권담당자 지정 --}}
        <div class="px-5 py-4">
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">{{ __('receivable.manager_section') }}</span>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <select wire:model="managerIdInput" class="input-base flex-1">
                    <option value="">{{ __('receivable.manager_unassigned') }}</option>
                    @foreach ($this->staff as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->permission }})</option>@endforeach
                </select>
                <button type="button" wire:click="assignManager" class="btn-primary px-3 py-1.5 text-sm">{{ __('receivable.assign') }}</button>
            </div>
        </div>

        <hr class="section-divider mx-5">

        {{-- 회수 이력 추가/수정 폼 --}}
        <div class="px-5 py-4">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ $historyEditId ? __('receivable.form_title_edit') : __('receivable.form_title_add') }}</span>
            </div>

            <div class="mt-2 grid grid-cols-2 gap-2">
                <div>
                    <label class="label-base">{{ __('receivable.field.date') }} *</label>
                    <input type="date" wire:model="hCollectedAt" class="input-base" />
                    @error('hCollectedAt')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="label-base">{{ __('receivable.field.collector') }} *</label>
                    <select wire:model="hCollectorId" class="input-base">
                        <option value="">{{ __('receivable.field.select') }}</option>
                        @foreach ($this->staff as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                    @error('hCollectorId')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="label-base">{{ __('receivable.field.method') }} *</label>
                    <select wire:model="hMethod" class="input-base">
                        <option value="deposit">{{ __('receivable.method_deposit_full') }}</option>
                        <option value="cash">{{ __('receivable.method.cash') }}</option>
                        <option value="offset">{{ __('receivable.method.offset') }}</option>
                        <option value="other">{{ __('receivable.method.other') }}</option>
                        <option value="write_off">{{ __('receivable.method.write_off') }}</option>
                        <option value="savings">{{ __('receivable.method.savings') }}</option>
                    </select>
                    @error('hMethod')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                    @if ($hMethod === 'savings')
                        @php
                            $savBal = (float) (\App\Models\SavingsStatus::where('buyer_id', $sv->buyer_id)
                                ->where('currency', $sv->currency)->orderByDesc('id')->first()?->balance ?? 0);
                        @endphp
                        <div class="mt-1 text-[11px] text-violet-600">
                            {{ __('receivable.savings.balance_hint', ['balance' => number_format($savBal, 2), 'currency' => $sv->currency]) }}
                        </div>
                    @endif
                </div>
                <div>
                    <label class="label-base">{{ __('receivable.field.amount') }} ({{ $sv->currency }}) *</label>
                    <input type="number" step="0.01" wire:model="hAmount" class="input-base" placeholder="0" />
                    @error('hAmount')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                @if($sv->currency !== 'KRW')
                <div>
                    <label class="label-base">{{ __('receivable.field.rate') }}</label>
                    <input type="number" step="0.0001" wire:model="hExchangeRate" class="input-base" placeholder="0" />
                    @error('hExchangeRate')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                @endif
                <div class="col-span-2">
                    <label class="label-base">{{ __('receivable.field.memo') }}</label>
                    <textarea wire:model="hNote" rows="2" class="input-base" placeholder="{{ __('receivable.memo_ph') }}"></textarea>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-2">
                @if ($historyEditId)
                <button type="button" wire:click="resetHistoryForm" class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
                @endif
                <button type="button" wire:click="saveHistory" class="btn-primary px-4 py-1.5 text-sm">{{ $historyEditId ? __('receivable.btn_edit_save') : __('receivable.btn_add') }}</button>
            </div>
        </div>

        <hr class="section-divider mx-5">

        {{-- 회수 이력 목록 --}}
        <div class="px-5 py-4 pb-8">
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">{{ __('receivable.list_title') }}</span>
            </div>

            @php $histories = $sv->receivableHistories->sortByDesc('collected_at'); @endphp

            @if ($histories->isEmpty())
            <div class="mt-3 rounded border border-dashed border-gray-200 px-3 py-6 text-center text-xs text-gray-400">
                {{ __('receivable.list_empty') }}
            </div>
            @else
            <div class="mt-2 space-y-2">
                @foreach ($histories as $h)
                @php
                    $methodLabel = __('receivable.method.'.$h->method);
                    $methodBadge = match ($h->method) {
                        'deposit' => 'badge-blue',
                        'write_off' => 'badge-red',
                        'savings' => 'badge-purple',
                        default => 'badge-gray',
                    };
                @endphp
                <div class="rounded border border-gray-200 px-3 py-2">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="font-medium text-gray-800">{{ $h->collected_at->format('Y-m-d') }}</span>
                                <span class="badge {{ $methodBadge }}">{{ $methodLabel }}</span>
                                <span class="text-xs text-gray-500">{{ $h->collector?->name ?? '-' }}</span>
                                @if ($h->final_payment_id)
                                <span class="text-xs text-blue-500" title="{{ __('receivable.mirror_title') }}">↔ #{{ $h->final_payment_id }}</span>
                                @endif
                            </div>
                            <div class="mt-1 text-base font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($h->amount, 0) }}</div>
                            @if ($h->note)
                            <div class="mt-0.5 text-xs text-gray-500">{{ $h->note }}</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="editHistory({{ $h->id }})" class="text-xs text-violet-600 hover:underline">{{ __('common.edit') }}</button>
                            <button type="button" wire:click="deleteHistory({{ $h->id }})" wire:confirm="{{ __('receivable.delete_confirm') }}" class="text-xs text-red-500 hover:underline">{{ __('common.delete') }}</button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endif
</div>
