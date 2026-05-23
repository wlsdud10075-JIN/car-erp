<?php

use App\Models\ApprovalRequest;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    // ── 목록 필터 ──────────────────────────────────────────────────
    public string $search = '';

    public string $statusFilter = '';

    public int $salesmanFilter = 0;

    public string $dateFrom = '';

    public string $dateTo = '';

    #[Url] public int $perPage = 10;

    // ── 슬라이드 패널 ─────────────────────────────────────────────
    public bool $showPanel = false;

    public ?int $editingId = null;

    // ── 폼 필드 ───────────────────────────────────────────────────
    public ?int $vehicle_id = null;

    public string $vehicleSearch = '';

    public ?int $salesman_id = null;

    public string $settlement_type = 'ratio';

    public ?float $settlement_ratio = null;

    public ?float $per_unit_amount = null;

    public float $other_deduction = 0;

    public string $settlement_status = 'pending';

    public string $note = '';

    // 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 환율 (수동 입력 또는 자동 fetch 저장).
    public string $exchange_rate_at_close_str = '';

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

    // ── 목록 ──────────────────────────────────────────────────────

    #[Computed]
    public function settlements()
    {
        return Settlement::query()
            ->with(['vehicle.finalPayments', 'vehicle.purchaseBalancePayments', 'salesman', 'latestPayApproval.approver'])
            ->when($this->search, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('vehicle_number', 'like', "%{$this->search}%")
            ))
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '>=', $this->dateFrom)
            ))
            ->when($this->dateTo, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '<=', $this->dateTo)
            ))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::orderBy('name')->get(['id', 'name']);
    }

    // 2026-05-20 #2 피드백 — 영업담당자별 합계 (인원별 솔팅 + 합계 KPI).
    // 현재 statusFilter / dateFrom / dateTo 동일 적용 (목록 SQL 과 일치).
    // computed accessor (total_margin / settlement_amount / actual_payout) 사용 → PHP 집계.
    #[Computed]
    public function salesmanSummaries(): array
    {
        $all = Settlement::query()
            ->with(['vehicle', 'salesman:id,name'])
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '>=', $this->dateFrom)
            ))
            ->when($this->dateTo, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '<=', $this->dateTo)
            ))
            ->get();

        return $all->groupBy('salesman_id')->map(function ($group, $salesmanId) {
            $first = $group->first();

            return [
                'salesman_id' => $salesmanId,
                'salesman_name' => $first->salesman?->name ?? '미지정',
                'count' => $group->count(),
                'total_margin_sum' => (int) $group->sum('total_margin'),
                'settlement_amount_sum' => (int) $group->sum('settlement_amount'),
                'actual_payout_sum' => (int) $group->sum('actual_payout'),
            ];
        })->sortByDesc('actual_payout_sum')->values()->toArray();
    }

    public function setSalesmanFilter(int $id): void
    {
        $this->salesmanFilter = $this->salesmanFilter === $id ? 0 : $id;
        $this->resetPage();
    }

    // ── 패널 차량 검색 ────────────────────────────────────────────

    #[Computed]
    public function vehicleSearchResults()
    {
        if (strlen($this->vehicleSearch) < 2) {
            return collect();
        }

        return Vehicle::query()
            ->where('vehicle_number', 'like', "%{$this->vehicleSearch}%")
            ->with('salesman:id,name')
            ->limit(8)
            ->get(['id', 'vehicle_number', 'salesman_id']);
    }

    #[Computed]
    public function selectedVehicle(): ?Vehicle
    {
        if (! $this->vehicle_id) {
            return null;
        }

        return Vehicle::find($this->vehicle_id);
    }

    // ── 패널 마진 실시간 계산 ────────────────────────────────────

    #[Computed]
    public function marginData(): array
    {
        $v = $this->selectedVehicle;
        if (! $v) {
            return [];
        }

        // 2026-05-21 정산 공식 재구조 — 엑셀 v2 기준 (Settlement 모델 accessor 와 동일 공식).
        // 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × exchange_rate (면장 미포함)
        $saleBase = (float) ($v->sale_price ?? 0)
            + (float) ($v->commission ?? 0)
            + (float) ($v->auto_loading ?? 0)
            - (float) ($v->tax_dc ?? 0);
        $rate = (float) ($v->exchange_rate ?? 0);
        $salesAmountKrw = (int) ($saleBase * $rate);

        $settlementSalesKrw = $salesAmountKrw - (int) ($v->cost_total ?? 0);

        // 판매마진 = 정산판매금원화 - (purchase_price + selling_fee)  ← 매입합계
        $purchaseTotal = (int) ($v->purchase_price ?? 0) + (int) ($v->selling_fee ?? 0);
        $salesMargin = $settlementSalesKrw - $purchaseTotal;

        $vatMargin = (int) (($v->purchase_price ?? 0) * 0.09);
        $totalMargin = (int) (($salesMargin + $vatMargin) * 0.9);   // × 0.9 = 부가세 10% 차감

        // 정산액 — type 별 자동 분기 + NULL fallback default
        $settlementAmount = 0;
        if ($this->settlement_type === 'ratio') {
            $ratio = ($this->settlement_ratio ?? null) !== null && (float) $this->settlement_ratio > 0
                ? (float) $this->settlement_ratio
                : \App\Models\Settlement::FREELANCE_RATIO_DEFAULT;
            $settlementAmount = (int) ($totalMargin * ($ratio / 100));
        } elseif ($this->settlement_type === 'per_unit') {
            $settlementAmount = ($this->per_unit_amount ?? null) !== null && (int) $this->per_unit_amount > 0
                ? (int) $this->per_unit_amount
                : \App\Models\Settlement::EMPLOYEE_PER_UNIT_DEFAULT;
        }

        // 서류비 — 프리랜서(ratio)만 50,000 자동 차감
        $documentFee = $this->settlement_type === 'ratio'
            ? \App\Models\Settlement::FREELANCE_DOCUMENT_FEE
            : 0;

        $actualPayout = $settlementAmount - $documentFee - (int) ($this->other_deduction ?? 0);

        // 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 closed + 프리랜서 시 환차 1:1 반영.
        // Settlement::getActualPayoutAttribute 와 동일 정책 — 편집 패널 미리보기 정합.
        $exchangeDiff = 0;
        if ($this->editingId && $this->settlement_type === 'ratio') {
            $settlement = Settlement::find($this->editingId);
            if ($settlement
                && $settlement->secondary_status === 'closed'
                && $settlement->exchange_difference_krw !== null) {
                $exchangeDiff = (int) $settlement->exchange_difference_krw;
                $actualPayout += $exchangeDiff;
            }
        }

        return compact(
            'salesAmountKrw', 'settlementSalesKrw', 'salesMargin',
            'vatMargin', 'totalMargin', 'settlementAmount',
            'documentFee', 'actualPayout', 'exchangeDiff'
        );
    }

    /**
     * 회의확장씬 #6+7 보강 (2026-05-23) — 정산 KRW 명세 (1차/입금/2차/환차).
     *
     * 사용자 명세: "1차정산·2차정산·환차익 계산 로직 그대로 화면에 나와야".
     *
     * 흐름:
     *   - 1차 정산금원화 = (sale_price + commission + auto_loading - tax_dc) × vehicle.exchange_rate
     *   - 입금 시점 KRW 합 = Σ(잔금 row × row 환율) = sale_received_krw_accumulated accessor (단일 출처)
     *   - 2차 정산 시점 KRW 합:
     *       · closed → vehicle 입금 시점 + exchange_difference_krw 저장값 역산
     *       · pending/null → 외화 합 × 현재 환율 (참고용 미리보기)
     *   - 환차 = 2차 KRW - 입금 시점 KRW
     *   - KRW currency 차량 → 환차 없음 ({is_krw_vehicle: true})
     *   - 환율 조회 실패 → rate_unavailable: true
     */
    #[Computed]
    public function krwBreakdown(): array
    {
        $v = $this->selectedVehicle;
        if (! $v) {
            return [];
        }

        $saleBase = (float) ($v->sale_price ?? 0)
            + (float) ($v->commission ?? 0)
            + (float) ($v->auto_loading ?? 0)
            - (float) ($v->tax_dc ?? 0);
        $primaryKrw = (int) ($saleBase * (float) ($v->exchange_rate ?? 0));
        $receivedKrw = (int) $v->sale_received_krw_accumulated;
        $isKrwVehicle = ($v->currency ?? 'KRW') === 'KRW';

        $settlement = $this->editingId ? Settlement::find($this->editingId) : null;
        $secondaryStatus = $settlement?->secondary_status;

        $base = [
            'is_krw_vehicle' => $isKrwVehicle,
            'primary_krw' => $primaryKrw,
            'received_krw' => $receivedKrw,
            'status' => $secondaryStatus,
        ];

        if ($isKrwVehicle) {
            return $base;
        }

        // closed: 저장된 환차 사용 (확정값)
        if ($settlement && $secondaryStatus === 'closed' && $settlement->exchange_difference_krw !== null) {
            $storedDiff = (float) $settlement->exchange_difference_krw;

            return array_merge($base, [
                'secondary_krw' => (int) ($receivedKrw + $storedDiff),
                'exchange_diff' => $storedDiff,
                'is_preview' => false,
            ]);
        }

        // pending / null: 사용자 입력 환율 우선, 없으면 자동 fetch (회의확장씬 #6+7 보강 2026-05-23)
        // 사용자가 input에 입력 중인 환율 ($exchange_rate_at_close_str) 라이브 미리보기.
        // 입력 비었으면 settlements 저장값, 그것도 없으면 ExchangeRateService 자동 fetch.
        $inputRate = (float) str_replace(',', '', $this->exchange_rate_at_close_str ?: '0');
        if ($inputRate > 0) {
            $currentRate = $inputRate;
            $rateSource = 'manual';
        } elseif ($settlement && $settlement->exchange_rate_at_close !== null) {
            $currentRate = (float) $settlement->exchange_rate_at_close;
            $rateSource = 'stored';
        } else {
            $currentRate = app(\App\Services\ExchangeRateService::class)->getRate($v->currency);
            $rateSource = 'auto';
        }

        if ($currentRate === null) {
            return array_merge($base, ['rate_unavailable' => true]);
        }

        $foreignReceived = (float) $v->finalPayments->whereNotNull('confirmed_at')->sum('amount');
        $secondaryKrw = (int) ($foreignReceived * $currentRate);

        return array_merge($base, [
            'secondary_krw' => $secondaryKrw,
            'exchange_diff' => (float) ($secondaryKrw - $receivedKrw),
            'current_rate' => $currentRate,
            'rate_source' => $rateSource,
            'is_preview' => true,
        ]);
    }

    // ── 액션 ──────────────────────────────────────────────────────

    public function selectVehicle(int $vehicleId): void
    {
        $this->vehicle_id = $vehicleId;
        $vehicle = Vehicle::find($vehicleId);
        $this->salesman_id = $vehicle?->salesman_id;
        $this->vehicleSearch = '';
        unset($this->selectedVehicle, $this->vehicleSearchResults, $this->marginData);
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $s = Settlement::findOrFail($id);
        $this->editingId = $id;
        $this->vehicle_id = $s->vehicle_id;
        $this->salesman_id = $s->salesman_id;
        $this->settlement_type = $s->settlement_type;
        $this->settlement_ratio = $s->settlement_ratio;
        $this->per_unit_amount = $s->per_unit_amount;
        $this->other_deduction = (float) ($s->other_deduction ?? 0);
        $this->settlement_status = $s->settlement_status;
        $this->note = $s->note ?? '';
        $this->vehicleSearch = '';
        $this->showPanel = true;

        // 회의확장씬 #6+7 보강 (2026-05-23) — secondary='pending' 외화 차량 환율 default 채움.
        // 저장값 우선, 없으면 ExchangeRateService 자동 fetch (default).
        $this->exchange_rate_at_close_str = '';
        $v = $s->vehicle;
        if ($s->secondary_status === 'pending' && $v && $v->currency !== 'KRW') {
            if ($s->exchange_rate_at_close !== null) {
                $this->exchange_rate_at_close_str = (string) $s->exchange_rate_at_close;
            } else {
                $auto = app(\App\Services\ExchangeRateService::class)->getRate($v->currency);
                if ($auto !== null) {
                    $this->exchange_rate_at_close_str = (string) $auto;
                }
            }
        }

        unset($this->selectedVehicle, $this->marginData, $this->krwBreakdown);
    }

    /**
     * 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 환율 수동 저장 (수정 가능).
     *
     * secondary='pending' 동안 [재무]/[관리]/admin 만 호출 가능.
     * 저장 후 패널의 KRW 명세 라이브 미리보기 자동 갱신.
     * closed 후엔 호출 차단 (회계 무결성).
     */
    public function saveExchangeRate(int $id): void
    {
        $s = Settlement::findOrFail($id);

        abort_unless(
            auth()->user()?->isAdmin()
                || in_array(auth()->user()?->role, ['재무', '관리'], true),
            403,
            '환율 저장은 [재무]/[관리]/admin 만 가능합니다.'
        );

        if ($s->secondary_status !== 'pending') {
            $this->dispatch('notify', message: '2차 정산 대기 상태에서만 환율 수정 가능합니다.', type: 'warning');

            return;
        }

        $rate = (float) str_replace(',', '', $this->exchange_rate_at_close_str);
        if ($rate <= 0) {
            $this->dispatch('notify', message: '환율은 0보다 커야 합니다.', type: 'error');

            return;
        }

        $s->update(['exchange_rate_at_close' => $rate]);
        unset($this->krwBreakdown);
        $this->dispatch('notify', message: '환율 저장 완료 — '.number_format($rate, 2), type: 'success');
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
        unset($this->selectedVehicle, $this->vehicleSearchResults, $this->marginData);
    }

    public function save(): void
    {
        $rules = [
            'vehicle_id' => 'required|exists:vehicles,id',
            'settlement_type' => 'required|in:ratio,per_unit',
            'other_deduction' => 'nullable|numeric|min:0',
            'settlement_status' => 'required|in:pending,calculating,confirmed,paid',
        ];

        if ($this->settlement_type === 'ratio') {
            $rules['settlement_ratio'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['per_unit_amount'] = 'required|numeric|min:0';
        }

        $this->validate($rules);

        $data = [
            'vehicle_id' => $this->vehicle_id,
            'salesman_id' => $this->salesman_id ?: null,
            'settlement_type' => $this->settlement_type,
            'settlement_ratio' => $this->settlement_type === 'ratio' ? $this->settlement_ratio : null,
            'per_unit_amount' => $this->settlement_type === 'per_unit' ? $this->per_unit_amount : null,
            'other_deduction' => (float) ($this->other_deduction ?? 0),
            'settlement_status' => $this->settlement_status,
            'note' => $this->note ?: null,
        ];

        $now = now();

        if ($this->editingId) {
            $existing = Settlement::findOrFail($this->editingId);
            if ($this->settlement_status === 'confirmed' && ! $existing->confirmed_at) {
                $data['confirmed_at'] = $now;
            }
            if ($this->settlement_status === 'paid' && ! $existing->paid_at) {
                $data['paid_at'] = $now;
            }
            $existing->update($data);
        } else {
            if ($this->settlement_status === 'confirmed') {
                $data['confirmed_at'] = $now;
            }
            if ($this->settlement_status === 'paid') {
                $data['paid_at'] = $now;
            }
            Settlement::create($data);
        }

        unset($this->settlements);
        $this->close();
        session()->flash('success', '정산 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        Settlement::findOrFail($id)->delete();
        unset($this->settlements);
        session()->flash('success', '정산이 삭제됐습니다.');
    }

    /**
     * 큐 14-4-2 — confirmed 정산을 paid로 전환 요청.
     * canApprove user는 직접 paid 변경 가능 (Settlement::saving 가드 통과).
     * 그 외 user는 이 메서드로 ApprovalRequest 생성 → /erp/approvals 큐로 진입.
     */
    public function requestPayApproval(int $id): void
    {
        $settlement = Settlement::findOrFail($id);
        if ($settlement->settlement_status !== 'confirmed') {
            $this->dispatch('notify', message: 'confirmed 상태에서만 지급 승인 요청 가능합니다.', type: 'warning');

            return;
        }

        // 동일 정산에 대기중 요청 있으면 중복 차단
        $existing = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_SETTLEMENT_PAY)
            ->where('target_type', Settlement::class)
            ->where('target_id', $settlement->id)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->exists();
        if ($existing) {
            $this->dispatch('notify', message: '이미 대기중인 승인 요청이 있습니다.', type: 'warning');

            return;
        }

        ApprovalRequest::create([
            'requester_id' => auth()->id(),
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $settlement->id,
            'payload' => [
                'vehicle_number' => $settlement->vehicle?->vehicle_number,
                'actual_payout' => $settlement->actual_payout,
            ],
            'reason' => '정산 #'.$settlement->id.' ('.($settlement->vehicle?->vehicle_number ?? '?').') 지급 처리',
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->dispatch('notify', message: '지급 승인 요청을 보냈습니다.', type: 'success');
    }

    /**
     * 회의확장씬 #8 (2026-05-22) — 2차 정산 완료 (secondary_status='closed').
     * paid → secondary_pending (자동) 후 [관리]/[재무] 가 기타비용 수정 → 최종 마무리.
     * closed 이후 회계 잠금 (Vehicle 측 가드 Step B-2 에서 처리).
     *
     * 회의확장씬 #7 Step C-4 (2026-05-22) — 2차 정산 시점 환차 계산.
     *   환차 = (current_rate × Σ foreign_amount) − sale_received_krw_accumulated
     *   +이면 환차익 → 프리랜서 정산금 +, -이면 환차손 → -.
     *   KRW 차량 또는 ExchangeRateService 실패 → 0 (환차 없음).
     */
    public function closeSecondarySettlement(int $id): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isAdmin() || in_array($user?->role, ['재무', '관리'], true),
            403,
            '2차 정산 완료는 [재무]/[관리]/admin 만 가능합니다.'
        );

        $settlement = Settlement::findOrFail($id);
        if ($settlement->secondary_status !== 'pending') {
            $this->dispatch('notify', message: '2차 정산 대기 상태가 아닙니다.', type: 'warning');

            return;
        }

        // Step C-4: 환차 계산 (회의확장씬 #7) — 저장된 사용자 입력 환율 우선.
        // 회의확장씬 #6+7 보강 (2026-05-23): saveExchangeRate 로 저장된 값 있으면 그 값 사용 (수동 override).
        [$exchangeDiff, $usedRate] = $this->calculateExchangeDifference($settlement);

        $update = [
            'secondary_status' => 'closed',
            'secondary_closed_at' => now(),
            'exchange_difference_krw' => $exchangeDiff,
        ];
        if ($usedRate !== null) {
            $update['exchange_rate_at_close'] = $usedRate;
        }
        $settlement->update($update);

        unset($this->settlements);
        $msg = '2차 정산 완료 (최종 마무리)';
        if ($exchangeDiff !== null && abs($exchangeDiff) > 0.01) {
            $sign = $exchangeDiff > 0 ? '+' : '';
            $msg .= ' — 환차 '.$sign.'₩'.number_format($exchangeDiff);
        }
        $this->dispatch('notify', message: $msg, type: 'success');
    }

    /**
     * 회의확장씬 #7 Step C-4 — 정산 시점 환율 재계산 환차.
     * 회의확장씬 #6+7 보강 (2026-05-23) — 저장된 exchange_rate_at_close 우선 사용.
     *
     * @return array{0: float|null, 1: float|null}  [diff KRW, rate used]
     *   diff: 환차 KRW (양수=환차익 / 음수=환차손 / 0=동일). null=계산 불가.
     *   rate: 실제 사용된 환율 (저장값 또는 자동 fetch). null=KRW 차량 또는 실패.
     */
    private function calculateExchangeDifference(Settlement $settlement): array
    {
        $vehicle = $settlement->vehicle;
        if (! $vehicle || $vehicle->currency === 'KRW') {
            return [0.0, null];   // KRW 차량은 환차 없음
        }

        // 사용자 저장 환율 우선, 없으면 자동 fetch fallback.
        $currentRate = $settlement->exchange_rate_at_close !== null
            ? (float) $settlement->exchange_rate_at_close
            : app(\App\Services\ExchangeRateService::class)->getRate($vehicle->currency);

        if ($currentRate === null) {
            return [null, null];   // 환율 조회 실패 — 환차 계산 불가
        }

        $foreignReceived = (float) $vehicle->finalPayments
            ->whereNotNull('confirmed_at')
            ->sum('amount');
        $krwAtNow = $foreignReceived * $currentRate;
        $krwAtPaymentTimes = (float) $vehicle->sale_received_krw_accumulated;

        return [$krwAtNow - $krwAtPaymentTimes, $currentRate];
    }

    private function resetForm(): void
    {
        $this->vehicle_id = null;
        $this->vehicleSearch = '';
        $this->salesman_id = null;
        $this->settlement_type = 'ratio';
        $this->settlement_ratio = null;
        $this->per_unit_amount = null;
        $this->other_deduction = 0;
        $this->settlement_status = 'pending';
        $this->note = '';
        $this->exchange_rate_at_close_str = '';
    }
}; ?>

<div wire:poll.30s>
{{-- 성공 토스트 --}}
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">정산 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->settlements->total() }}건</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">10개씩</option>
            <option value="30">30개씩</option>
            <option value="50">50개씩</option>
            <option value="100">100개씩</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            정산 추가
        </button>
    </div>
</div>

{{-- 필터 바 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="차량번호"
           class="input-filter w-36" />
    <select wire:model="statusFilter" class="input-filter">
        <option value="">전체 상태</option>
        <option value="pending">대기</option>
        <option value="calculating">계산중</option>
        <option value="confirmed">확정</option>
        <option value="paid">지급완료</option>
    </select>
    <select wire:model="salesmanFilter" class="input-filter">
        <option value="0">전체 담당자</option>
        @foreach($this->salesmen as $sm)
        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    <input wire:model="dateFrom" type="date" class="input-filter" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model="dateTo" type="date" class="input-filter" />
    <button wire:click="search" class="btn-search">조회</button>
</div>

{{-- 2026-05-20 #2 피드백 — 영업담당자별 합계 카드 (인원별 솔팅 + 합계). --}}
{{-- 클릭 시 해당 담당자 필터 토글. statusFilter / dateFrom/To 와 동일 컨텍스트. --}}
@if(!empty($this->salesmanSummaries))
<div class="mt-3">
    <div class="mb-2 flex items-center gap-2 text-xs text-gray-500">
        <span>영업담당자별 합계</span>
        <span class="text-gray-400">— 클릭하면 해당 담당자만 솔팅</span>
    </div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        @foreach($this->salesmanSummaries as $summary)
        <button type="button" wire:click="setSalesmanFilter({{ $summary['salesman_id'] ?? 0 }})"
                class="card text-left transition hover:bg-violet-50 {{ $salesmanFilter == $summary['salesman_id'] ? 'border-violet-400 bg-violet-50/40' : '' }}">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-700">{{ $summary['salesman_name'] }}</span>
                <span class="pill-count">{{ $summary['count'] }}건</span>
            </div>
            <div class="mt-2 space-y-1 text-[11px]">
                <div class="flex items-center justify-between text-gray-500">
                    <span>총마진</span>
                    <span class="font-mono text-gray-700">{{ number_format($summary['total_margin_sum']) }}</span>
                </div>
                <div class="flex items-center justify-between text-gray-500">
                    <span>정산액</span>
                    <span class="font-mono text-gray-700">{{ number_format($summary['settlement_amount_sum']) }}</span>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 pt-1">
                    <span class="text-violet-700">실지급액</span>
                    <span class="font-mono font-semibold text-violet-700">{{ number_format($summary['actual_payout_sum']) }}</span>
                </div>
            </div>
        </button>
        @endforeach
    </div>
</div>
@endif

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">차량번호</th>
                <th class="pb-2 pr-4 font-medium">담당자</th>
                <th class="pb-2 pr-4 font-medium">진행상태</th>
                {{-- 2026-05-20 #2 피드백 — 입금률 게이지 (거래완료 미완납 시 정산 진행 차단 정보) --}}
                <th class="pb-2 pr-4 font-medium" style="min-width: 110px;">입금률</th>
                <th class="pb-2 pr-4 font-medium">정산방식</th>
                <th class="pb-2 pr-4 font-medium text-right">총마진</th>
                <th class="pb-2 pr-4 font-medium text-right">정산액</th>
                <th class="pb-2 pr-4 font-medium text-right">실지급액</th>
                {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차익 컬럼 (closed 정산만 stored value 표시). --}}
                <th class="pb-2 pr-4 font-medium text-right">환차</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->settlements as $s)
            @php
                $statusBadge = match($s->settlement_status) {
                    'pending'     => 'badge-blue',
                    'calculating' => 'badge-amber',
                    'confirmed'   => 'badge-green',
                    'paid'        => 'badge-gray',
                    default       => 'badge-gray',
                };
                $statusLabel = match($s->settlement_status) {
                    'pending'     => '대기',
                    'calculating' => '계산중',
                    'confirmed'   => '확정',
                    'paid'        => '지급완료',
                    default       => $s->settlement_status,
                };
                // 회의확장씬 #8 (2026-05-22) — 2차 정산 status 보강 라벨.
                $secondaryLabel = match($s->secondary_status) {
                    'pending' => '2차 대기',
                    'closed'  => '최종 마무리',
                    default   => null,
                };
                $secondaryBadge = match($s->secondary_status) {
                    'pending' => 'badge-amber',
                    'closed'  => 'badge-gray',
                    default   => null,
                };
                $canCloseSecondary = $s->secondary_status === 'pending'
                    && (auth()->user()?->isAdmin() || in_array(auth()->user()?->role, ['재무', '관리'], true));
                // 안건 1 v4 (2026-05-21) — 색 매핑: 선적=amber, 통관=green. v3 호환 키 동시 보유
                $progressBadge = match(true) {
                    in_array($s->vehicle?->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($s->vehicle?->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                    in_array($s->vehicle?->progress_status, ['선적중','선적완료'])            => 'badge-amber',
                    in_array($s->vehicle?->progress_status, ['통관중','통관완료'])             => 'badge-green',
                    in_array($s->vehicle?->progress_status, ['수출통관중','수출통관완료'])    => 'badge-amber',
                    $s->vehicle?->progress_status === '거래완료'                              => 'badge-gray',
                    default => 'badge-gray',
                };
            @endphp
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $s->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $s->vehicle?->vehicle_number ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $s->salesman?->name ?? '-' }}</td>
                <td class="py-3 pr-4">
                    @if($s->vehicle)
                    <span class="badge {{ $progressBadge }}">{{ $s->vehicle->progress_status }}</span>
                    @else
                    <span class="text-gray-300">-</span>
                    @endif
                </td>
                {{-- 2026-05-20 #2 피드백 — 입금률 게이지 (vehicle.unpaid_ratio 기반, SKILLS §13 단일 출처) --}}
                <td class="py-3 pr-4">
                    @php
                        $ratio = $s->vehicle?->unpaid_ratio;
                        $unpaidAmount = $s->vehicle?->sale_unpaid_amount ?? 0;
                    @endphp
                    @if($ratio === null)
                        <span class="text-[10px] text-gray-400">환율 미입력</span>
                    @elseif($ratio <= 0)
                        <div class="flex items-center gap-1">
                            <div class="h-2 w-full rounded-full bg-green-100 overflow-hidden">
                                <div class="h-full bg-green-500" style="width: 100%;"></div>
                            </div>
                            <span class="text-[10px] font-medium text-green-700">완납</span>
                        </div>
                    @else
                        @php $paidPct = max(0, min(100, (1 - $ratio) * 100)); @endphp
                        <div class="flex items-center gap-1">
                            <div class="h-2 w-full rounded-full bg-amber-100 overflow-hidden">
                                <div class="h-full bg-amber-500" style="width: {{ $paidPct }}%;"></div>
                            </div>
                            <span class="text-[10px] font-medium text-amber-700">{{ number_format($paidPct, 0) }}%</span>
                        </div>
                        <div class="mt-0.5 text-[10px] text-amber-700">받을 ₩{{ number_format($unpaidAmount) }}</div>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-600">
                    @if($s->settlement_type === 'ratio')
                        비율 {{ number_format($s->settlement_ratio, 1) }}%
                    @else
                        건당 ₩{{ number_format($s->per_unit_amount) }}
                    @endif
                </td>
                <td class="py-3 pr-4 text-right {{ $s->total_margin < 0 ? 'text-red-500' : 'text-gray-700' }}">
                    ₩{{ number_format($s->total_margin) }}
                </td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    ₩{{ number_format($s->settlement_amount) }}
                </td>
                <td class="py-3 pr-4 text-right font-semibold {{ $s->actual_payout < 0 ? 'text-red-600' : 'text-gray-800' }}">
                    ₩{{ number_format($s->actual_payout) }}
                </td>
                {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차 (저장값 기준, live 계산 X). --}}
                <td class="py-3 pr-4 text-right text-xs">
                    @if($s->vehicle?->currency === 'KRW')
                        <span class="text-gray-300" title="원화 차량 — 환차 없음">—</span>
                    @elseif($s->secondary_status === 'closed' && $s->exchange_difference_krw !== null)
                        @php $diff = (float) $s->exchange_difference_krw; @endphp
                        @if($diff > 0)
                        <span class="font-semibold text-emerald-600" title="환차익">+₩{{ number_format($diff) }}</span>
                        @elseif($diff < 0)
                        <span class="font-semibold text-red-600" title="환차손">-₩{{ number_format(abs($diff)) }}</span>
                        @else
                        <span class="text-gray-400" title="환차 동일">₩0</span>
                        @endif
                    @else
                        <span class="text-gray-300" title="2차 정산 완료 후 표시">—</span>
                    @endif
                </td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                    {{-- 회의확장씬 #8 (2026-05-22) — 2차 정산 상태 보강 라벨 --}}
                    @if($secondaryLabel)
                    <span class="badge {{ $secondaryBadge }} ml-1" title="2차 정산 status">{{ $secondaryLabel }}</span>
                    @endif
                    {{-- 큐 14-4-2 — 지급 승인 요청 상태 인라인 표시 --}}
                    @php $pa = $s->latestPayApproval; @endphp
                    @if($pa && $pa->status === 'pending')
                    <span class="badge badge-amber ml-1" title="승인 대기중 — 관리자 결정 대기">승인 대기중</span>
                    @elseif($pa && $pa->status === 'rejected')
                    <span class="badge badge-red ml-1" title="{{ $pa->approver?->name ?? '?' }} 거부 사유: {{ $pa->decision_note ?? '(사유 없음)' }}">
                        거부됨
                    </span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <div class="flex justify-end gap-2">
                        {{-- 큐 14-4-2 — confirmed + 대기중 요청 없음 + 비-canApprove user만 버튼 노출 --}}
                        @if($s->settlement_status === 'confirmed' && ! auth()->user()->canApprove()
                            && (! $pa || $pa->status !== 'pending'))
                        <button wire:click.stop="requestPayApproval({{ $s->id }})"
                                wire:confirm="지급 승인 요청을 보내시겠습니까?"
                                class="text-xs text-violet-600 hover:text-violet-800">지급 승인 요청</button>
                        @endif
                        {{-- 회의확장씬 #8 (2026-05-22) — 2차 정산 완료 액션 ([재무]/[관리]/admin) --}}
                        @if($canCloseSecondary)
                        <button wire:click.stop="closeSecondarySettlement({{ $s->id }})"
                                wire:confirm="2차 정산을 최종 마무리하시겠습니까? 이후 회계 컬럼 잠금됩니다."
                                class="text-xs text-violet-600 hover:text-violet-800">2차 완료</button>
                        @endif
                        <button wire:click.stop="delete({{ $s->id }})"
                                wire:confirm="정산을 삭제하시겠습니까?"
                                class="text-xs text-red-400 hover:text-red-600">삭제</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="py-12 text-center text-sm text-gray-400">정산 내역이 없습니다.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->settlements as $s)
    @php
        $statusBadge = match($s->settlement_status) {
            'pending' => 'badge-blue', 'calculating' => 'badge-amber',
            'confirmed' => 'badge-green', 'paid' => 'badge-gray', default => 'badge-gray',
        };
        $statusLabel = match($s->settlement_status) {
            'pending' => '대기', 'calculating' => '계산중',
            'confirmed' => '확정', 'paid' => '지급완료', default => $s->settlement_status,
        };
    @endphp
    <div class="card-tight cursor-pointer" wire:click="openEdit({{ $s->id }})">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-800">{{ $s->vehicle?->vehicle_number ?? '-' }}</div>
            <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-gray-500">
            <div>담당자: {{ $s->salesman?->name ?? '-' }}</div>
            <div>방식: {{ $s->settlement_type === 'ratio' ? number_format($s->settlement_ratio, 1).'%' : '건당' }}</div>
            <div>총마진: ₩{{ number_format($s->total_margin) }}</div>
            <div class="font-semibold text-gray-700">실지급: ₩{{ number_format($s->actual_payout) }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">정산 내역이 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->settlements->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[520px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '정산 수정' : '정산 추가' }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- Validation 에러 박스 (큐 14-4-2 — Settlement::saving 가드 throw 잡힘) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-xs font-semibold text-red-700">저장할 수 없습니다 — 아래 항목을 확인하세요</p>
                <ul class="mt-1 space-y-0.5 text-xs text-red-600">
                    @foreach($errors->all() as $msg)
                    <li>· {{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif

    {{-- 폼 본문 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">

        {{-- 차량 선택 (신규) / 차량 정보 표시 (수정) --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">차량</span>
            </div>
            @if(! $editingId)
            <div class="relative">
                <input wire:model.live.debounce.300ms="vehicleSearch"
                       type="text"
                       placeholder="차량번호 입력 (2자 이상)..."
                       class="input-base w-full" />
                @if($this->vehicleSearchResults->isNotEmpty())
                <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border bg-white shadow-lg">
                    @foreach($this->vehicleSearchResults as $v)
                    <button wire:click="selectVehicle({{ $v->id }})"
                            class="flex w-full items-center justify-between border-b px-3 py-2 text-left text-sm last:border-0 hover:bg-gray-50">
                        <span class="font-medium">{{ $v->vehicle_number }}</span>
                        @if($v->salesman)
                        <span class="text-xs text-gray-400">{{ $v->salesman->name }}</span>
                        @endif
                    </button>
                    @endforeach
                </div>
                @endif
            </div>
            @if($vehicle_id && $this->selectedVehicle)
            <div class="mt-2 flex items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 text-sm">
                <svg class="h-4 w-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span class="font-medium text-blue-800">{{ $this->selectedVehicle->vehicle_number }}</span>
                <span class="text-blue-500">{{ $this->selectedVehicle->progress_status }}</span>
            </div>
            @endif
            @error('vehicle_id')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            @else
            @if($this->selectedVehicle)
            <div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                <span class="font-medium text-gray-800">{{ $this->selectedVehicle->vehicle_number }}</span>
                @php
                    // 안건 1 v4 (2026-05-21) — 색 매핑 swap
                    $pb = match(true) {
                        in_array($this->selectedVehicle->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                        in_array($this->selectedVehicle->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                        in_array($this->selectedVehicle->progress_status, ['선적중','선적완료'])            => 'badge-amber',
                        in_array($this->selectedVehicle->progress_status, ['통관중','통관완료'])             => 'badge-green',
                        in_array($this->selectedVehicle->progress_status, ['수출통관중','수출통관완료'])    => 'badge-amber',
                        $this->selectedVehicle->progress_status === '거래완료'                              => 'badge-gray',
                        default => 'badge-red',
                    };
                @endphp
                <span class="badge {{ $pb }}">{{ $this->selectedVehicle->progress_status }}</span>
            </div>
            @endif
            @endif
        </div>

        {{-- 마진 산출내역 --}}
        @if(! empty($this->marginData))
        <div>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">마진 산출내역</span>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600">
                    <span>판매금원화 <span class="text-xs text-gray-400">(판매가+커미션+자동하역비-TAX/DC)×환율</span></span>
                    <span>₩{{ number_format($this->marginData['salesAmountKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>정산판매금원화 <span class="text-xs text-gray-400">-비용합계</span></span>
                    <span>₩{{ number_format($this->marginData['settlementSalesKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>판매마진 <span class="text-xs text-gray-400">-(매입가+매도비)</span></span>
                    <span class="{{ $this->marginData['salesMargin'] < 0 ? 'text-red-500' : '' }}">₩{{ number_format($this->marginData['salesMargin']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>부가세마진 <span class="text-xs text-gray-400">매입가×9%</span></span>
                    <span>₩{{ number_format($this->marginData['vatMargin']) }}</span>
                </div>
                <hr class="border-gray-200" />
                <div class="flex justify-between font-semibold text-gray-800">
                    <span>총마진 <span class="text-xs text-gray-400 font-normal">(판매마진+부가세마진)×0.9</span></span>
                    <span class="{{ $this->marginData['totalMargin'] < 0 ? 'text-red-600' : '' }}">₩{{ number_format($this->marginData['totalMargin']) }}</span>
                </div>
            </div>
        </div>
        @endif

        {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 정산 KRW 명세 (1차·입금·2차·환차). --}}
        @if(! empty($this->krwBreakdown))
        @php $kb = $this->krwBreakdown; @endphp
        <div>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">
                    정산 KRW 명세
                    @if($kb['status'] === 'closed')
                    <span class="ml-1 text-emerald-600 font-semibold">(확정)</span>
                    @elseif($kb['status'] === 'pending')
                    <span class="ml-1 text-amber-600 font-semibold">(2차 대기)</span>
                    @endif
                </span>
            </div>
            @if($kb['is_krw_vehicle'])
            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 text-center">
                원화 차량 — 환차 없음
            </div>
            @else
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600">
                    <span>1차 정산금원화 <span class="text-xs text-gray-400">(차량 환율 기준)</span></span>
                    <span>₩{{ number_format($kb['primary_krw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>입금 시점 KRW 합 <span class="text-xs text-gray-400">(row별 환율)</span></span>
                    <span>₩{{ number_format($kb['received_krw']) }}</span>
                </div>

                @if(! empty($kb['rate_unavailable']))
                <div class="mt-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-700">
                    현재 환율 조회 실패 — 정산 시점 KRW / 환차 계산 불가
                </div>
                @elseif(! empty($kb['is_preview']))
                {{-- pending — 환율 수동 입력 + 라이브 미리보기 (회의확장씬 #6+7 보강 2026-05-23) --}}
                <hr class="border-gray-200">
                @php
                    $canEditRate = auth()->user()?->isAdmin()
                        || in_array(auth()->user()?->role, ['재무', '관리'], true);
                    $rateSourceLabel = match($kb['rate_source'] ?? 'auto') {
                        'manual' => '입력 중',
                        'stored' => '저장값',
                        'auto'   => '자동 조회',
                        default  => '',
                    };
                @endphp
                <div class="space-y-1.5">
                    <div class="text-xs font-medium text-gray-600">정산 시점 환율 (수동 입력 또는 자동 default)</div>
                    <div class="flex gap-2 items-center">
                        <input wire:model.live.debounce.400ms="exchange_rate_at_close_str"
                               type="text"
                               class="input-base text-right"
                               style="width: 130px; flex: none;"
                               placeholder="환율"
                               title="2차 정산 시점 환율 (입력 후 [환율 저장] 또는 [2차 완료] 클릭)"
                               @if(!$canEditRate) disabled @endif />
                        <span class="text-[10px] text-gray-400">{{ $rateSourceLabel }}</span>
                        @if($canEditRate)
                        <button type="button" wire:click="saveExchangeRate({{ $this->editingId }})"
                                class="ml-auto rounded border border-violet-300 bg-white px-2 py-1 text-[11px] font-medium text-violet-700 hover:bg-violet-50">
                            환율 저장
                        </button>
                        @endif
                    </div>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">정산 시점 KRW 합 <span class="text-[10px] text-gray-400">(입력 환율 × 외화 합)</span></span>
                    <span class="text-gray-700">₩{{ number_format($kb['secondary_krw']) }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">예상 환차 <span class="text-[10px] text-gray-400">(2차 완료 시 확정)</span></span>
                    @if($kb['exchange_diff'] > 0)
                    <span class="text-emerald-600 font-medium">+₩{{ number_format($kb['exchange_diff']) }} 환차익</span>
                    @elseif($kb['exchange_diff'] < 0)
                    <span class="text-red-600 font-medium">-₩{{ number_format(abs($kb['exchange_diff'])) }} 환차손</span>
                    @else
                    <span class="text-gray-400">₩0 (동일)</span>
                    @endif
                </div>
                @if($canEditRate)
                <button type="button"
                        wire:click="closeSecondarySettlement({{ $this->editingId }})"
                        wire:confirm="2차 정산을 최종 마무리하시겠습니까? 환율 {{ number_format($kb['current_rate'], 2) }} 기준으로 확정되며 이후 회계 잠금됩니다."
                        class="mt-2 w-full rounded bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700">
                    2차 정산 완료 (현재 환율 확정)
                </button>
                @endif
                @else
                {{-- closed — 저장된 확정값 --}}
                <hr class="border-gray-200">
                @php
                    $closedSettlement = Settlement::find($this->editingId);
                    $closedRate = $closedSettlement?->exchange_rate_at_close;
                @endphp
                @if($closedRate !== null)
                <div class="flex justify-between text-gray-600 text-xs">
                    <span>확정 환율 <span class="text-[10px] text-gray-400">(2차 완료 시점)</span></span>
                    <span class="font-medium text-gray-700">{{ number_format((float) $closedRate, 4) }}</span>
                </div>
                @endif
                <div class="flex justify-between text-gray-700">
                    <span>정산 시점 KRW 합 <span class="text-xs text-gray-400">(2차 정산 확정 환율)</span></span>
                    <span class="font-medium">₩{{ number_format($kb['secondary_krw']) }}</span>
                </div>
                <div class="flex justify-between font-semibold">
                    <span class="text-gray-800">환차 (확정)</span>
                    @if($kb['exchange_diff'] > 0)
                    <span class="text-emerald-600">+₩{{ number_format($kb['exchange_diff']) }} 환차익</span>
                    @elseif($kb['exchange_diff'] < 0)
                    <span class="text-red-600">-₩{{ number_format(abs($kb['exchange_diff'])) }} 환차손</span>
                    @else
                    <span class="text-gray-500">₩0 (동일)</span>
                    @endif
                </div>
                {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차 반영 실지급액 안내 (프리랜서만, 1:1 적용). --}}
                @if($settlement_type === 'ratio')
                <div class="mt-1 rounded border border-violet-200 bg-violet-50 px-2 py-1.5 text-[11px] text-violet-700">
                    <strong>실지급액 환차 자동 반영</strong> — 프리랜서(비율제) 정산금에 환차 1:1 가산됨 (회의확장씬 #6+7).
                </div>
                @elseif($settlement_type === 'per_unit')
                <div class="mt-1 rounded border border-gray-200 bg-gray-50 px-2 py-1.5 text-[11px] text-gray-500">
                    사내직원(건당제) — 환차는 실지급액에 미반영 (회사 부담).
                </div>
                @endif
                @endif
            </div>
            @endif
        </div>
        @endif

        {{-- 정산 설정 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">정산 설정</span>
            </div>

            {{-- 담당자 --}}
            <div class="mb-3">
                <label class="label-base">영업담당자</label>
                <select wire:model="salesman_id" class="input-base">
                    <option value="">담당자 없음</option>
                    @foreach($this->salesmen as $sm)
                    <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- 정산방식 --}}
            <div class="mb-3">
                <label class="label-base">정산방식 <span class="text-red-500">*</span></label>
                <div class="mt-1.5 flex gap-5">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="ratio" class="accent-primary" />
                        비율제
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="per_unit" class="accent-primary" />
                        건당제
                    </label>
                </div>
            </div>

            @if($settlement_type === 'ratio')
            <div class="mb-3">
                <label class="label-base">비율 (%) <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="settlement_ratio"
                       type="number" step="0.01" min="0" max="100"
                       class="input-base" placeholder="예: 30.00" />
                @error('settlement_ratio')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @else
            <div class="mb-3">
                <label class="label-base">건당 금액 (원) <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="per_unit_amount"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="예: 500000" />
                @error('per_unit_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif

            <div class="mb-3">
                <label class="label-base">기타공제 (원)</label>
                <input wire:model.live.debounce.500ms="other_deduction"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="0" />
            </div>
        </div>

        {{-- 정산 결과 --}}
        @if(! empty($this->marginData))
        <div class="rounded-lg bg-purple-50 p-3 text-sm space-y-1.5">
            <div class="flex justify-between text-gray-600">
                <span>정산액
                    @if($settlement_type === 'ratio')
                        <span class="text-xs text-gray-400">총마진 × {{ ($settlement_ratio ?? null) !== null && (float) $settlement_ratio > 0 ? $settlement_ratio : 50 }}%</span>
                    @else
                        <span class="text-xs text-gray-400">건당 고정</span>
                    @endif
                </span>
                <span class="font-medium">₩{{ number_format($this->marginData['settlementAmount']) }}</span>
            </div>
            {{-- 2026-05-21 — 서류비 행 추가. 프리랜서(ratio)만 5만원 자동 차감 --}}
            @if($this->marginData['documentFee'] > 0)
            <div class="flex justify-between text-gray-500">
                <span>서류비 <span class="text-xs text-gray-400">(프리랜서 자동)</span></span>
                <span class="text-red-500">- ₩{{ number_format($this->marginData['documentFee']) }}</span>
            </div>
            @endif
            <div class="flex justify-between text-gray-500">
                <span>기타공제</span>
                <span class="text-red-500">- ₩{{ number_format((int) ($other_deduction ?? 0)) }}</span>
            </div>
            {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차 자동 반영 라인 (프리랜서 closed 일 때만). --}}
            @if(! empty($this->marginData['exchangeDiff']))
            <div class="flex justify-between text-gray-600">
                <span>환차 (2차 확정) <span class="text-xs text-gray-400">(프리랜서 1:1 반영)</span></span>
                @if($this->marginData['exchangeDiff'] > 0)
                <span class="text-emerald-600">+ ₩{{ number_format($this->marginData['exchangeDiff']) }}</span>
                @else
                <span class="text-red-500">- ₩{{ number_format(abs($this->marginData['exchangeDiff'])) }}</span>
                @endif
            </div>
            @endif
            <hr class="border-purple-200" />
            <div class="flex justify-between text-base font-bold">
                <span class="text-gray-800">실지급액</span>
                <span class="{{ $this->marginData['actualPayout'] < 0 ? 'text-red-600' : 'text-purple-700' }}">
                    ₩{{ number_format($this->marginData['actualPayout']) }}
                </span>
            </div>
        </div>
        @endif

        {{-- 진행상태 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">진행상태</span>
            </div>
            <select wire:model="settlement_status" class="input-base">
                <option value="pending">대기</option>
                <option value="calculating">계산중</option>
                <option value="confirmed">확정</option>
                <option value="paid">지급완료</option>
            </select>
            @if($editingId)
            @php
                $existing = \App\Models\Settlement::find($editingId);
            @endphp
            @if($existing?->confirmed_at)
            <p class="mt-1 text-xs text-gray-400">확정일: {{ $existing->confirmed_at->format('Y-m-d H:i') }}</p>
            @endif
            @if($existing?->paid_at)
            <p class="mt-0.5 text-xs text-gray-400">지급일: {{ $existing->paid_at->format('Y-m-d H:i') }}</p>
            @endif
            @endif
        </div>

        {{-- 메모 --}}
        <div>
            <label class="label-base">메모</label>
            <textarea wire:model="note" class="input-base" rows="2" placeholder="특이사항 등"></textarea>
        </div>

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            취소
        </button>
        <button wire:click="save" class="btn-primary"
                wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">저장</span>
            <span wire:loading wire:target="save">저장 중...</span>
        </button>
    </div>

</div>
@endif

</div>
