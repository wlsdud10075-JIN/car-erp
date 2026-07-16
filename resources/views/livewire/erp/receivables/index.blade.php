<?php

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
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

        $this->dateFrom = $this->dateFrom ?: now()->subMonths(3)->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');

        // 판매탭 잠금 잔금 → '채권관리에서 수정' 진입: 해당 차량 수정 패널 바로 오픈 (재검색 불필요).
        if ($this->openVehicle) {
            try {
                $this->openPanel($this->openVehicle);
            } catch (\Throwable $e) {
            }
        }
    }

    public function search(): void
    {
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
        $base = $this->buildQuery()->excludeReceivableGrace();
        $cur = $this->displayCurrency;   // '' = 전체(₩ 환산) / 통화코드 = 그 통화 차량만 원금액

        // 통화별 보기: 재환산 없이 그 통화 차량의 판매시점 원금액 합산 (jin 2026-07-16 — "그때 찍힌 금액 그대로").
        //   전체 보기: 기존대로 행 단위 KRW 환산 후 합산.
        if ($cur !== '') {
            $base = (clone $base)->where('currency', $cur);
            $rows = (clone $base)->get();
            $totalSale = $rows->sum(fn ($v) => $v->sale_total_amount);
            $totalUnpaid = $rows->sum(fn ($v) => $v->sale_unpaid_amount);
        } else {
            $totalSale = (clone $base)->get()->sum(function ($v) {
                $total = $v->sale_total_amount;

                return $v->currency === 'KRW' ? $total : $total * ($v->exchange_rate ?: 0);
            });
            $totalUnpaid = (int) (clone $base)->sum('sale_unpaid_amount_krw_cache');
        }
        $totalPaid = max(0, (int) $totalSale - (int) $totalUnpaid);
        $riskCount = (clone $base)->whereIn('receivable_risk', ['danger', 'critical'])->count();

        // 결제대기(grace) — 채권 총액에선 제외됐지만 별도 카드로 보여줘 정합 확인 (jin 2026-07-06).
        //   base 는 grace 제외본이라, grace 는 buildQuery(목록·grace 포함) 에서 따로 집계.
        $graceQuery = $this->buildQuery()->onlyReceivableGrace()->where('sale_unpaid_amount_krw_cache', '>', 0);
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
        $decimals = $cur === 'JPY' ? 0 : 2;

        return new \Illuminate\Support\HtmlString('<span>'.e($cur.' '.number_format($amount, $decimals)).'</span>');
    }

    #[Computed]
    public function buyers()
    {
        return Buyer::where('is_active', true)->orderBy('name')->get();
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
            'hMethod' => ['required', 'in:deposit,cash,offset,other,write_off'],
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

        // 2차 정산 마감(secondary closed) 차량은 소급 변경 금지 (SKILLS §28) — 환차·이월이 이미
        //   산정·지급된 뒤라 확정 잔금 감액이 지급값과 어긋난다. 개별 잠금해제로만 정정.
        if ($vehicle->settlements()->where('secondary_status', 'closed')->exists()) {
            session()->flash('panel_error', __('receivable.overpay.secondary_closed'));

            return;
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
            DB::transaction(function () use ($vehicle, $confirmedFps, $excess, $user) {
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
                    'old_value' => null,
                    'new_value' => $vehicle->currency.' '.$excess,
                    'ip_address' => request()?->ip(),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::warning('convertOverpayToSavings failed', ['vehicle' => $vehicle->id, 'msg' => $e->getMessage()]);
            session()->flash('panel_error', __('receivable.overpay.failed'));

            return;
        }

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
     * 공통 쿼리 빌더 — KPI / 목록 / 필터링에 모두 사용.
     */
    private function buildQuery()
    {
        return Vehicle::query()
            // 큐 16 — sales_channel 단일화로 채널 필터 제거.
            // 채권관리는 판매단계 이후 차량만 (sale_price > 0)
            ->where('sale_price', '>', 0)
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
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
            // 미납률 30/50/70%↑ 필터는 receivable_risk 캐시 컬럼 매핑으로 대신.
            // 정확한 ratio 슬라이더 필요 시 raw SQL로 확장 가능 (현재는 카테고리 매핑으로 충분).
            ->when($this->unpaidRatioMin === '30', fn ($q) => $q->whereIn('receivable_risk', ['caution', 'danger', 'critical']))
            ->when($this->unpaidRatioMin === '50', fn ($q) => $q->whereIn('receivable_risk', ['danger', 'critical']))
            ->when($this->unpaidRatioMin === '70', fn ($q) => $q->where('receivable_risk', 'critical'))
            // 큐 10 확장 — G3 미수 분류 탭 (Vehicle::scopeAction과 동일 SQL 출처).
            ->when($this->classification === 'before_shipping', fn ($q) => $q
                ->whereIn('progress_status_cache', ['매입중', '매입완료', '말소완료', '판매중', '판매완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                // 결제대기(grace) 제외 — 선적전 채권은 판매일+10일 지난 것만(scopeExcludeReceivableGrace 단일 출처).
                ->excludeReceivableGrace())
            ->when($this->classification === 'after_shipping', fn ($q) => $q
                ->whereIn('progress_status_cache', ['선적중', '선적완료', '통관중', '통관완료', '수출통관중', '수출통관완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0))
            ->when($this->classification === 'deposit', fn ($q) => $q
                ->where('savings_used', '>', 0));
    }

    /**
     * 큐 10 확장 — G3 분류별 카운트 (탭 라벨 N건 표시).
     * buildQuery 전 단계의 base (sale_price > 0)에서 분류 SQL만 분기.
     */
    public function getClassificationCountsProperty(): array
    {
        $base = Vehicle::query()->where('sale_price', '>', 0);

        return [
            'all' => (clone $base)->count(),
            'before_shipping' => (clone $base)
                ->whereIn('progress_status_cache', ['매입중', '매입완료', '말소완료', '판매중', '판매완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->excludeReceivableGrace()
                ->count(),
            'after_shipping' => (clone $base)
                ->whereIn('progress_status_cache', ['선적중', '선적완료', '통관중', '통관완료', '수출통관중', '수출통관완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->count(),
            'deposit' => (clone $base)->where('savings_used', '>', 0)->count(),
        ];
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
    </div>

    {{-- 통화 선택 — 재환산 없이 그 통화 차량의 판매시점 원금액 (전체=₩ 환산). 목록은 그대로 (jin 2026-07-16). --}}
    @if(count($this->currencyOptions) > 1)
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
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.total_paid') }}</div>
            <div class="mt-1 text-2xl font-bold text-blue-600">{!! $this->fmtSummaryMoney($this->summary['total_paid_krw']) !!}</div>
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">{{ __('receivable.kpi.total_unpaid') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-600">{!! $this->fmtSummaryMoney($this->summary['total_unpaid_krw']) !!}</div>
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
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $v->currency }} {{ number_format($v->sale_total_amount, $v->currency === 'KRW' ? 0 : 2) }}</td>
                    <td class="py-2 pr-3 text-right font-medium text-red-600">{{ $v->currency }} {{ number_format($v->sale_unpaid_amount, $v->currency === 'KRW' ? 0 : 2) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $unpaidRatio }}%</td>
                    <td class="py-2 pr-3"><span class="badge badge-gray">{{ $v->progress_status_cache ? __('domain.progress.'.$v->progress_status_cache) : '-' }}</span></td>
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
                    <span class="block truncate font-mono text-[11px] text-gray-400">{{ $v->nice_reg_vin }}</span>
                    @endif
                </div>
                <span class="badge {{ $riskBadge }} shrink-0">{{ $v->receivable_risk ? __('receivable.risk.'.$v->receivable_risk) : '-' }}</span>
            </div>
            {{-- 중간: 바이어 + 담당자 --}}
            <div class="mt-1 flex items-center justify-between gap-2 text-xs text-gray-500">
                <span class="truncate">{{ $primaryBuyer?->name ?? __('receivable.buyer_none') }}</span>
                <span>{{ $v->salesman?->name ?? '-' }}</span>
            </div>
            {{-- 하단: 미납금 + 미납률 --}}
            <div class="mt-2 flex items-end justify-between gap-2">
                <div class="text-xs text-gray-500">
                    {{ __('receivable.mobile_unpaid') }} <span class="font-medium text-red-600">{{ $v->currency }} {{ number_format($v->sale_unpaid_amount, $v->currency === 'KRW' ? 0 : 2) }}</span>
                </div>
                <div class="text-xs text-gray-700">{{ $unpaidRatio }}%</div>
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
                    <div class="mt-0.5 text-sm font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($sv->sale_total_amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
                </div>
                <div class="card-sm">
                    <div class="text-xs text-gray-500">{{ __('receivable.col.unpaid') }}</div>
                    <div class="mt-0.5 text-sm font-semibold text-red-600">{{ $sv->currency }} {{ number_format($sv->sale_unpaid_amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
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

        {{-- 과입금 → 적립금 전환 (음수 미수 = 과입금 시에만, 재무 권한) --}}
        @if ($sv->sale_unpaid_amount < 0 && auth()->user()?->canConfirmFinance())
        @php $overpayLabel = $sv->currency.' '.number_format(-$sv->sale_unpaid_amount, $sv->currency === 'KRW' ? 0 : 2); @endphp
        <div class="mx-5 mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs font-semibold text-amber-800">{{ __('receivable.overpay.title') }} <span class="font-bold">{{ $overpayLabel }}</span></div>
                    <div class="mt-0.5 text-[11px] text-amber-600">{{ __('receivable.overpay.hint') }}</div>
                </div>
                <button type="button" wire:click="convertOverpayToSavings"
                        wire:confirm="{{ __('receivable.overpay.confirm', ['amount' => $overpayLabel]) }}"
                        class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                    {{ __('receivable.overpay.btn') }}
                </button>
            </div>
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
                    </select>
                    @error('hMethod')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
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
                            <div class="mt-1 text-base font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($h->amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
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
