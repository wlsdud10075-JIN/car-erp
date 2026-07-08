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

    // 2026-06-24 — 정산 월(월급 귀속월) 솔팅. 기준 = created_at(정산 발생/거래완료월, jin 결정).
    // 'YYYY-MM' 형식. 빈 문자열 = 전체. 1일~말일 일한 정산 → 다음달 10일 지급 주기를 월 단위로 묶음.
    #[Url] public string $monthFilter = '';

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

    public function updatedMonthFilter(): void
    {
        $this->resetPage();
    }

    // 2026-06-24 — 드롭다운에 노출할 정산 월 목록 (귀속월 기준, 최신순).
    // 귀속월 = payrollMonthOf(confirmed_at ?? created_at) — 재무확정일 앵커 + 10일 cutoff (jin 2026-07-02).
    //   앵커=confirmed_at: 거래완료 아니어도 완납되면 정산 진행하므로 '확정 시점'이 귀속월 결정 (jin).
    //   pending(confirmed_at=null)은 created_at 로 잠정 배치. import 백데이트분은 confirmed_at=created_at 로 정렬됨.
    // DATE_FORMAT 은 MySQL 전용 → 테스트 SQLite 호환 위해 PHP 에서 포맷 (project_db_tier_mismatch).
    // A-3 (2026-07-08) — 귀속월 = attributed_month(완납월, 달력 1일~말일) 우선. NULL(백필 전)은 payrollMonthOf fallback.
    #[Computed]
    public function availableMonths(): array
    {
        return Settlement::query()
            ->get(['attributed_month', 'confirmed_at', 'created_at'])
            ->map(fn ($s) => $s->attributed_month
                ? $s->attributed_month->format('Y-m')
                : \App\Support\SettlementCkBatch::payrollMonthOf($s->confirmed_at ?? $s->created_at))
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();
    }

    // monthFilter('YYYY-MM') → attributed_month 우선. NULL 은 기존 COALESCE 앵커[M/10,(M+1)/10) fallback (submitForMonth 와 동일).
    private function monthScope(): \Closure
    {
        if ($this->monthFilter === '') {
            return fn ($q) => $q;
        }
        $monthStart = $this->monthFilter.'-01';
        [$start, $end] = \App\Support\SettlementCkBatch::monthRange($this->monthFilter);
        $s = $start->format('Y-m-d H:i:s');
        $e = $end->format('Y-m-d H:i:s');

        return fn ($q) => $q->where(function ($q2) use ($monthStart, $s, $e) {
            $q2->whereDate('attributed_month', $monthStart)
                ->orWhere(function ($q3) use ($s, $e) {
                    $q3->whereNull('attributed_month')
                        ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$s])
                        ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$e]);
                });
        });
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
            ->when($this->monthFilter, $this->monthScope())
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
    // 현재 statusFilter / monthFilter / dateFrom / dateTo 동일 적용 (목록 SQL 과 일치).
    // computed accessor (total_margin / settlement_amount / actual_payout) 사용 → PHP 집계.
    #[Computed]
    public function salesmanSummaries(): array
    {
        $all = Settlement::query()
            ->with(['vehicle', 'salesman:id,name'])
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->monthFilter, $this->monthScope())
            ->when($this->dateFrom, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '>=', $this->dateFrom)
            ))
            ->when($this->dateTo, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '<=', $this->dateTo)
            ))
            ->get();

        return $all->groupBy('salesman_id')->map(function ($group, $salesmanId) {
            $first = $group->first();

            return [
                'salesman_id' => $salesmanId,
                'salesman_name' => $first->salesman?->name ?? __('settlement.summary_unassigned'),
                'count' => $group->count(),
                'total_margin_sum' => (int) $group->sum('total_margin'),
                'settlement_amount_sum' => (int) $group->sum('settlement_amount'),
                'actual_payout_sum' => (int) $group->sum('actual_payout'),
                // 미청산 이월 — Salesman accessor(단일 출처). 필터 무관 현재 잔액. 재무 사각지대 보완.
                'unconsumed_carryover' => (int) ($first->salesman?->unconsumed_carryover ?? 0),
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
        $carryoverIn = 0;
        $carryoverOut = 0;
        if ($this->editingId) {
            $settlement = Settlement::find($this->editingId);
            if ($settlement) {
                if ($this->settlement_type === 'ratio'
                    && $settlement->secondary_status === 'closed'
                    && $settlement->exchange_difference_krw !== null) {
                    $exchangeDiff = (int) $settlement->exchange_difference_krw;
                    $actualPayout += $exchangeDiff;
                }
                // 새회의 #8 보강 (2026-05-23) — 캐리오버 표시.
                if ($settlement->carryover_in_krw !== null) {
                    $carryoverIn = (int) $settlement->carryover_in_krw;
                    $actualPayout += $carryoverIn;
                }
                if ($settlement->carryover_out_krw !== null) {
                    $carryoverOut = (int) $settlement->carryover_out_krw;
                }
            }
        }

        return compact(
            'salesAmountKrw', 'settlementSalesKrw', 'salesMargin',
            'vatMargin', 'totalMargin', 'settlementAmount',
            'documentFee', 'actualPayout', 'exchangeDiff',
            'carryoverIn', 'carryoverOut'
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

        // 2026-07-06 재피벗 — baseline = 총판매가(외화) × 판매환율. close_rate 제거.
        // 환차 = 실입금KRW − baseline. 프리뷰·확정 동일 공식이라 괴리 없음.
        $saleRate = (float) ($v->exchange_rate ?? 0);
        if ($saleRate <= 0) {
            return array_merge($base, ['rate_unavailable' => true]);
        }
        $baselineKrw = (int) ((float) $v->sale_total_amount * $saleRate);

        // closed: 저장된 환차 사용 (확정값). baseline 은 판매환율 불변이라 재계산해도 동일.
        if ($settlement && $secondaryStatus === 'closed' && $settlement->exchange_difference_krw !== null) {
            return array_merge($base, [
                'baseline_krw' => $baselineKrw,
                'exchange_diff' => (float) $settlement->exchange_difference_krw,
                'is_preview' => false,
            ]);
        }

        // pending / null: 실입금 − baseline 미리보기 (마감 시 확정될 값과 동일).
        return array_merge($base, [
            'baseline_krw' => $baselineKrw,
            'exchange_diff' => (float) ($receivedKrw - $baselineKrw),
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

        unset($this->selectedVehicle, $this->marginData, $this->krwBreakdown);
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
        session()->flash('success', __('settlement.notify.saved'));
    }

    public function delete(int $id): void
    {
        // Review.md #1 (2026-06-09) — 모델 deleting 가드(confirmed/paid/closed 차단)가
        // 던지는 DomainException 을 토스트로 안내 (500 대신).
        try {
            Settlement::findOrFail($id)->delete();
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->settlements);
        session()->flash('success', __('settlement.notify.deleted'));
    }

    /**
     * 큐 14-4-2 — confirmed 정산을 paid로 전환 요청.
     * canApprove user는 직접 paid 변경 가능 (Settlement::saving 가드 통과).
     * 그 외 user는 이 메서드로 ApprovalRequest 생성 → /erp/approvals 큐로 진입.
     */
    // Phase 2 (jin 2026-07-07) — 선택한 귀속월의 confirmed 정산을 월배치로 제출 → 승인 사다리.
    //   [관리]/업무관리자만. 제출자보다 위 계단(업무관리자→대표) 순서대로 승인 → 대표 최종 시 일괄 paid.
    public function submitPayoutBatch(): void
    {
        if (! auth()->user()->canSubmitPayoutBatch()) {
            $this->dispatch('notify', message: __('settlement.batch.no_permission'), type: 'error');

            return;
        }
        if ($this->monthFilter === '') {
            $this->dispatch('notify', message: __('settlement.batch.select_month'), type: 'warning');

            return;
        }
        try {
            $batch = \App\Models\SettlementPayoutBatch::submitForMonth(auth()->user(), $this->monthFilter);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }
        unset($this->settlements, $this->salesmanSummaries);
        $this->dispatch('notify', message: __('settlement.batch.submitted', ['count' => $batch->settlement_count]), type: 'success');
    }

    public function requestPayApproval(int $id): void
    {
        $settlement = Settlement::findOrFail($id);
        if ($settlement->settlement_status !== 'confirmed') {
            $this->dispatch('notify', message: __('settlement.notify.pay_only_confirmed'), type: 'warning');

            return;
        }

        // 동일 정산에 대기중 요청 있으면 중복 차단
        $existing = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_SETTLEMENT_PAY)
            ->where('target_type', Settlement::class)
            ->where('target_id', $settlement->id)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->exists();
        if ($existing) {
            $this->dispatch('notify', message: __('settlement.notify.pay_duplicate'), type: 'warning');

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
            'reason' => __('settlement.notify.pay_reason', ['id' => $settlement->id, 'vehicle' => $settlement->vehicle?->vehicle_number ?? '?']),
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->dispatch('notify', message: __('settlement.notify.pay_sent'), type: 'success');
    }

    /**
     * 회의확장씬 #8 (2026-05-22) — 2차 정산 완료 (secondary_status='closed').
     * paid → secondary_pending (자동) 후 [관리]/[재무] 가 기타비용 수정 → 최종 마무리.
     * closed 이후 회계 잠금 (Vehicle 측 가드 Step B-2 에서 처리).
     *
     * 2026-07-06 재피벗 (실현손익) — close_rate 제거.
     *   환차(2차분) = 실입금KRW − baseline
     *     실입금KRW = sale_received_krw_accumulated (잔금 row환율 + 기타 판매환율)
     *     baseline  = sale_total_amount(총판매가 외화) × 판매환율(vehicle.exchange_rate)
     *   +이면 환차익 → 프리랜서 정산금 +, -이면 환차손 → -.
     *   완납게이트(sale_unpaid_amount ≤ 0) 하에서 기타 term 상쇄 → 순수 실현환차 보장.
     *   KRW 차량 또는 판매환율 없음 → 0/null (환차 없음).
     */
    public function closeSecondarySettlement(int $id): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isAdmin() || in_array($user?->role, ['재무', '관리'], true),
            403,
            __('settlement.forbidden_close')
        );

        $settlement = Settlement::findOrFail($id);
        if ($settlement->secondary_status !== 'pending') {
            $this->dispatch('notify', message: __('settlement.notify.close_not_pending'), type: 'warning');

            return;
        }

        // 완납 게이트 (2026-07-06 재피벗 #3) — 외화 차량은 원금 완납(sale_unpaid_amount ≤ 0) 후에만 2차 마감.
        // 미완납 상태로 마감하면 Σ잔금외화 < 총판매가외화 라 원금 미수가 "환차손"으로 둔갑함.
        // 완납 시에만 2차분 = 순수 실현환차 보장 (SKILLS §13, [[project_settlement_v2_groupware_design]]).
        // KRW 차량은 환차 개념이 없어 게이트 제외 — 기존 마감 동작 유지.
        $vehicle = $settlement->vehicle;
        if ($vehicle && $vehicle->currency !== 'KRW' && $vehicle->sale_unpaid_amount > 0) {
            $this->dispatch('notify', message: __('settlement.notify.close_needs_full_payment'), type: 'error');

            return;
        }

        // 환차 계산 (2026-07-06 재피벗) — 실입금KRW − baseline(총판매가×판매환율).
        [$exchangeDiff, $usedRate] = $this->calculateExchangeDifference($settlement);

        // 방어 — 판매환율이 0/null 이면 환차 계산 불가 (chk_sale_required 상 판매차량은 사실상 불가).
        if ($exchangeDiff === null) {
            $this->dispatch('notify', message: __('settlement.notify.close_needs_rate'), type: 'error');

            return;
        }

        $update = [
            'secondary_status' => 'closed',
            'secondary_closed_at' => now(),
            'exchange_difference_krw' => $exchangeDiff,
        ];
        if ($usedRate !== null) {
            $update['exchange_rate_at_close'] = $usedRate;
        }
        $settlement->update($update);

        // 새회의 #8 보강 (2026-05-23) — 캐리오버 계산.
        // carryover_out_krw = closed actual_payout (cost·환차 모두 반영) - paid snapshot actual_payout
        // 다음 영업담당자 정산 creating 훅이 자동 흡수.
        $paidSnapshotPayout = (int) ($settlement->confirmed_snapshot['actual_payout'] ?? 0);
        $closedPayout = $settlement->fresh()->actual_payout;
        $carryoverOut = $closedPayout - $paidSnapshotPayout;
        if ($carryoverOut !== 0) {
            $settlement->update(['carryover_out_krw' => $carryoverOut]);
        }

        unset($this->settlements);
        $msg = __('settlement.notify.close_done');
        if ($exchangeDiff !== null && abs($exchangeDiff) > 0.01) {
            $sign = $exchangeDiff > 0 ? '+' : '';
            $msg .= __('settlement.notify.close_diff_suffix', ['sign' => $sign, 'amount' => number_format($exchangeDiff)]);
        }
        if ($carryoverOut !== 0) {
            $sign = $carryoverOut > 0 ? '+' : '';
            $msg .= __('settlement.notify.close_carry_suffix', ['sign' => $sign, 'amount' => number_format($carryoverOut)]);
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

        // 2026-07-06 재피벗 — close_rate 제거, baseline = 판매환율 고정.
        $saleRate = (float) ($vehicle->exchange_rate ?? 0);
        if ($saleRate <= 0) {
            return [null, null];   // 판매환율 없음 — 계산 불가 (chk_sale_required 상 사실상 불가)
        }

        // 환차 = 실입금KRW − baseline. baseline = 총판매가 외화 × 판매환율.
        $receivedKrw = (float) $vehicle->sale_received_krw_accumulated;
        $baselineKrw = (float) $vehicle->sale_total_amount * $saleRate;

        // 두 번째 반환값 null → exchange_rate_at_close 미기록 (컬럼 deprecate, closed 감사행만 보존).
        return [$receivedKrw - $baselineKrw, null];
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
        <h1 class="text-xl font-bold text-gray-800">{{ __('settlement.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('settlement.total', ['count' => $this->settlements->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('settlement.add') }}
        </button>
    </div>
</div>

{{-- 필터 바 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="{{ __('settlement.search_ph') }}"
           class="input-filter w-36" />
    <select wire:model="statusFilter" class="input-filter">
        <option value="">{{ __('settlement.filter_all_status') }}</option>
        <option value="pending">{{ __('settlement.status.pending') }}</option>
        <option value="calculating">{{ __('settlement.status.calculating') }}</option>
        <option value="confirmed">{{ __('settlement.status.confirmed') }}</option>
        <option value="paid">{{ __('settlement.status.paid') }}</option>
    </select>
    <select wire:model="salesmanFilter" class="input-filter">
        <option value="0">{{ __('settlement.filter_all_salesman') }}</option>
        @foreach($this->salesmen as $sm)
        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    {{-- 정산 귀속월(attributed_month=완납월, 1일~말일) 솔팅 + 익월 10일 지급 라벨 (A-3 2026-07-08). --}}
    <select wire:model.live="monthFilter" class="input-filter" title="{{ __('settlement.filter_month_title') }}">
        <option value="">{{ __('settlement.filter_all_month') }}</option>
        @foreach($this->availableMonths as $ym)
        @php $payDate = \Carbon\Carbon::parse($ym.'-01')->addMonthNoOverflow()->format('Y-m').'-10'; @endphp
        <option value="{{ $ym }}">{{ $ym }} {{ __('settlement.filter_month_label') }} → {{ $payDate }} {{ __('settlement.filter_month_pay') }}</option>
        @endforeach
    </select>
    <input wire:model="dateFrom" type="date" class="input-filter" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model="dateTo" type="date" class="input-filter" />
    <button wire:click="search" class="btn-search">{{ __('common.search') }}</button>
    @if(auth()->user()->canSubmitPayoutBatch() && $monthFilter !== '')
    {{-- 승인큐 이동링크 제거 (2026-07-07 jin) — 사이드바 정산그룹 「승인큐」 메뉴로 접근. 여기선 헷갈림만 유발. --}}
    <button wire:click="submitPayoutBatch" wire:confirm="{{ __('settlement.batch.confirm_submit', ['month' => $monthFilter]) }}"
            class="btn-primary text-xs">{{ __('settlement.batch.submit') }}</button>
    @endif
</div>

{{-- 2026-05-20 #2 피드백 — 영업담당자별 합계 카드 (인원별 솔팅 + 합계). --}}
{{-- 클릭 시 해당 담당자 필터 토글. statusFilter / dateFrom/To 와 동일 컨텍스트. --}}
@if(!empty($this->salesmanSummaries))
<div class="mt-3">
    <div class="mb-2 flex items-center gap-2 text-xs text-gray-500">
        <span>{{ __('settlement.summary_title') }}</span>
        <span class="text-gray-400">{{ __('settlement.summary_hint') }}</span>
    </div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        @foreach($this->salesmanSummaries as $summary)
        <button type="button" wire:click="setSalesmanFilter({{ $summary['salesman_id'] ?? 0 }})"
                class="card text-left transition hover:bg-violet-50 {{ $salesmanFilter == $summary['salesman_id'] ? 'border-violet-400 bg-violet-50/40' : '' }}">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-700">{{ $summary['salesman_name'] }}</span>
                <span class="pill-count">{{ __('settlement.summary_count', ['count' => $summary['count']]) }}</span>
            </div>
            <div class="mt-2 space-y-1 text-[11px]">
                <div class="flex items-center justify-between text-gray-500">
                    <span>{{ __('settlement.summary_total_margin') }}</span>
                    <span class="font-mono text-gray-700">{{ number_format($summary['total_margin_sum']) }}</span>
                </div>
                <div class="flex items-center justify-between text-gray-500">
                    <span>{{ __('settlement.summary_settlement_amount') }}</span>
                    <span class="font-mono text-gray-700">{{ number_format($summary['settlement_amount_sum']) }}</span>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 pt-1">
                    <span class="text-violet-700">{{ __('settlement.summary_actual_payout') }}</span>
                    <span class="font-mono font-semibold text-violet-700">{{ number_format($summary['actual_payout_sum']) }}</span>
                </div>
                @if(($summary['unconsumed_carryover'] ?? 0) != 0)
                <div class="flex items-center justify-between border-t border-gray-100 pt-1">
                    <span class="{{ $summary['unconsumed_carryover'] > 0 ? 'text-emerald-600' : 'text-red-500' }}">{{ __('settlement.summary_carryover') }}</span>
                    <span class="font-mono font-semibold {{ $summary['unconsumed_carryover'] > 0 ? 'text-emerald-600' : 'text-red-500' }}">{{ $summary['unconsumed_carryover'] > 0 ? '+' : '−' }}{{ number_format(abs($summary['unconsumed_carryover'])) }}</span>
                </div>
                @endif
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
                <th class="pb-2 pr-4 font-medium">{{ __('settlement.col.vehicle_no') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('settlement.col.salesman') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('settlement.col.progress') }}</th>
                {{-- 2026-05-20 #2 피드백 — 입금률 게이지 (거래완료 미완납 시 정산 진행 차단 정보) --}}
                <th class="pb-2 pr-4 font-medium" style="min-width: 110px;">{{ __('settlement.col.paid_ratio') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('settlement.col.type') }}</th>
                {{-- 매입가 — 사내직원 차등 tier 트리거(≥1억→총마진×25%). 방식↔총마진 사이 기준값. --}}
                <th class="pb-2 pr-4 font-medium text-right">{{ __('settlement.col.purchase_price') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('settlement.col.total_margin') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('settlement.col.settlement_amount') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('settlement.col.actual_payout') }}</th>
                {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차익 컬럼 (closed 정산만 stored value 표시). --}}
                <th class="pb-2 pr-4 font-medium text-right">{{ __('settlement.col.exchange_diff') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('settlement.col.status') }}</th>
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
                $statusLabel = __('settlement.status.'.$s->settlement_status);
                // 회의확장씬 #8 (2026-05-22) — 2차 정산 status 보강 라벨.
                $secondaryLabel = in_array($s->secondary_status, ['pending', 'closed'], true) ? __('settlement.secondary.'.$s->secondary_status) : null;
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
                    <span class="badge {{ $progressBadge }}">{{ __('domain.progress.'.$s->vehicle->progress_status) }}</span>
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
                        <span class="text-[10px] text-gray-400">{{ __('settlement.paid_ratio_no_rate') }}</span>
                    @elseif($ratio <= 0)
                        <div class="flex items-center gap-1">
                            <div class="h-2 w-full rounded-full bg-green-100 overflow-hidden">
                                <div class="h-full bg-green-500" style="width: 100%;"></div>
                            </div>
                            <span class="text-[10px] font-medium text-green-700">{{ __('settlement.paid_ratio_full') }}</span>
                        </div>
                    @else
                        @php $paidPct = max(0, min(100, (1 - $ratio) * 100)); @endphp
                        <div class="flex items-center gap-1">
                            <div class="h-2 w-full rounded-full bg-amber-100 overflow-hidden">
                                <div class="h-full bg-amber-500" style="width: {{ $paidPct }}%;"></div>
                            </div>
                            <span class="text-[10px] font-medium text-amber-700">{{ number_format($paidPct, 0) }}%</span>
                        </div>
                        <div class="mt-0.5 text-[10px] text-amber-700">{{ __('settlement.paid_ratio_receivable', ['amount' => number_format($unpaidAmount)]) }}</div>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-600">
                    @if($s->settlement_type === 'ratio')
                        {{ __('settlement.ratio_unit', ['ratio' => number_format($s->settlement_ratio, 1)]) }}
                    @else
                        {{ __('settlement.per_unit_unit', ['amount' => number_format($s->per_unit_amount)]) }}
                    @endif
                </td>
                {{-- 매입가 — tier 기준값(₩1억 이상이면 총마진×25%). 1억↑은 강조. --}}
                <td class="py-3 pr-4 text-right {{ ($s->vehicle?->purchase_price ?? 0) >= 100000000 ? 'font-semibold text-primary-text' : 'text-gray-500' }}">
                    ₩{{ number_format($s->vehicle?->purchase_price ?? 0) }}
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
                        <span class="text-gray-300" title="{{ __('settlement.exchange_krw_vehicle_title') }}">—</span>
                    @elseif($s->secondary_status === 'closed' && $s->exchange_difference_krw !== null)
                        @php $diff = (float) $s->exchange_difference_krw; @endphp
                        @if($diff > 0)
                        <span class="font-semibold text-emerald-600" title="{{ __('settlement.exchange_profit_title') }}">+₩{{ number_format($diff) }}</span>
                        @elseif($diff < 0)
                        <span class="font-semibold text-red-600" title="{{ __('settlement.exchange_loss_title') }}">-₩{{ number_format(abs($diff)) }}</span>
                        @else
                        <span class="text-gray-400" title="{{ __('settlement.exchange_same_title') }}">₩0</span>
                        @endif
                    @else
                        <span class="text-gray-300" title="{{ __('settlement.exchange_after_close') }}">—</span>
                    @endif
                </td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                    {{-- 회의확장씬 #8 (2026-05-22) — 2차 정산 상태 보강 라벨 --}}
                    @if($secondaryLabel)
                    <span class="badge {{ $secondaryBadge }} ml-1" title="{{ __('settlement.col.status') }}">{{ $secondaryLabel }}</span>
                    @endif
                    {{-- 큐 14-4-2 — 지급 승인 요청 상태 인라인 표시 --}}
                    @php $pa = $s->latestPayApproval; @endphp
                    @if($pa && $pa->status === 'pending')
                    <span class="badge badge-amber ml-1" title="{{ __('settlement.approval_pending_title') }}">{{ __('settlement.approval_pending') }}</span>
                    @elseif($pa && $pa->status === 'rejected')
                    <span class="badge badge-red ml-1" title="{{ __('settlement.approval_rejected_title', ['name' => $pa->approver?->name ?? '?', 'reason' => $pa->decision_note ?? __('settlement.approval_no_reason')]) }}">
                        {{ __('settlement.approval_rejected') }}
                    </span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <div class="flex justify-end gap-2">
                        {{-- Phase 2 (2026-07-07) — 개별 지급 승인요청 은퇴. [관리]/업무관리자가 '월배치 제출'로 진행.
                             (레거시 requestPayApproval 메서드/executeSettlementPay 는 기존 pending 처리용 존치, 대표만 실행) --}}
                        {{-- 회의확장씬 #8 (2026-05-22) — 2차 정산 완료 액션 ([재무]/[관리]/admin) --}}
                        @if($canCloseSecondary)
                        <button wire:click.stop="closeSecondarySettlement({{ $s->id }})"
                                wire:confirm="{{ __('settlement.confirm_close_secondary') }}"
                                class="text-xs text-violet-600 hover:text-violet-800">{{ __('settlement.btn_close_secondary') }}</button>
                        @endif
                        <button wire:click.stop="delete({{ $s->id }})"
                                wire:confirm="{{ __('settlement.confirm_delete') }}"
                                class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="py-12 text-center text-sm text-gray-400">{{ __('settlement.empty') }}</td>
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
        $statusLabel = __('settlement.status.'.$s->settlement_status);
    @endphp
    <div class="card-tight cursor-pointer" wire:click="openEdit({{ $s->id }})">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-800">{{ $s->vehicle?->vehicle_number ?? '-' }}</div>
            <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-gray-500">
            <div>{{ __('settlement.mobile_salesman') }}: {{ $s->salesman?->name ?? '-' }}</div>
            <div>{{ __('settlement.mobile_type') }}: {{ $s->settlement_type === 'ratio' ? number_format($s->settlement_ratio, 1).'%' : __('settlement.mobile_type_per_unit') }}</div>
            <div>{{ __('settlement.mobile_purchase_price') }}: ₩{{ number_format($s->vehicle?->purchase_price ?? 0) }}</div>
            <div>{{ __('settlement.mobile_total_margin') }}: ₩{{ number_format($s->total_margin) }}</div>
            <div class="font-semibold text-gray-700">{{ __('settlement.mobile_actual') }}: ₩{{ number_format($s->actual_payout) }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('settlement.empty') }}</div>
    @endforelse
</div>

{{-- 하단 여백(pb-28) — 우하단 고정 통관서류 알람 위젯과 페이지네이션 화살표가 겹쳐 클릭 방해되던 문제 해소 (2026-07-07 jin). --}}
<div class="pb-28">{{ $this->settlements->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[520px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('settlement.panel_title_edit') : __('settlement.panel_title_add') }}</h2>
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
                <p class="text-xs font-semibold text-red-700">{{ __('settlement.error_box_title') }}</p>
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
                <span class="section-title">{{ __('settlement.section_vehicle') }}</span>
            </div>
            @if(! $editingId)
            <div class="relative">
                <input wire:model.live.debounce.300ms="vehicleSearch"
                       type="text"
                       placeholder="{{ __('settlement.vehicle_search_ph') }}"
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
                <span class="text-blue-500">{{ __('domain.progress.'.$this->selectedVehicle->progress_status) }}</span>
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
                <span class="badge {{ $pb }}">{{ __('domain.progress.'.$this->selectedVehicle->progress_status) }}</span>
            </div>
            @endif
            @endif
        </div>

        {{-- 마진 산출내역 --}}
        @if(! empty($this->marginData))
        <div>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('settlement.section_margin') }}</span>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.margin_sales_krw') }} <span class="text-xs text-gray-400">{{ __('settlement.margin_sales_krw_formula') }}</span></span>
                    <span>₩{{ number_format($this->marginData['salesAmountKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.margin_settle_sales_krw') }} <span class="text-xs text-gray-400">{{ __('settlement.margin_settle_sales_formula') }}</span></span>
                    <span>₩{{ number_format($this->marginData['settlementSalesKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.margin_sales_margin') }} <span class="text-xs text-gray-400">{{ __('settlement.margin_sales_margin_formula') }}</span></span>
                    <span class="{{ $this->marginData['salesMargin'] < 0 ? 'text-red-500' : '' }}">₩{{ number_format($this->marginData['salesMargin']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.margin_vat') }} <span class="text-xs text-gray-400">{{ __('settlement.margin_vat_formula') }}</span></span>
                    <span>₩{{ number_format($this->marginData['vatMargin']) }}</span>
                </div>
                <hr class="border-gray-200" />
                <div class="flex justify-between font-semibold text-gray-800">
                    <span>{{ __('settlement.margin_total') }} <span class="text-xs text-gray-400 font-normal">{{ __('settlement.margin_total_formula') }}</span></span>
                    <span class="{{ $this->marginData['totalMargin'] < 0 ? 'text-red-600' : '' }}">₩{{ number_format($this->marginData['totalMargin']) }}</span>
                </div>
            </div>
        </div>
        @endif

        {{-- 적용 비용 내역 (cost_total 분해) — read-only. 2차 정산에서 반영된 실측 비용을 투명화. --}}
        @if($this->selectedVehicle)
        @php
            $sv = $this->selectedVehicle;
            $costRows = [
                'cost_deregistration' => (int) $sv->cost_deregistration,
                'cost_license'        => (int) $sv->cost_license,
                'cost_towing'         => (int) $sv->cost_towing,
                'cost_carry'          => (int) $sv->cost_carry,
                'cost_shoring'        => (int) $sv->cost_shoring,
                'cost_insurance'      => (int) $sv->cost_insurance,
                'cost_transfer'       => (int) $sv->cost_transfer,
                'cost_extra1'         => (int) $sv->cost_extra1,
                'cost_extra2'         => (int) $sv->cost_extra2,
            ];
            $costTotalSum = array_sum($costRows);
        @endphp
        <div>
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">{{ __('settlement.section_costs') }}</span>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1">
                @foreach($costRows as $col => $amt)
                <div class="flex justify-between {{ $amt === 0 ? 'text-gray-300' : 'text-gray-600' }}">
                    <span>{{ __('vehicle.field.'.$col) }}</span>
                    <span>₩{{ number_format($amt) }}</span>
                </div>
                @endforeach
                <hr class="border-gray-200" />
                <div class="flex justify-between font-semibold text-gray-800">
                    <span>{{ __('settlement.costs_total') }} <span class="text-xs text-gray-400 font-normal">{{ __('settlement.costs_total_sub') }}</span></span>
                    <span>₩{{ number_format($costTotalSum) }}</span>
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
                    {{ __('settlement.krw_title') }}
                    @if($kb['status'] === 'closed')
                    <span class="ml-1 text-emerald-600 font-semibold">{{ __('settlement.krw_confirmed') }}</span>
                    @elseif($kb['status'] === 'pending')
                    <span class="ml-1 text-amber-600 font-semibold">{{ __('settlement.krw_secondary_pending') }}</span>
                    @endif
                </span>
            </div>
            @if($kb['is_krw_vehicle'])
            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 text-center">
                {{ __('settlement.krw_krw_vehicle') }}
            </div>
            @else
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.krw_primary') }} <span class="text-xs text-gray-400">{{ __('settlement.krw_primary_sub') }}</span></span>
                    <span>₩{{ number_format($kb['primary_krw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>{{ __('settlement.krw_received') }} <span class="text-xs text-gray-400">{{ __('settlement.krw_received_sub') }}</span></span>
                    <span>₩{{ number_format($kb['received_krw']) }}</span>
                </div>

                @if(! empty($kb['rate_unavailable']))
                <div class="mt-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-700">
                    {{ __('settlement.krw_rate_unavailable') }}
                </div>
                @elseif(! empty($kb['is_preview']))
                {{-- pending — 실입금 − baseline 라이브 미리보기 (2026-07-06 재피벗, 마감 시 확정값과 동일) --}}
                <hr class="border-gray-200">
                @php
                    $canEditRate = auth()->user()?->isAdmin()
                        || in_array(auth()->user()?->role, ['재무', '관리'], true);
                @endphp
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">{{ __('settlement.krw_baseline') }} <span class="text-[10px] text-gray-400">{{ __('settlement.krw_baseline_sub') }}</span></span>
                    <span class="text-gray-700">₩{{ number_format($kb['baseline_krw']) }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">{{ __('settlement.krw_expected_diff') }} <span class="text-[10px] text-gray-400">{{ __('settlement.krw_expected_diff_sub') }}</span></span>
                    @if($kb['exchange_diff'] > 0)
                    <span class="text-emerald-600 font-medium">+₩{{ number_format($kb['exchange_diff']) }} {{ __('settlement.diff_profit_suffix') }}</span>
                    @elseif($kb['exchange_diff'] < 0)
                    <span class="text-red-600 font-medium">-₩{{ number_format(abs($kb['exchange_diff'])) }} {{ __('settlement.diff_loss_suffix') }}</span>
                    @else
                    <span class="text-gray-400">{{ __('settlement.diff_same') }}</span>
                    @endif
                </div>
                {{-- 회의확장씬 #9 보강 안내 (2026-05-23) — 기타비용 수정 위치 안내. --}}
                @php
                    $vehicleEditUrl = $this->selectedVehicle
                        ? route('erp.vehicles.index').'?openVehicle='.$this->selectedVehicle->id
                        : null;
                @endphp
                <div class="mt-2 rounded border border-blue-200 bg-blue-50 px-2 py-2 text-[11px] text-blue-800 space-y-1">
                    <div class="font-semibold">{{ __('settlement.extra_cost_title') }}</div>
                    <div class="text-blue-700">
                        {{ __('settlement.extra_body_1') }}<strong>{{ __('settlement.extra_strong_1') }}</strong>{{ __('settlement.extra_body_2') }}<strong>{{ __('settlement.extra_strong_2') }}</strong>{{ __('settlement.extra_body_3') }}
                    </div>
                    @if($vehicleEditUrl)
                    <a href="{{ $vehicleEditUrl }}" wire:navigate
                       class="inline-flex items-center gap-1 mt-1 text-violet-700 hover:underline font-medium">
                        {{ __('settlement.btn_vehicle_edit') }}
                    </a>
                    @endif
                </div>

                @if($canEditRate)
                <button type="button"
                        wire:click="closeSecondarySettlement({{ $this->editingId }})"
                        wire:confirm="{{ __('settlement.confirm_close_secondary') }}"
                        class="mt-2 w-full rounded bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700">
                    {{ __('settlement.btn_close_secondary_full') }}
                </button>
                @endif
                @else
                {{-- closed — 저장된 확정값 (2026-07-06 재피벗: baseline=판매환율) --}}
                <hr class="border-gray-200">
                <div class="flex justify-between text-gray-700">
                    <span>{{ __('settlement.krw_baseline') }} <span class="text-xs text-gray-400">{{ __('settlement.krw_baseline_sub') }}</span></span>
                    <span class="font-medium">₩{{ number_format($kb['baseline_krw']) }}</span>
                </div>
                <div class="flex justify-between font-semibold">
                    <span class="text-gray-800">{{ __('settlement.krw_diff_confirmed') }}</span>
                    @if($kb['exchange_diff'] > 0)
                    <span class="text-emerald-600">+₩{{ number_format($kb['exchange_diff']) }} {{ __('settlement.diff_profit_suffix') }}</span>
                    @elseif($kb['exchange_diff'] < 0)
                    <span class="text-red-600">-₩{{ number_format(abs($kb['exchange_diff'])) }} {{ __('settlement.diff_loss_suffix') }}</span>
                    @else
                    <span class="text-gray-500">{{ __('settlement.diff_same') }}</span>
                    @endif
                </div>
                @endif
            </div>
            @endif
        </div>
        @endif

        {{-- 정산 설정 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">{{ __('settlement.section_settle') }}</span>
            </div>

            {{-- 담당자 --}}
            <div class="mb-3">
                <label class="label-base">{{ __('settlement.field_salesman') }}</label>
                <select wire:model="salesman_id" class="input-base">
                    <option value="">{{ __('settlement.salesman_none') }}</option>
                    @foreach($this->salesmen as $sm)
                    <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- 정산방식 --}}
            <div class="mb-3">
                <label class="label-base">{{ __('settlement.field_type') }} <span class="text-red-500">*</span></label>
                <div class="mt-1.5 flex gap-5">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="ratio" class="accent-primary" />
                        {{ __('settlement.type_ratio') }}
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="per_unit" class="accent-primary" />
                        {{ __('settlement.type_per_unit') }}
                    </label>
                </div>
            </div>

            @if($settlement_type === 'ratio')
            <div class="mb-3">
                <label class="label-base">{{ __('settlement.field_ratio') }} <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="settlement_ratio"
                       type="number" step="0.01" min="0" max="100"
                       class="input-base" placeholder="{{ __('settlement.field_ratio_ph') }}" />
                @error('settlement_ratio')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @else
            <div class="mb-3">
                <label class="label-base">{{ __('settlement.field_per_unit') }} <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="per_unit_amount"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="{{ __('settlement.field_per_unit_ph') }}" />
                @error('per_unit_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif

            <div class="mb-3">
                <label class="label-base">{{ __('settlement.field_other_deduction') }}</label>
                <input wire:model.live.debounce.500ms="other_deduction"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="0" />
            </div>
        </div>

        {{-- 정산 결과 --}}
        @if(! empty($this->marginData))
        <div class="rounded-lg bg-purple-50 p-3 text-sm space-y-1.5">
            <div class="flex justify-between text-gray-600">
                <span>{{ __('settlement.result_settlement_amount') }}
                    @if($settlement_type === 'ratio')
                        <span class="text-xs text-gray-400">{{ __('settlement.result_ratio_formula', ['ratio' => ($settlement_ratio ?? null) !== null && (float) $settlement_ratio > 0 ? $settlement_ratio : 50]) }}</span>
                    @else
                        <span class="text-xs text-gray-400">{{ __('settlement.result_per_unit_formula') }}</span>
                    @endif
                </span>
                <span class="font-medium">₩{{ number_format($this->marginData['settlementAmount']) }}</span>
            </div>
            {{-- 2026-05-21 — 서류비 행 추가. 프리랜서(ratio)만 5만원 자동 차감 --}}
            @if($this->marginData['documentFee'] > 0)
            <div class="flex justify-between text-gray-500">
                <span>{{ __('settlement.result_document_fee') }} <span class="text-xs text-gray-400">{{ __('settlement.result_document_fee_sub') }}</span></span>
                <span class="text-red-500">- ₩{{ number_format($this->marginData['documentFee']) }}</span>
            </div>
            @endif
            <div class="flex justify-between text-gray-500">
                <span>{{ __('settlement.result_other_deduction') }}</span>
                <span class="text-red-500">- ₩{{ number_format((int) ($other_deduction ?? 0)) }}</span>
            </div>
            {{-- 회의확장씬 #6+7 보강 (2026-05-23) — 환차 자동 반영 라인 (프리랜서 closed 일 때만). --}}
            @if(! empty($this->marginData['exchangeDiff']))
            <div class="flex justify-between text-gray-600">
                <span>{{ __('settlement.result_exchange') }} <span class="text-xs text-gray-400">{{ __('settlement.result_exchange_sub') }}</span></span>
                @if($this->marginData['exchangeDiff'] > 0)
                <span class="text-emerald-600">+ ₩{{ number_format($this->marginData['exchangeDiff']) }}</span>
                @else
                <span class="text-red-500">- ₩{{ number_format(abs($this->marginData['exchangeDiff'])) }}</span>
                @endif
            </div>
            @endif
            {{-- 새회의 #8 보강 (2026-05-23) — 전월 이월 (영업담당자 카운오버). --}}
            @if(! empty($this->marginData['carryoverIn']))
            <div class="flex justify-between text-gray-600">
                <span>{{ __('settlement.result_carryover_in') }} <span class="text-xs text-gray-400">{{ __('settlement.result_carryover_in_sub') }}</span></span>
                @if($this->marginData['carryoverIn'] > 0)
                <span class="text-emerald-600">+ ₩{{ number_format($this->marginData['carryoverIn']) }}</span>
                @else
                <span class="text-red-500">- ₩{{ number_format(abs($this->marginData['carryoverIn'])) }}</span>
                @endif
            </div>
            @endif
            <hr class="border-purple-200" />
            <div class="flex justify-between text-base font-bold">
                <span class="text-gray-800">{{ __('settlement.result_actual_payout') }}</span>
                <span class="{{ $this->marginData['actualPayout'] < 0 ? 'text-red-600' : 'text-purple-700' }}">
                    ₩{{ number_format($this->marginData['actualPayout']) }}
                </span>
            </div>
            {{-- 새회의 #8 보강 (2026-05-23) — 다음 달 이월 표시 (closed + carryover_out_krw 존재 시). --}}
            @if(! empty($this->marginData['carryoverOut']))
            <div class="mt-1 rounded border border-violet-200 bg-violet-50 px-2 py-1.5 text-[11px] text-violet-700">
                <strong>{{ __('settlement.result_carryover_out') }}</strong>
                @if($this->marginData['carryoverOut'] > 0)
                <span class="text-emerald-700">+₩{{ number_format($this->marginData['carryoverOut']) }}</span>
                @else
                <span class="text-red-600">-₩{{ number_format(abs($this->marginData['carryoverOut'])) }}</span>
                @endif
                {{ __('settlement.result_carryover_out_note') }}
            </div>
            @endif
        </div>
        @endif

        {{-- 진행상태 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">{{ __('settlement.section_status') }}</span>
            </div>
            <select wire:model="settlement_status" class="input-base">
                <option value="pending">{{ __('settlement.status.pending') }}</option>
                <option value="calculating">{{ __('settlement.status.calculating') }}</option>
                <option value="confirmed">{{ __('settlement.status.confirmed') }}</option>
                <option value="paid">{{ __('settlement.status.paid') }}</option>
            </select>
            @if($editingId)
            @php
                $existing = \App\Models\Settlement::find($editingId);
            @endphp
            @if($existing?->confirmed_at)
            <p class="mt-1 text-xs text-gray-400">{{ __('settlement.confirmed_at', ['datetime' => $existing->confirmed_at->format('Y-m-d H:i')]) }}</p>
            @endif
            @if($existing?->paid_at)
            <p class="mt-0.5 text-xs text-gray-400">{{ __('settlement.paid_at', ['datetime' => $existing->paid_at->format('Y-m-d H:i')]) }}</p>
            @endif
            @endif
        </div>

        {{-- 메모 --}}
        <div>
            <label class="label-base">{{ __('settlement.field_memo') }}</label>
            <textarea wire:model="note" class="input-base" rows="2" placeholder="{{ __('settlement.memo_ph') }}"></textarea>
        </div>

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            {{ __('common.cancel') }}
        </button>
        <button wire:click="save" class="btn-primary"
                wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span>
            <span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>
@endif

</div>
