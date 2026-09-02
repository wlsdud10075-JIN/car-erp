<?php

use App\Models\ApprovalRequest;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Support\SearchTerm;
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

    // 지급 게이트 (jin 2026-07-08) — 미수로 지급보류된 확정 정산만 보기(재무 대시보드 딥링크 ?held=1).
    //   URL 파라미터명 = 'held' (as 별칭). 재무 대시보드 '미수로 지급보류' 클릭 → ?held=1.
    #[Url(as: 'held')] public bool $heldOnly = false;

    public int $salesmanFilter = 0;

    public string $dateFrom = '';

    public string $dateTo = '';

    // 2026-06-24 — 정산 월(월급 귀속월) 솔팅. 기준 = created_at(정산 발생/거래완료월, jin 결정).
    // 'YYYY-MM' 형식. 빈 문자열 = 전체. 1일~말일 일한 정산 → 다음달 10일 지급 주기를 월 단위로 묶음.
    #[Url] public string $monthFilter = '';

    #[Url] public int $perPage = 10;

    /**
     * 담당자별 합계 펼침 — **기본 접힘**.
     *
     * 🚨 이게 성능의 핵심이다. 이 합계는 **필터에 걸린 정산 전부를 순회**하며 행마다
     *    총마진·정산액·실지급액 accessor 를 돈다. 실측(ssancarerp 3,815건) 7.9초다.
     *    게다가 이 화면은 `wire:poll.30s` 라 **30초마다 그 비용이 반복**된다.
     * 🔑 **Alpine 으로 숨기는 것으로는 안 줄어든다** — 서버가 HTML 을 다 만들어 보낸다.
     *    서버 계산까지 빼려면 접힘이 **서버 상태**여야 하고, 접혀 있으면 Blade 가
     *    `$this->salesmanSummaries` 를 아예 안 부른다(그러면 `#[Computed]` 도 안 돈다).
     */
    #[Url(as: 'sum')]
    public bool $showSummaries = false;

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

    // 정산 락 개편 (jin 2026-07-24) — 마감(closed) 정산 회계 재조정(잠금 해제) 모달.
    //   잠금 원인이 2차 정산 마감이므로 잠금 해제도 정산 화면에서 시작 → 차량 편집으로 이동해 수정.
    public bool $showReadjustModal = false;

    public string $readjustReason = '';

    public ?int $readjustVehicleId = null;

    public function searchNow(): void
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
            // 성능(jin 2026-07-23): attributed_month 인덱스 유지 위해 whereDate(DATE())→시간경계 범위.
            //   ⚠ SQLite(테스트)는 date 를 'Y-m-d 00:00:00' 저장 → plain where 불일치. 명시 경계로 양쪽 DB 안전.
            //   (ssancar 504 교훈 패턴② / DB tier 불일치 대응)
            $q2->whereBetween('attributed_month', [$monthStart.' 00:00:00', $monthStart.' 23:59:59'])
                ->orWhere(function ($q3) use ($s, $e) {
                    $q3->whereNull('attributed_month')
                        ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$s])
                        ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$e]);
                });
        });
    }

    public function mount(): void
    {
        // 재무 대시보드 '미수로 지급보류' 딥링크(?held=1) 진입 시 = 전체담당자 + 지급보류만.
        //
        // ⚠️ 딥링크엔 **월 기본값을 걸지 않는다** — 지급보류는 달과 무관한 「잔액」이라
        //    이번 달로 좁히면 대시보드가 센 건수와 화면이 갈린다.
        if ($this->heldOnly) {
            $this->salesmanFilter = 0;

            return;
        }

        // 월 필터 기본값 = 이번 달 (jin 2026-08-28).
        //
        // 🚨 성능이 이유다. 담당자별 합계는 필터에 걸린 정산 **전부**를 PHP 로 순회하며
        //    행마다 총마진·정산액 accessor 를 돈다 — 전 기간(ssancarerp 3,815건)이면 8.1초다.
        //    월로 좁히면 ~1.3초. 목록 SQL 도 같이 좁아진다.
        // 🧭 「전 기간」은 없어지지 않았다 — 월 선택을 '전체'로 비우면 그대로 나온다(그때만 8.1초).
        // ⚠️ URL 에 month 가 실려 오면 그 값이 이긴다(#[Url] 이 mount 전에 채운다).
        // 🚨 `now()->format('Y-m')` 이 아니다 — 귀속월 경계가 **10일**이라(monthRange = [M/10, (M+1)/10))
        //    매월 1~9일에는 그 달 라벨의 범위가 아직 시작도 안 했다. 그대로 쓰면 그 9일 동안
        //    **오늘 만들어진 정산이 기본 화면에서 통째로 사라진다**(2026-09-01 실측으로 발견).
        //    역함수 단일 출처 = SettlementCkBatch::payrollMonthOf (백필 명령·집계가 쓰는 것과 같다).
        if ($this->monthFilter === '') {
            $this->monthFilter = \App\Support\SettlementCkBatch::payrollMonthOf(now());
        }
    }

    // ── 목록 ──────────────────────────────────────────────────────

    #[Computed]
    public function settlements()
    {
        return Settlement::query()
            ->with(['vehicle.finalPayments', 'vehicle.purchaseBalancePayments', 'salesman', 'latestPayApproval.approver'])
            ->when(SearchTerm::of($this->search), fn ($q) => $q->searchTerm($this->search))
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->heldOnly, fn ($q) => $q->payoutHeldByUnpaid())
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

    /** 지급보류(미수)만 보기 토글 — 재무 대시보드 딥링크와 동일 필터. */
    public function toggleHeld(): void
    {
        $this->heldOnly = ! $this->heldOnly;

        // 🚨 켤 때는 **월 필터를 푼다** — 지급보류는 달과 무관한 「잔액」이다(미수는 기간으로 안 자른다).
        //    월 기본값(mount)이 생긴 뒤로, 안 풀면 이번 달에 만든 정산만 보여 «보류가 없다»로 읽힌다.
        //    딥링크(?held=1)가 mount 에서 월을 안 거는 것과 같은 규칙이다.
        if ($this->heldOnly) {
            $this->monthFilter = '';
        }

        $this->resetPage();
    }

    // 2026-05-20 #2 피드백 — 영업담당자별 합계 (인원별 솔팅 + 합계 KPI).
    // 현재 statusFilter / monthFilter / dateFrom / dateTo 동일 적용 (목록 SQL 과 일치).
    // computed accessor (total_margin / settlement_amount / actual_payout) 사용 → PHP 집계.
    #[Computed]
    public function salesmanSummaries(): array
    {
        $all = Settlement::query()
            // ⚠️ salesman 컬럼을 제한하지 말 것 — per_unit_tier_enabled 가 안 실리면
            //    차등정산(tier) 담당자의 정산액이 10만 고정으로 계산돼 합계가 통째로 틀린다(실측 20,430,000→100,000).
            // 🚨 **잔금·회수이력을 같이 싣는다.** 아래 `sum('actual_payout')` 는 행마다
            //    총마진 → 판매금원화 → 정산환율 → **미수** 를 타고, 미수가 그 둘을 읽는다.
            //    안 실으면 행마다 2쿼리가 붙는다 — 실측(ssancarerp 3,815건) 7,837쿼리 / 19.3초.
            //    싣고 나면 209쿼리 / 7.9초.
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->heldOnly, fn ($q) => $q->payoutHeldByUnpaid())
            ->when($this->monthFilter, $this->monthScope())
            ->when($this->dateFrom, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '>=', $this->dateFrom)
            ))
            ->when($this->dateTo, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '<=', $this->dateTo)
            ))
            ->get();

        // 미반영 매입취소 손실 (jin 2026-08-06) — 실무자가 정산관리만 보다가 월배치 손실 요약을 놓쳐서 추가.
        //   ⚠️ 표시 전용. 실제 차감은 「월배치 지급」 조정 1곳에서만 — 여기 합계에 더하면 이중 청구.
        $cancelLoss = Vehicle::unsettledCancelLossBySalesman();

        return $all->groupBy('salesman_id')->map(function ($group, $salesmanId) use ($cancelLoss) {
            $first = $group->first();
            $loss = $cancelLoss[(int) $salesmanId] ?? null;

            return [
                'salesman_id' => $salesmanId,
                'salesman_name' => $first->salesman?->name ?? __('settlement.summary_unassigned'),
                'count' => $group->count(),
                'total_margin_sum' => (int) $group->sum('display_margin'),   // karaba=영업이익 / 그 외=총마진
                'settlement_amount_sum' => (int) $group->sum('settlement_amount'),
                'actual_payout_sum' => (int) $group->sum('actual_payout'),
                // 미청산 이월 — Salesman accessor(단일 출처). 필터 무관 현재 잔액. 재무 사각지대 보완.
                'unconsumed_carryover' => (int) ($first->salesman?->unconsumed_carryover ?? 0),
                // 미반영 매입취소 손실 — 필터 무관 현재 잔액. 합계에는 미포함(월배치에서 차감).
                'cancel_loss' => (int) ($loss['sum'] ?? 0),
                'cancel_loss_plates' => $loss['plates'] ?? [],
            ];
        })->sortByDesc('actual_payout_sum')->values()->toArray();
    }

    /**
     * **다른 달에 남은 확정 대기** 건수 (jin 2026-08-28, 월 기본값과 세트).
     *
     * 🚨 월 기본값을 넣자마자 「숫자는 보이는데 행이 없다」가 생길 자리가 하나 열렸다 —
     *    `erp_settlement_confirm_wait` 알림톡은 **월 스코프가 없어 전체 pending 을 센다**.
     *    재무가 「확정 대기 40건」 카톡을 받고 들어와 31건만 보면 그게 곧 혼란이다.
     *    실측(2026-08-28) heymanerp 2026-06 2건 · 2026-07 7건 — **실재한다**.
     * 🧭 그래서 숨기지 않고 **다른 달에 몇 건 남았는지 화면이 스스로 말하게** 한다.
     *    (채권관리에서 완납 차를 완납 탭으로 데려간 것과 같은 처방)
     * ⚠️ 월 필터가 걸려 있을 때만 센다. `count()` 하나라 `wire:poll.30s` 에도 부담 없다.
     */
    #[Computed]
    public function pendingOutsideMonth(): int
    {
        if ($this->monthFilter === '') {
            return 0;
        }

        // 🧭 `whereNot(closure)` 로 뒤집지 않는다 — monthScope 는 OR 두 갈래(attributed_month /
        //    COALESCE 앵커)라 부정형이 헷갈리고, 실제로 0 이 나왔다. 전체에서 이 달을 빼는 게 명확하다.
        $base = fn () => Settlement::query()->whereIn('settlement_status', ['pending', 'calculating']);

        return max(0, $base()->count() - $base()->where($this->monthScope())->count());
    }

    /** 「다른 달 N건」 안내를 눌렀을 때 — 전 기간으로 푼다. */
    public function clearMonthFilter(): void
    {
        $this->monthFilter = '';
        $this->resetPage();
    }

    /** 담당자별 합계 펼치기/접기 — 접으면 다음 렌더부터 그 계산을 건너뛴다. */
    public function toggleSummaries(): void
    {
        $this->showSummaries = ! $this->showSummaries;
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
        if (strlen(SearchTerm::of($this->vehicleSearch)) < 2) {
            return collect();
        }

        return Vehicle::query()
            ->where('vehicle_number', 'like', SearchTerm::like($this->vehicleSearch))
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
        // 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × 정산환율 (면장 미포함)
        // 2026-08-06 (jin) — 정산환율 = 실효 입금환율(완납 외화) / 판매환율(그 외).
        //   ⚠️ karaba 이익율 정산은 아래에서 판매환율($rate)을 그대로 쓴다 — tier 공식이라 무관.
        $saleBase = (float) ($v->sale_price ?? 0)
            + (float) ($v->commission ?? 0)
            + (float) ($v->auto_loading ?? 0)
            - (float) ($v->tax_dc ?? 0);
        $rate = (float) ($v->exchange_rate ?? 0);
        $settleRate = $v->settlement_exchange_rate;
        $salesAmountKrw = (int) ($saleBase * $settleRate);

        $settlementSalesKrw = $salesAmountKrw - (int) ($v->cost_total ?? 0);

        // 판매마진 = 정산판매금원화 - (purchase_price + selling_fee)  ← 매입합계
        $purchaseTotal = (int) ($v->purchase_price ?? 0) + (int) ($v->selling_fee ?? 0);
        $salesMargin = $settlementSalesKrw - $purchaseTotal;

        $vatMargin = (int) (($v->purchase_price ?? 0) * 0.09);
        $totalMargin = (int) (($salesMargin + $vatMargin) * 0.9);   // × 0.9 = 부가세 10% 차감

        // karaba 이익율 정산 (Phase 3) — Settlement::karaba_* accessor 와 동일 공식 (미리보기 정합).
        //   판매가 = 차대금(sale_price) × 환율 (운임·부품 제외) / 영업이익 = 판매가 − (구매가 + 부대비용 − 매입세액).
        $isKaraba = \App\Models\Setting::isKaraba();
        $karabaSalesKrw = (int) round((float) ($v->sale_price ?? 0) * $rate);
        $karabaCosts = (int) ($v->cost_total ?? 0);
        $karabaVat = (int) ($v->purchase_vat_amount ?? 0);
        $operatingProfit = $karabaSalesKrw - ($purchaseTotal + $karabaCosts - $karabaVat);
        $profitRate = $karabaSalesKrw > 0 ? round($operatingProfit / $karabaSalesKrw * 100, 1) : null;

        // 정산액 — karaba=이익율 tier / 그 외=type 별 자동 분기 + NULL fallback default
        $settlementAmount = 0;
        if ($isKaraba) {
            // 프리랜서 = 이익률 비율 정산 / 사내직원 = 건당 — 모델 getSettlementAmountAttribute 와 같은 분기.
            // ⚠️ tier 구간(6/5%)을 여기 옮겨 적지 말 것. 갈리면 «미리보기와 실제 정산액이 다른» 형태가 된다.
            if ($this->settlement_type === 'ratio') {
                if ($operatingProfit > 0) {
                    $pct = ($this->settlement_ratio ?? null) !== null && (float) $this->settlement_ratio > 0
                        ? (float) $this->settlement_ratio
                        : Settlement::karabaTierPercent($profitRate);
                    $settlementAmount = $pct > 0 ? (int) (floor($operatingProfit * $pct / 100 / 10) * 10) : 0;
                }
            } else {
                $settlementAmount = ($this->per_unit_amount ?? null) !== null && (int) $this->per_unit_amount > 0
                    ? (int) $this->per_unit_amount
                    : Settlement::EMPLOYEE_PER_UNIT_DEFAULT;
            }
        } elseif ($this->settlement_type === 'ratio') {
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

        // 서류 발송비(우체국 EMS + DHL) — 타입 무관 전액 차감 (jin 2026-08-31).
        //   ⚠️ 여기 안 빼면 **미리보기와 실제 지급액이 갈린다** — 화면엔 X 인데 지급은 X−발송비.
        //   단일 출처는 Settlement::getActualPayoutAttribute 이고 이 블록은 그 미리보기다.
        $shippingFee = (int) ($v->shipping_fee_total ?? 0);

        $actualPayout = $settlementAmount - $documentFee - $shippingFee - (int) ($this->other_deduction ?? 0);

        // 2026-08-06 (jin) — 환차는 판매금원화의 환율로 **1차 정산에 이미 반영**된다.
        //   여기서 다시 더하면 이중계상. 아래 $exchangeDiff 는 실현 환차 총액 **표시 전용**이다.
        // Settlement::getActualPayoutAttribute 와 동일 정책 — 편집 패널 미리보기 정합.
        $exchangeDiff = 0;
        $carryoverIn = 0;
        $carryoverOut = 0;
        if ($this->editingId) {
            $settlement = Settlement::find($this->editingId);
            if ($settlement) {
                if ($settlement->secondary_status === 'closed'
                    && $settlement->exchange_difference_krw !== null) {
                    $exchangeDiff = (int) $settlement->exchange_difference_krw;
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
            'documentFee', 'shippingFee', 'actualPayout', 'exchangeDiff',
            'carryoverIn', 'carryoverOut',
            // karaba 이익율 정산 표시용 (Phase 3)
            'isKaraba', 'operatingProfit', 'profitRate', 'karabaSalesKrw', 'purchaseTotal', 'karabaCosts', 'karabaVat'
        );
    }

    /**
     * 회의확장씬 #6+7 보강 (2026-05-23) — 정산 KRW 명세 (1차/입금/2차/환차).
     *
     * 사용자 명세: "1차정산·2차정산·환차익 계산 로직 그대로 화면에 나와야".
     *
     * 흐름:
     *   - 판매환율 기준 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × vehicle.exchange_rate
     *     ⚠️ 2026-08-06 (jin) 개편 후 이 값은 **환차 비교용 기준선**이다. 실제 1차 정산 판매금원화는
     *        정산환율(실효 입금환율)로 계산된다 — marginData['salesAmountKrw'] 를 볼 것.
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

        // 차량이 정해져야 이익률이 나온다 — 여기서 비율 자동값을 채운다(차가 바뀌면 다시).
        $this->fillKarabaRatio(force: true);
    }

    /**
     * karaba — 비율칸에 **이익률 자동값**을 채운다 (jin 2026-08-21).
     *
     * 「자동으로 기입되고, 고치면 고친 값으로 계산된다」가 요구사항이라 **읽기 전용이 아니다**.
     * ⚠️ 그래서 **비어 있을 때만** 채운다 — 사람이 넣은 값을 덮으면 조정이 매번 되돌아간다.
     * ⚠️ 차량을 바꾸면 이익률이 달라지므로 그때는 다시 계산해 넣는다(선택 직후라 사람 입력이 없다).
     */
    private function fillKarabaRatio(bool $force = false): void
    {
        if (! \App\Models\Setting::isKaraba() || $this->settlement_type !== 'ratio') {
            return;
        }
        if (! $force && ($this->settlement_ratio ?? null) !== null && (float) $this->settlement_ratio > 0) {
            return;
        }

        $rate = $this->marginData['profitRate'] ?? null;
        $pct = Settlement::karabaTierPercent($rate === null ? null : (float) $rate);
        if ($pct > 0) {
            $this->settlement_ratio = $pct;
        }
    }

    /**
     * 저장할 비율 — karaba 에서 **자동값 그대로면 `null` 로 남긴다** (jin 2026-08-21).
     *
     * 🚨 **자동값을 숫자로 굳히면 그 순간 tier 가 얼어붙는다.** karaba 의 2차 정산은
     *    **비용 보정**이 본업이라 영업이익이 자주 움직이는데, 굳은 숫자가 이기면 이익률이
     *    내려가도 옛 요율로 계산된다. 실측: 이익률 22.8%(20%) 상태에서 메모만 고치고 저장 →
     *    비용 보정으로 4.8%(10%) 가 됐는데도 **20% 로 남아 정산액이 2배**였다.
     *    화면을 열고 저장만 해도 그렇게 되므로 «사용자가 안 건드렸는데» 틀어진다.
     *
     * 그래서 **사람이 실제로 고친 값만** 저장한다 — 그래야 「손대지 않으면 tier 를 따라간다」가
     * 참이 된다. 고친 값은 그대로 저장돼 다음에 열어도 유지된다(fillKarabaRatio 가 안 덮는다).
     */
    private function resolvedRatioForSave(): ?float
    {
        $ratio = $this->settlement_ratio;
        if ($ratio === null || ! \App\Models\Setting::isKaraba()) {
            return $ratio;
        }

        $auto = Settlement::karabaTierPercent($this->marginData['profitRate'] ?? null);

        return $auto > 0 && (float) $ratio === (float) $auto ? null : $ratio;
    }

    /** 정산 방식을 바꾸면 karaba 비율 자동값을 다시 맞춘다(사내직원 → 프리랜서 전환 등). */
    public function updatedSettlementType(): void
    {
        unset($this->marginData);
        $this->fillKarabaRatio();
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

        // 옛 정산은 비율칸이 비어 있다(예전엔 안 쓰던 값) — 열 때 자동값을 채워 금액이 그대로 보이게 한다.
        $this->fillKarabaRatio();
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
            'settlement_ratio' => $this->settlement_type === 'ratio' ? $this->resolvedRatioForSave() : null,
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
    // ── 월배치 제출 확인 모달 (jin 2026-08-06) ────────────────────────────────
    //   조정을 **제출 전에** 확정한다. 구조상 조정은 배치에 종속(batch_id)이라 예전엔
    //   "제출 → 카톡 → 그제서야 조정" 순서였고, 승인자가 본 총액과 실제 지급액이 어긋났다.
    //   이제 여기서 차감을 정하고 넘기므로 카톡 총액이 정확하다. 월배치 화면엔 조정 입력이 없다.

    public bool $showSubmitModal = false;

    /** 차감할 매입취소 손실 담당자 — 체크박스 바인딩이라 **문자열** 배열이다(비교 시 캐스팅). */
    public array $submitLossChecked = [];

    /** 제출과 함께 만들 수동 조정 draft — [['salesman_id','amount','reason'], ...] */
    public array $submitAdjustments = [];

    public string $newAdjSalesmanId = '';

    public string $newAdjAmount = '';

    public string $newAdjReason = '';

    public function openSubmitModal(): void
    {
        if (! auth()->user()->canSubmitPayoutBatch()) {
            $this->dispatch('notify', message: __('settlement.batch.no_permission'), type: 'error');

            return;
        }
        if ($this->monthFilter === '') {
            $this->dispatch('notify', message: __('settlement.batch.select_month'), type: 'warning');

            return;
        }

        unset($this->submitPreview);
        $preview = $this->submitPreview;
        if ($preview['count'] === 0) {
            $this->dispatch('notify', message: __('settlement.batch.none_eligible'), type: 'warning');

            return;
        }

        // 기본 = 차감 가능한 손실 전부 체크. 이번 달 지급이 없는 담당자는 뺄 곳이 없어 제외.
        $this->submitLossChecked = collect($preview['losses'])
            ->where('payable', true)
            ->pluck('salesman_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->submitAdjustments = [];
        $this->newAdjSalesmanId = '';
        $this->newAdjAmount = '';
        $this->newAdjReason = '';
        $this->showSubmitModal = true;
    }

    public function closeSubmitModal(): void
    {
        $this->showSubmitModal = false;
    }

    /**
     * 제출 미리보기 — 대상 정산·매입취소 손실·수동 조정·최종 총액.
     * 대상 정산은 SettlementPayoutBatch::eligibleSettlementIds 단일 출처(실제 제출과 동일 목록).
     */
    #[Computed]
    public function submitPreview(): array
    {
        if ($this->monthFilter === '') {
            return ['count' => 0, 'payout_sum' => 0, 'losses' => []];
        }

        $ids = \App\Models\SettlementPayoutBatch::eligibleSettlementIds($this->monthFilter);
        $settlements = Settlement::whereIn('id', $ids)->with(['vehicle', 'salesman'])->get();   // salesman 컬럼 제한 금지 (tier)
        $payoutBySalesman = $settlements->groupBy('salesman_id')
            ->map(fn ($g) => (int) $g->sum('actual_payout'));

        $names = Salesman::whereIn('id', array_keys(Vehicle::unsettledCancelLossBySalesman()))
            ->pluck('name', 'id');

        $losses = [];
        foreach (Vehicle::unsettledCancelLossBySalesman() as $sid => $row) {
            $payable = ($payoutBySalesman[$sid] ?? 0) > 0;
            $losses[] = [
                'salesman_id' => $sid,
                'name' => $names[$sid] ?? '#'.$sid,
                'sum' => $row['sum'],
                'plates' => $row['plates'],
                'vehicle_ids' => $row['vehicle_ids'],
                'payable' => $payable,   // 이번 배치에 그 담당자 지급이 있어야 차감 의미가 있다
            ];
        }

        return [
            'count' => $settlements->count(),
            'payout_sum' => (int) $settlements->sum('actual_payout'),
            'losses' => $losses,
        ];
    }

    /** 모달 하단 합계 — 체크된 손실 + 수동 조정 반영. */
    #[Computed]
    public function submitTotals(): array
    {
        $preview = $this->submitPreview;
        $checked = array_map('intval', $this->submitLossChecked);
        $lossSum = collect($preview['losses'])
            ->whereIn('salesman_id', $checked)
            ->sum('sum');
        $adjSum = collect($this->submitAdjustments)->sum('amount');

        return [
            'loss_sum' => (int) $lossSum,
            'adj_sum' => (int) $adjSum,
            'final' => max(0, $preview['payout_sum'] - (int) $lossSum + (int) $adjSum),
        ];
    }

    public function addSubmitAdjustment(): void
    {
        $amount = (int) preg_replace('/[^\-0-9]/', '', $this->newAdjAmount);
        if ($this->newAdjSalesmanId === '' || $amount === 0 || trim($this->newAdjReason) === '') {
            $this->dispatch('notify', message: __('settlement.batch.adjust_invalid'), type: 'warning');

            return;
        }
        $this->submitAdjustments[] = [
            'salesman_id' => (int) $this->newAdjSalesmanId,
            'amount' => $amount,
            'reason' => trim($this->newAdjReason),
        ];
        $this->newAdjSalesmanId = '';
        $this->newAdjAmount = '';
        $this->newAdjReason = '';
        unset($this->submitTotals);
    }

    public function removeSubmitAdjustment(int $idx): void
    {
        unset($this->submitAdjustments[$idx]);
        $this->submitAdjustments = array_values($this->submitAdjustments);
        unset($this->submitTotals);
    }

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

        // 체크된 매입취소 손실 → 담당자별 −조정. 차량 id 를 함께 박아둬야 최종 승인 시 도장이 찍힌다.
        $checked = array_map('intval', $this->submitLossChecked);
        $adjustments = [];
        foreach ($this->submitPreview['losses'] as $loss) {
            if (! in_array($loss['salesman_id'], $checked, true) || ! $loss['payable']) {
                continue;
            }
            $adjustments[] = [
                'salesman_id' => $loss['salesman_id'],
                'amount' => -1 * (int) $loss['sum'],
                'reason' => __('settlement.batch.cancel_loss_reason', ['plates' => implode(', ', $loss['plates'])]),
                'cancel_vehicle_ids' => $loss['vehicle_ids'],
            ];
        }
        foreach ($this->submitAdjustments as $a) {
            $adjustments[] = [
                'salesman_id' => (int) $a['salesman_id'],
                'amount' => (int) $a['amount'],
                'reason' => (string) $a['reason'],
            ];
        }

        try {
            $batch = \App\Models\SettlementPayoutBatch::submitForMonth(auth()->user(), $this->monthFilter, $adjustments);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }

        $this->showSubmitModal = false;
        unset($this->settlements, $this->salesmanSummaries, $this->submitPreview, $this->submitTotals);
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
     * jin 2026-07-09 — 정산 행 인라인 확정 (pending/calculating → confirmed).
     *   기존엔 편집 모달을 열어 상태를 바꿔야 했음. 모델 save 로 confirmed_at·H3 가드 그대로 통과.
     *   권한 = canConfirmFinance (admin / 업무관리자 / 재무 / 관리). attributed_month(완납월 고정)라 월 안 밀림.
     */
    public function confirmSettlement(int $id): void
    {
        abort_unless(auth()->user()?->canConfirmFinance(), 403);

        $settlement = Settlement::findOrFail($id);
        if (! in_array($settlement->settlement_status, ['pending', 'calculating'], true)) {
            $this->dispatch('notify', message: __('settlement.notify.confirm_not_pending'), type: 'warning');

            return;
        }

        try {
            $this->confirmOne($settlement);
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->settlements, $this->salesmanSummaries);
        $this->dispatch('notify', message: __('settlement.notify.confirmed'), type: 'success');
    }

    /**
     * pending/calculating → confirmed 단건 처리 (인라인·일괄 공용).
     * confirmed_at 세팅 전 attributed_month(귀속월) 가 비었으면 현재 버킷으로 고정 —
     * 백필 안 된 레거시 정산이 확정 시점(now) 앵커로 밀려 다른 월로 새는 것을 방지 (서버 데이터 상태 무관).
     */
    private function confirmOne(Settlement $s): void
    {
        if ($s->attributed_month === null) {
            $ym = \App\Support\SettlementCkBatch::payrollMonthOf($s->confirmed_at ?? $s->created_at);
            $s->attributed_month = $ym.'-01';
        }
        $s->settlement_status = 'confirmed';
        if (! $s->confirmed_at) {
            $s->confirmed_at = now();
        }
        $s->save();
    }

    /**
     * jin 2026-07-09 — 선택 귀속월의 미확정(pending/calculating) 정산 일괄 확정.
     *   "다 정확하면" 한 번에 confirmed 로. 각 행을 개별 save 로 처리 → confirmed_at·H3 정상.
     *   ⚠ bulk update(whereIn->update) 금지 — 모델 이벤트 안 떠서 confirmed_at 누락 (SKILLS §2).
     *   현재 화면 필터(status/salesman/held/month)와 동일 스코프 → 보이는 대기 정산만 확정.
     */
    public function confirmMonth(): void
    {
        abort_unless(auth()->user()?->canConfirmFinance(), 403);

        if ($this->monthFilter === '') {
            $this->dispatch('notify', message: __('settlement.batch.select_month'), type: 'warning');

            return;
        }

        $targets = Settlement::query()
            ->whereIn('settlement_status', ['pending', 'calculating'])
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->heldOnly, fn ($q) => $q->payoutHeldByUnpaid())
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->monthFilter, $this->monthScope())
            ->get();

        if ($targets->isEmpty()) {
            $this->dispatch('notify', message: __('settlement.batch.confirm_none'), type: 'warning');

            return;
        }

        $ok = 0;
        $fail = 0;
        foreach ($targets as $s) {
            try {
                $this->confirmOne($s);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
            }
        }

        unset($this->settlements, $this->salesmanSummaries);
        if ($fail > 0) {
            $this->dispatch('notify', message: __('settlement.batch.confirm_partial', ['ok' => $ok, 'fail' => $fail]), type: 'warning');
        } else {
            $this->dispatch('notify', message: __('settlement.batch.confirmed', ['count' => $ok]), type: 'success');
        }
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
     *   완납게이트(sale_unpaid_amount ≤ 0) 하에서 기타 term 상쇄 → 순수 실현환차 보장.
     *   KRW 차량 또는 판매환율 없음 → 0/null (환차 없음).
     *
     * 🚨 2026-08-06 (jin) — **이 값은 더 이상 지급액에 가산되지 않는다.**
     *   환차는 판매금원화의 환율(Vehicle::settlement_exchange_rate)로 1차 정산에 이미 들어갔다.
     *   여기 기록은 실현 환차 총액의 **감사·참고용**이다. 2차 마감이 이월(carryover)로 넘기는 것은
     *   명세서 기입(탁송비·면허비 등 비용 9개) 변동분이다.
     *   ⚠️ 두 숫자는 크기가 다르다 — 여기 환차는 총판매가(운임비 포함) 기준 총액이고,
     *      1차에 반영된 몫은 정산 base(운임비 제외) × 0.9 × 비율 만큼이다.
     */
    /**
     * 정산 락 개편 (jin 2026-07-24) — 마감(closed)된 정산의 회계 재조정 진입.
     * 잠금 원인이 2차 정산 마감이므로 잠금 해제도 정산 화면에서 시작한다.
     * 사유 입력 → 차량 잠금 토큰 발급 → 해당 차량 편집 패널로 이동해 실제 수정.
     */
    public function openReadjustModal(int $settlementId): void
    {
        $settlement = Settlement::with('vehicle')->findOrFail($settlementId);
        $vehicle = $settlement->vehicle;
        if (! $vehicle || ! auth()->user()?->canUnlockLedger($vehicle)) {
            $this->dispatch('notify', message: __('settlement.readjust.no_perm'), type: 'error');

            return;
        }
        $this->readjustVehicleId = $vehicle->id;
        $this->readjustReason = '';
        $this->showReadjustModal = true;
    }

    public function closeReadjustModal(): void
    {
        $this->showReadjustModal = false;
        $this->readjustReason = '';
        $this->readjustVehicleId = null;
    }

    public function submitReadjust()
    {
        $this->validate(
            ['readjustReason' => ['required', 'string', 'min:10']],
            ['readjustReason.required' => __('settlement.readjust.reason_required'),
                'readjustReason.min' => __('settlement.readjust.reason_min')]
        );

        $vehicle = Vehicle::find($this->readjustVehicleId);
        if (! $vehicle || ! auth()->user()?->canUnlockLedger($vehicle)) {
            $this->dispatch('notify', message: __('settlement.readjust.no_perm'), type: 'error');

            return;
        }

        try {
            app(\App\Services\VehicleLedgerUnlockService::class)->unlock(
                $vehicle, auth()->user(), $this->readjustReason
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('settlement.readjust.failed', ['error' => $e->getMessage()]), type: 'error');

            return;
        }

        // 토큰 발급됨 → 차량 편집 패널로 이동해 회계필드 수정.
        return redirect()->route('erp.vehicles.index', ['openVehicle' => $vehicle->id]);
    }

    /** 2차 마감 권한 — 인라인 단건·일괄이 같은 조건을 본다. */
    private function canCloseSecondary(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || in_array($user?->role, ['재무', '관리'], true));
    }

    /**
     * 2차 마감을 막는 사유 — 단건·일괄 미리보기·일괄 실행의 **단일 출처** (jin 2026-08-26).
     *   null = 마감 가능 / 문자열 = 번역 키.
     * ⚠️ 미리보기와 실행이 각자 조건을 들면 「목록엔 마감된다고 떴는데 안 닫히는」 행이 생긴다.
     *
     * - 완납 게이트 (2026-07-06 재피벗 #3): 외화는 원금 완납(sale_unpaid_amount ≤ 0) 후에만 마감.
     *   미완납으로 마감하면 Σ잔금외화 < 총판매가외화 라 원금 미수가 "환차손"으로 둔갑한다.
     *   KRW 는 환차 개념이 없어 게이트 제외 (SKILLS §13, [[project_settlement_v2_groupware_design]]).
     * - 환율: 판매환율이 0/null 이면 환차 계산 불가.
     */
    private function secondaryCloseBlocker(Settlement $settlement): ?string
    {
        if ($settlement->secondary_status !== 'pending') {
            return 'settlement.notify.close_not_pending';
        }

        $vehicle = $settlement->vehicle;
        if ($vehicle && $vehicle->currency !== 'KRW' && $vehicle->sale_unpaid_amount > 0) {
            return 'settlement.notify.close_needs_full_payment';
        }

        [$exchangeDiff] = $this->calculateExchangeDifference($settlement);
        if ($exchangeDiff === null) {
            return 'settlement.notify.close_needs_rate';
        }

        return null;
    }

    public function closeSecondarySettlement(int $id): void
    {
        abort_unless($this->canCloseSecondary(), 403, __('settlement.forbidden_close'));

        $settlement = Settlement::findOrFail($id);
        $blocker = $this->secondaryCloseBlocker($settlement);
        if ($blocker !== null) {
            $type = $blocker === 'settlement.notify.close_not_pending' ? 'warning' : 'error';
            $this->dispatch('notify', message: __($blocker), type: $type);

            return;
        }

        [$exchangeDiff, $carryoverOut] = $this->closeOne($settlement);

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
     * 2차 마감 단건 실행 (인라인·일괄 공용) — 가드는 **호출 전에** secondaryCloseBlocker 로 통과시킬 것.
     *
     * @return array{0: float|null, 1: int}  [환차 KRW, 이월 KRW]
     */
    private function closeOne(Settlement $settlement): array
    {
        // 환차 계산 (2026-07-06 재피벗) — 실입금KRW − baseline(총판매가×판매환율).
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

        // 새회의 #8 보강 (2026-05-23) — 캐리오버 계산.
        // carryover_out_krw = closed actual_payout (cost·환차 모두 반영) - paid snapshot actual_payout
        // 다음 영업담당자 정산 creating 훅이 자동 흡수.
        $paidSnapshotPayout = (int) ($settlement->confirmed_snapshot['actual_payout'] ?? 0);
        $closedPayout = $settlement->fresh()->actual_payout;
        $carryoverOut = $closedPayout - $paidSnapshotPayout;
        if ($carryoverOut !== 0) {
            $settlement->update(['carryover_out_krw' => $carryoverOut]);
        }

        return [$exchangeDiff, $carryoverOut];
    }

    // -- 2차 정산 완료 일괄 (jin 2026-08-26) ---------------------------------
    //   마감은 되돌릴 수 없다(secondary_status='closed' = 회계 락 단일 트리거, 해제는 차량별
    //   [잠금 해제] + 관리 승인). 그래서 wire:confirm 한 줄이 아니라 **무엇이 닫히고 무엇이
    //   왜 빠지는지** 보여주는 미리보기 모달을 거친다(월배치 제출 모달과 같은 형태).
    //   ⚠️ 건너뛴 건을 카운터에 뭉뚱그리지 말 것 — 정작 봐야 할 차(미완납·환율누락)가 숫자에 묻힌다.

    public bool $showCloseSecondaryModal = false;

    /** 일괄 대상 -- 2차 대기 + **현재 화면 필터**. 목록에 보이는 것만 닫힌다. */
    private function secondaryCloseTargets()
    {
        return Settlement::query()
            ->where('secondary_status', 'pending')
            // ⚠️ salesman 컬럼 제한 금지 (tier) — actual_payout 이 통째로 틀어진다.
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])
            ->when(SearchTerm::of($this->search), fn ($q) => $q->searchTerm($this->search))
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->heldOnly, fn ($q) => $q->payoutHeldByUnpaid())
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->monthFilter, $this->monthScope())
            ->orderBy('id')
            ->get();
    }

    /**
     * 이 달 2차 대기 건수 — **버튼 노출 판정용** (jin 2026-08-26).
     *   0 이면 버튼을 아예 안 띄운다. "이번 달에도 눌러야 하나" 하는 헷갈림을 없앤다.
     *
     * 🧭 jin 제안은 「일괄 확정을 1회라도 누른 이전 달에만」이었는데, **누른 기록이 없다**
     *   (confirmMonth 는 이벤트를 안 남기고, 한 건씩 인라인 확정해도 결과가 같다).
     *   그럴 필요도 없다 — secondary_status='pending' 은 **이미 지급(paid)된 정산**에만 붙으므로
     *   그 달이 확정·배치·승인을 이미 거쳤다는 뜻이다. 조건이 데이터에 이미 들어 있다.
     *   ⇒ 달력 규칙(귀속월/지급월 경계) 없이 건수만 보면 된다. 실측: 06월 27 · 07월 27 · 당월 0.
     *
     * ⚠️ 이 화면은 wire:poll.30s 라 매 30초 재평가된다 — accessor 를 도는 closeSecondaryPreview 를
     *   여기서 부르지 말 것(모달 안에서만 쓴다). 여긴 순수 count 여야 한다.
     */
    #[Computed]
    public function secondaryPendingCount(): int
    {
        if (! $this->canCloseSecondary() || $this->monthFilter === '') {
            return 0;
        }

        return Settlement::query()
            ->where('secondary_status', 'pending')
            ->when(SearchTerm::of($this->search), fn ($q) => $q->searchTerm($this->search))
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->heldOnly, fn ($q) => $q->payoutHeldByUnpaid())
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->monthFilter, $this->monthScope())
            ->count();
    }

    /** 미리보기 -- 닫을 것 / 건너뛸 것(사유 포함). 실행과 같은 secondaryCloseBlocker 를 쓴다. */
    #[Computed]
    public function closeSecondaryPreview(): array
    {
        $ready = [];
        $skipped = [];
        foreach ($this->secondaryCloseTargets() as $s) {
            $row = [
                'id' => $s->id,
                'plate' => $s->vehicle?->vehicle_number ?? '-',
                'salesman' => $s->salesman?->name ?? '-',
                'payout' => (int) $s->actual_payout,
            ];
            $blocker = $this->secondaryCloseBlocker($s);
            if ($blocker === null) {
                $ready[] = $row;
            } else {
                $row['reason'] = __($blocker);
                $skipped[] = $row;
            }
        }

        return ['ready' => $ready, 'skipped' => $skipped];
    }

    public function openCloseSecondaryModal(): void
    {
        if (! $this->canCloseSecondary()) {
            $this->dispatch('notify', message: __('settlement.forbidden_close'), type: 'error');

            return;
        }
        if ($this->monthFilter === '') {
            $this->dispatch('notify', message: __('settlement.batch.select_month'), type: 'warning');

            return;
        }

        unset($this->closeSecondaryPreview);
        $preview = $this->closeSecondaryPreview;
        if (empty($preview['ready']) && empty($preview['skipped'])) {
            $this->dispatch('notify', message: __('settlement.batch.close_none'), type: 'warning');

            return;
        }

        $this->showCloseSecondaryModal = true;
    }

    public function closeCloseSecondaryModal(): void
    {
        $this->showCloseSecondaryModal = false;
    }

    public function closeSecondaryMonth(): void
    {
        abort_unless($this->canCloseSecondary(), 403, __('settlement.forbidden_close'));

        $ok = 0;
        $skip = 0;
        $fail = 0;
        foreach ($this->secondaryCloseTargets() as $s) {
            if ($this->secondaryCloseBlocker($s) !== null) {
                $skip++;

                continue;
            }
            try {
                $this->closeOne($s);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                report($e);
            }
        }

        $this->showCloseSecondaryModal = false;
        unset($this->settlements, $this->salesmanSummaries, $this->closeSecondaryPreview);

        if ($ok === 0) {
            $this->dispatch('notify', message: __('settlement.batch.close_none_done', ['skip' => $skip + $fail]), type: 'warning');

            return;
        }
        $this->dispatch(
            'notify',
            message: __('settlement.batch.close_done', ['ok' => $ok, 'skip' => $skip + $fail]),
            type: ($skip + $fail) > 0 ? 'warning' : 'success'
        );
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
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('settlement.search_ph') }}"
           class="input-filter w-36" />
    <select wire:model="statusFilter" class="input-filter">
        <option value="">{{ __('settlement.filter_all_status') }}</option>
        <option value="pending">{{ __('settlement.status.pending') }}</option>
        <option value="calculating">{{ __('settlement.status.calculating') }}</option>
        <option value="confirmed">{{ __('settlement.status.confirmed') }}</option>
        <option value="paid">{{ __('settlement.status.paid') }}</option>
    </select>
    {{-- 지급 게이트 (jin 2026-07-08) — 미수로 지급보류된 확정 정산만 --}}
    <button type="button" wire:click="toggleHeld"
            class="rounded border px-2.5 py-1.5 text-sm font-medium {{ $heldOnly ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50' }}">
        {{ __('settlement.held.filter') }}
    </button>
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
    {{-- 다른 달에 남은 확정 대기 — 월 기본값이 가린 것을 화면이 스스로 말한다(위 computed 참조). --}}
    @if($this->pendingOutsideMonth > 0)
    <button type="button" wire:click="clearMonthFilter"
            class="badge badge-amber hover:underline"
            title="{{ __('settlement.pending_outside_month_title') }}">
        {{ __('settlement.pending_outside_month', ['count' => $this->pendingOutsideMonth]) }}
    </button>
    @endif
    <input wire:model="dateFrom" type="date" class="input-filter" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model="dateTo" type="date" class="input-filter" />
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
    {{-- 엑셀 내려받기 (jin 2026-08-03) — 화면 필터 그대로. 영업담당자별 시트로 나뉘고 첫 시트가 요약.
         "전체(필터 무시)" 범위는 일부러 안 만든다 — 화면에서 본 것과 다른 게 나오면 대조가 무의미(SKILLS §14). --}}
    <button type="button" title="{{ __('settlement.export_hint') }}"
            x-data
            @click="(() => {
                const p = new URLSearchParams();
                if ($wire.search) p.set('q', $wire.search);
                if ($wire.statusFilter) p.set('status', $wire.statusFilter);
                if ($wire.heldOnly) p.set('held', '1');
                if ($wire.salesmanFilter) p.set('salesmanId', $wire.salesmanFilter);
                if ($wire.monthFilter) p.set('month', $wire.monthFilter);
                if ($wire.dateFrom) p.set('dateFrom', $wire.dateFrom);
                if ($wire.dateTo) p.set('dateTo', $wire.dateTo);
                window.location.href = '{{ route('erp.settlements.export') }}?' + p.toString();
            })()"
            class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
        {{ __('settlement.export_btn') }}
    </button>
    {{-- jin 2026-07-09 — 선택 월 미확정 정산 일괄 확정 (확정 → 이후 월배치 제출). --}}
    @if(auth()->user()->canConfirmFinance() && $monthFilter !== '')
    <button wire:click="confirmMonth" wire:confirm="{{ __('settlement.batch.confirm_month_prompt', ['month' => $monthFilter]) }}"
            class="rounded-md border border-emerald-500 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50">
        {{ __('settlement.batch.confirm_month') }}</button>
    @endif
    {{-- jin 2026-08-26 — 2차 정산 완료 일괄. 마감은 되돌릴 수 없어 미리보기 모달을 반드시 거친다.
         대상(2차 대기)이 0건인 달엔 아예 안 뜬다 — 권한·월 판정까지 secondaryPendingCount 안에 있다. --}}
    @if($this->secondaryPendingCount > 0)
    <button wire:click="openCloseSecondaryModal"
            class="rounded-md border border-violet-500 px-3 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50">
        {{ __('settlement.batch.close_secondary', ['count' => $this->secondaryPendingCount]) }}</button>
    @endif
    @if(auth()->user()->canSubmitPayoutBatch() && $monthFilter !== '')
    {{-- 승인큐 이동링크 제거 (2026-07-07 jin) — 사이드바 정산그룹 「승인큐」 메뉴로 접근. 여기선 헷갈림만 유발. --}}
    {{-- jin 2026-08-06 — wire:confirm 한 줄에서 확인 모달로. 조정(매입취소 손실 차감·수동)을
         제출 전에 확정해야 카톡 총액과 실제 지급액이 어긋나지 않는다. --}}
    <button wire:click="openSubmitModal" class="btn-primary text-xs">{{ __('settlement.batch.submit') }}</button>
    @endif
</div>

{{-- ── 2차 정산 완료 일괄 확인 모달 (jin 2026-08-26) ──────────────────────
     닫힐 것과 건너뛸 것을 **사유까지** 보여준다. 카운터로 뭉뚱그리면 정작 손봐야 할
     차(미완납·환율누락)가 숫자에 묻힌다. --}}
@if($showCloseSecondaryModal)
@php $cp = $this->closeSecondaryPreview; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3" wire:key="close-secondary-modal">
    <div class="card max-h-[90vh] w-full max-w-2xl overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-800">{{ __('settlement.batch.close_modal_title', ['month' => $monthFilter]) }}</h3>
            <button type="button" wire:click="closeCloseSecondaryModal" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {{ __('settlement.batch.close_warning') }}
        </div>

        {{-- 닫을 것 --}}
        <div class="mt-3">
            <div class="section-header"><span class="section-dot bg-violet-500"></span>
                <span class="section-title">{{ __('settlement.batch.close_ready', ['count' => count($cp['ready'])]) }}</span></div>
            @if(count($cp['ready']) === 0)
            <div class="mt-1 px-1.5 py-1 text-xs text-gray-500">{{ __('settlement.batch.close_ready_none') }}</div>
            @else
            <div class="mt-1 max-h-56 overflow-y-auto">
                <table class="w-full text-xs">
                    <tbody>
                    @foreach($cp['ready'] as $row)
                    <tr wire:key="cs-ready-{{ $row['id'] }}" class="border-b border-gray-100">
                        <td class="py-1 font-medium text-gray-800">{{ $row['plate'] }}</td>
                        <td class="py-1 text-gray-500">{{ $row['salesman'] }}</td>
                        <td class="py-1 text-right font-mono text-gray-700">&#8361;{{ number_format($row['payout']) }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        {{-- 건너뛸 것 --}}
        @if(count($cp['skipped']) > 0)
        <div class="mt-3">
            <div class="section-header"><span class="section-dot bg-rose-500"></span>
                <span class="section-title">{{ __('settlement.batch.close_skipped', ['count' => count($cp['skipped'])]) }}</span></div>
            <div class="mt-1 max-h-56 overflow-y-auto">
                @foreach($cp['skipped'] as $row)
                <div wire:key="cs-skip-{{ $row['id'] }}" class="border-b border-gray-100 py-1 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-800">{{ $row['plate'] }}</span>
                        <span class="text-gray-500">{{ $row['salesman'] }}</span>
                    </div>
                    <div class="mt-0.5 text-rose-600">{{ $row['reason'] }}</div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" wire:click="closeCloseSecondaryModal"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                {{ __('settlement.batch.close_cancel') }}</button>
            <button type="button" wire:click="closeSecondaryMonth" @disabled(count($cp['ready']) === 0)
                    class="rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-40">
                {{ __('settlement.batch.close_apply', ['count' => count($cp['ready'])]) }}</button>
        </div>
    </div>
</div>
@endif

{{-- ── 월배치 제출 확인 모달 (jin 2026-08-06) ─────────────────────────────── --}}
@if($showSubmitModal)
@php $pv = $this->submitPreview; $tt = $this->submitTotals; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3" wire:key="submit-modal">
    <div class="card max-h-[90vh] w-full max-w-2xl overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-800">{{ __('settlement.batch.modal_title', ['month' => $monthFilter]) }}</h3>
            <button type="button" wire:click="closeSubmitModal" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        {{-- 대상 정산 --}}
        <div class="mt-3 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-xs">
            <span class="text-gray-600">{{ __('settlement.batch.modal_target', ['count' => $pv['count']]) }}</span>
            <span class="font-mono font-semibold text-gray-800">₩{{ number_format($pv['payout_sum']) }}</span>
        </div>

        {{-- 매입취소 손실 차감 --}}
        @if(!empty($pv['losses']))
        <div class="mt-3">
            <div class="section-header"><span class="section-dot bg-rose-500"></span>
                <span class="section-title">{{ __('settlement.batch.modal_cancel_loss') }}</span></div>
            <div class="mt-1 space-y-1">
                @foreach($pv['losses'] as $loss)
                <label wire:key="loss-{{ $loss['salesman_id'] }}"
                       class="flex items-start gap-2 rounded px-1.5 py-1 text-xs {{ $loss['payable'] ? 'hover:bg-rose-50' : 'opacity-60' }}">
                    <input type="checkbox" wire:model.live="submitLossChecked" value="{{ $loss['salesman_id'] }}"
                           @disabled(! $loss['payable']) class="mt-0.5" />
                    <span class="flex-1">
                        <span class="font-medium text-gray-700">{{ $loss['name'] }}</span>
                        <span class="ml-1 text-gray-400">{{ implode(', ', $loss['plates']) }}</span>
                        @unless($loss['payable'])
                            <span class="ml-1 text-[10px] text-amber-600">{{ __('settlement.batch.modal_no_payout') }}</span>
                        @endunless
                    </span>
                    <span class="font-mono text-rose-600">−{{ number_format($loss['sum']) }}</span>
                </label>
                @endforeach
            </div>
        </div>
        @endif

        {{-- 수동 조정 --}}
        <div class="mt-3">
            <div class="section-header"><span class="section-dot bg-indigo-500"></span>
                <span class="section-title">{{ __('settlement.batch.modal_adjust') }}</span></div>
            @foreach($submitAdjustments as $i => $adj)
            <div wire:key="adj-{{ $i }}" class="flex items-center gap-2 px-1.5 py-1 text-xs">
                <span class="font-medium text-gray-700">{{ $this->salesmen->firstWhere('id', $adj['salesman_id'])?->name }}</span>
                <span class="flex-1 text-gray-400">{{ $adj['reason'] }}</span>
                <span class="font-mono {{ $adj['amount'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                    {{ $adj['amount'] < 0 ? '−' : '+' }}{{ number_format(abs($adj['amount'])) }}</span>
                <button type="button" wire:click="removeSubmitAdjustment({{ $i }})" class="text-gray-400 hover:text-red-500">&times;</button>
            </div>
            @endforeach
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <select wire:model="newAdjSalesmanId" class="input-base w-32 text-xs">
                    <option value="">{{ __('settlement.batch.adjust_salesman') }}</option>
                    @foreach($this->salesmen as $sm)
                        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="newAdjAmount" data-money placeholder="{{ __('settlement.batch.adjust_amount') }}" class="input-base w-28 text-xs" />
                <input type="text" wire:model="newAdjReason" placeholder="{{ __('settlement.batch.adjust_reason') }}" class="input-base flex-1 text-xs" />
                <button type="button" wire:click="addSubmitAdjustment"
                        class="rounded bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">{{ __('settlement.batch.adjust_add') }}</button>
            </div>
            {{-- 사유가 밖으로 나간다는 것을 쓰는 자리에서 알린다 (2026-08-31 board 월배치 미러).
                 위험한 건 API 가 아니라 「쓰는 사람이 외부 노출을 모르는 것」이다 — SKILLS §8 #60. --}}
            <p class="mt-1 text-[11px] text-amber-600">{{ __('settlement.batch.adjust_reason_visible') }}</p>
        </div>

        {{-- 최종 총액 --}}
        <div class="mt-3 border-t border-gray-200 pt-2">
            <div class="flex items-center justify-between text-sm">
                <span class="font-semibold text-gray-700">{{ __('settlement.batch.modal_final') }}</span>
                <span class="font-mono text-base font-bold text-violet-700">₩{{ number_format($tt['final']) }}</span>
            </div>
            <p class="mt-1 text-[11px] text-gray-400">{{ __('settlement.batch.modal_hint') }}</p>
        </div>

        <div class="mt-3 flex items-center justify-end gap-2">
            <button type="button" wire:click="closeSubmitModal"
                    class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button type="button" wire:click="submitPayoutBatch" class="btn-primary text-xs">{{ __('settlement.batch.submit') }}</button>
        </div>
    </div>
</div>
@endif

{{-- 2026-05-20 #2 피드백 — 영업담당자별 합계 카드 (인원별 솔팅 + 합계). --}}
{{-- 클릭 시 해당 담당자 필터 토글. statusFilter / dateFrom/To 와 동일 컨텍스트. --}}
{{-- 🚨 **기본 접힘**이고 접혀 있으면 `$this->salesmanSummaries` 를 안 부른다 —
     그 계산이 전 정산을 순회해서 3,815건이면 7.9초다(그리고 wire:poll.30s 가 반복한다). --}}
<div class="mt-3">
    <button type="button" wire:click="toggleSummaries"
            class="mb-2 flex items-center gap-2 text-xs text-gray-500 hover:text-violet-700">
        <span class="inline-block transition-transform {{ $showSummaries ? 'rotate-90' : '' }}">▸</span>
        <span>{{ __('settlement.summary_title') }}</span>
        {{-- 🧭 범위를 제목에 적는다 — 월 기본값이 생긴 뒤로 「이게 전 기간 합계」라는 오해가 가능해졌다. --}}
        <span class="pill-count">{{ $monthFilter !== '' ? $monthFilter : __('settlement.summary_scope_all') }}</span>
        <span class="text-gray-400">{{ $showSummaries ? __('settlement.summary_hint') : __('settlement.summary_collapsed_hint') }}</span>
    </button>

    @if($showSummaries)
        @php $isKaraba = \App\Models\Setting::isKaraba(); @endphp
        @if(!empty($this->salesmanSummaries))
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
            @foreach($this->salesmanSummaries as $summary)
            {{-- 접힌 카드 = 이름 + 실지급액. 자세히는 개별 펼치기(값은 이미 계산돼 있어 Alpine 으로 충분). --}}
            <div x-data="{ open: false }"
                 class="card {{ $salesmanFilter == $summary['salesman_id'] ? 'border-violet-400 bg-violet-50/40' : '' }}">
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="setSalesmanFilter({{ $summary['salesman_id'] ?? 0 }})"
                            class="min-w-0 flex-1 truncate text-left text-xs font-medium text-gray-700 hover:text-violet-700">
                        {{ $summary['salesman_name'] }}
                    </button>
                    <span class="shrink-0 font-mono text-xs font-semibold text-violet-700">{{ number_format($summary['actual_payout_sum']) }}</span>
                    <button type="button" @click="open = !open"
                            class="shrink-0 text-[11px] text-gray-400 hover:text-violet-700"
                            :aria-expanded="open" aria-label="{{ __('settlement.summary_detail_toggle') }}">
                        <span x-text="open ? '−' : '+'">+</span>
                    </button>
                </div>
                <div x-show="open" x-cloak class="mt-2 space-y-1 text-[11px]">
                    <div class="flex items-center justify-between text-gray-500">
                        <span>{{ __('settlement.summary_count_label') }}</span>
                        <span class="pill-count">{{ __('settlement.summary_count', ['count' => $summary['count']]) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-gray-500">
                        <span>{{ $isKaraba ? __('settlement.label_operating_profit') : __('settlement.summary_total_margin') }}</span>
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
                    {{-- 미반영 매입취소 손실 (jin 2026-08-06) — 표시 전용. 실제 차감은 「월배치 지급」 조정에서. --}}
                    @if(($summary['cancel_loss'] ?? 0) > 0)
                    <div class="flex items-center justify-between border-t border-gray-100 pt-1"
                         title="{{ __('settlement.summary_cancel_loss_hint', ['plates' => implode(', ', $summary['cancel_loss_plates'] ?? [])]) }}">
                        <span class="text-rose-600">{{ __('settlement.summary_cancel_loss') }}</span>
                        <span class="font-mono font-semibold text-rose-600">−{{ number_format($summary['cancel_loss']) }}</span>
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif
    @endif
</div>

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
                <th class="pb-2 pr-4 font-medium text-right">{{ \App\Models\Setting::isKaraba() ? __('settlement.label_operating_profit') : __('settlement.col.total_margin') }}</th>
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
                <td class="py-3 pr-4 text-right {{ $s->display_margin < 0 ? 'text-red-500' : 'text-gray-700' }}">
                    ₩{{ number_format($s->display_margin) }}
                </td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    ₩{{ number_format($s->settlement_amount) }}
                    {{-- 왜 이 금액인지 — 승계 바이어면 건당 5만 고정이라, 표시 없으면 재무가 이유를 못 찾는다 (jin 2026-08-04) --}}
                    @if($s->settlement_type === 'per_unit' && $s->isInheritedBuyerDeal())
                        <span class="badge badge-amber ml-1">{{ __('settlement.inherited_buyer') }}</span>
                    @endif
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
                    {{-- 지급 게이트 (jin 2026-07-08) — 미수 있어 월배치·지급에서 제외되는 확정 정산 표시 --}}
                    @if($s->isPayoutHeldByUnpaid())
                    <span class="badge badge-red ml-1" title="{{ __('settlement.held.tooltip', ['amount' => number_format($s->vehicle?->sale_unpaid_amount ?? 0)]) }}">{{ __('settlement.held.badge') }}</span>
                    @endif
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
                        {{-- jin 2026-07-09 — 행 인라인 확정 (pending/calculating → confirmed). 편집모달 안 열고 바로. --}}
                        @if(in_array($s->settlement_status, ['pending', 'calculating'], true) && auth()->user()->canConfirmFinance())
                        <button wire:click.stop="confirmSettlement({{ $s->id }})"
                                wire:confirm="{{ __('settlement.confirm_confirm') }}"
                                class="text-xs font-medium text-emerald-600 hover:text-emerald-800">{{ __('settlement.btn_confirm') }}</button>
                        @endif
                        {{-- Phase 2 (2026-07-07) — 개별 지급 승인요청 은퇴. [관리]/업무관리자가 '월배치 제출'로 진행.
                             (레거시 requestPayApproval 메서드/executeSettlementPay 는 기존 pending 처리용 존치, 대표만 실행) --}}
                        {{-- 회의확장씬 #8 (2026-05-22) — 2차 정산 완료 액션 ([재무]/[관리]/admin) --}}
                        @if($canCloseSecondary)
                        <button wire:click.stop="closeSecondarySettlement({{ $s->id }})"
                                wire:confirm="{{ __('settlement.confirm_close_secondary') }}"
                                class="text-xs text-violet-600 hover:text-violet-800">{{ __('settlement.btn_close_secondary') }}</button>
                        @endif
                        {{-- 정산 락 개편 (jin 2026-07-24) — 마감된 정산 회계 재조정(잠금 해제 → 차량 편집) --}}
                        @if($s->secondary_status === 'closed' && $s->vehicle && auth()->user()->canUnlockLedger($s->vehicle))
                        <button wire:click.stop="openReadjustModal({{ $s->id }})"
                                class="text-xs text-amber-600 hover:text-amber-800"
                                title="{{ __('settlement.readjust.title') }}">🔓 {{ __('settlement.btn_readjust') }}</button>
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
            <div>{{ \App\Models\Setting::isKaraba() ? __('settlement.label_operating_profit') : __('settlement.mobile_total_margin') }}: ₩{{ number_format($s->display_margin) }}</div>
            <div class="font-semibold text-gray-700">{{ __('settlement.mobile_actual') }}: ₩{{ number_format($s->actual_payout) }}</div>
        </div>
        @if(in_array($s->settlement_status, ['pending', 'calculating'], true) && auth()->user()->canConfirmFinance())
        <button wire:click.stop="confirmSettlement({{ $s->id }})"
                wire:confirm="{{ __('settlement.confirm_confirm') }}"
                class="mt-3 w-full rounded-md border border-emerald-500 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50">
            {{ __('settlement.btn_confirm') }}</button>
        @endif
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('settlement.empty') }}</div>
    @endforelse
</div>

{{-- 하단 여백(pb-28) — 우하단 고정 통관서류 알람 위젯과 페이지네이션 화살표가 겹쳐 클릭 방해되던 문제 해소 (2026-07-07 jin). --}}
{{-- 아래 여백은 공용 페이지네이션 뷰가 갖고 있다(vendor/pagination/tailwind) — 여기서 땜질하지 말 것. --}}
<div>{{ $this->settlements->links() }}</div>

</div>

{{-- 정산 락 개편 (jin 2026-07-24) — 마감(closed) 정산 회계 재조정(잠금 해제) 모달 --}}
@if($showReadjustModal)
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeReadjustModal">
    <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
        <h3 class="text-sm font-bold text-gray-800">🔓 {{ __('settlement.readjust.title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('settlement.readjust.desc') }}</p>
        <textarea wire:model="readjustReason" rows="4"
                  placeholder="{{ __('settlement.readjust.reason_ph') }}"
                  class="input-base mt-3 w-full text-sm"></textarea>
        @error('readjustReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2">
            <button wire:click="closeReadjustModal" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="submitReadjust" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">{{ __('settlement.readjust.submit_btn') }}</button>
        </div>
    </div>
</div>
@endif

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
            @if($this->marginData['isKaraba'] ?? false)
            {{-- karaba 이익율 정산 (Phase 3) — 영업이익 = 판매가 − (구매가 + 부대비용 − 매입세액VAT) --}}
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600"><span>{{ __('settlement.karaba.sales') }} <span class="text-xs text-gray-400">{{ __('settlement.karaba.sales_note') }}</span></span><span>₩{{ number_format($this->marginData['karabaSalesKrw']) }}</span></div>
                <div class="flex justify-between text-gray-600"><span>{{ __('settlement.karaba.purchase') }} <span class="text-xs text-gray-400">{{ __('settlement.karaba.purchase_note') }}</span></span><span>₩{{ number_format($this->marginData['purchaseTotal']) }}</span></div>
                <div class="flex justify-between text-gray-600"><span>{{ __('settlement.karaba.costs') }}</span><span>₩{{ number_format($this->marginData['karabaCosts']) }}</span></div>
                <div class="flex justify-between text-gray-600"><span>{{ __('settlement.karaba.vat') }}</span><span>₩{{ number_format($this->marginData['karabaVat']) }}</span></div>
                <hr class="border-gray-200" />
                <div class="flex justify-between font-semibold text-gray-800"><span>{{ __('settlement.karaba.operating') }} <span class="text-xs font-normal text-gray-400">{{ __('settlement.karaba.operating_note') }}</span></span><span class="{{ $this->marginData['operatingProfit'] < 0 ? 'text-red-600' : '' }}">₩{{ number_format($this->marginData['operatingProfit']) }}</span></div>
                <div class="flex justify-between text-gray-600"><span>{{ __('settlement.karaba.rate') }} <span class="text-xs text-gray-400">(≥6% 20% / 5~6% 15% / &lt;5% 10%)</span></span><span>{{ $this->marginData['profitRate'] !== null ? $this->marginData['profitRate'].'%' : '-' }}</span></div>
            </div>
            @else
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
            @endif
        </div>
        @endif

        {{-- 적용 비용 내역 (cost_total 분해) — read-only. 2차 정산에서 반영된 실측 비용을 투명화. --}}
        @if($this->selectedVehicle)
        @php
            $sv = $this->selectedVehicle;
            // 회사별 목록을 여기 옮겨 적지 말 것 — 갈리면 「표의 합계는 맞는데 줄을 더하면 안 맞는」
            //   화면이 된다. costBreakdown() 은 합이 반드시 cost_total 과 같다(SKILLS §8 #64).
            $costRows = $sv->costBreakdown();
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
                    <span>{{ \App\Models\Vehicle::costLabel($col) }}</span>
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
                {{-- karaba — 이익률로 정해지는 자동값을 알려준다. 칸은 그대로 고칠 수 있고,
                     고치면 그 값으로 정산액이 다시 계산된다(jin 2026-08-21). --}}
                @if(($this->marginData['isKaraba'] ?? false) && ($this->marginData['profitRate'] ?? null) !== null)
                    @php $autoPct = \App\Models\Settlement::karabaTierPercent((float) $this->marginData['profitRate']); @endphp
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('settlement.karaba_ratio_hint', [
                            'rate' => number_format((float) $this->marginData['profitRate'], 1),
                            'pct' => $autoPct,
                        ]) }}
                    </p>
                @endif
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
                        <span class="text-xs text-gray-400">{{ ($this->marginData['isKaraba'] ?? false)
                            ? __('settlement.karaba_ratio_formula', ['ratio' => ($settlement_ratio ?? null) !== null && (float) $settlement_ratio > 0 ? $settlement_ratio : \App\Models\Settlement::karabaTierPercent($this->marginData['profitRate'] ?? null)])
                            : __('settlement.result_ratio_formula', ['ratio' => ($settlement_ratio ?? null) !== null && (float) $settlement_ratio > 0 ? $settlement_ratio : 50]) }}</span>
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
            {{-- 발송비 (jin 2026-08-31) — 정산액에서 전액 차감. 줄이 없으면 실지급액이 안 맞아 보인다. --}}
            @if(($this->marginData['shippingFee'] ?? 0) > 0)
            <div class="flex justify-between text-gray-500">
                <span>{{ __('settlement.result_shipping_fee') }} <span class="text-xs text-gray-400">{{ __('settlement.result_shipping_fee_sub') }}</span></span>
                <span class="text-red-500">- ₩{{ number_format($this->marginData['shippingFee']) }}</span>
            </div>
            @endif
            <div class="flex justify-between text-gray-500">
                <span>{{ __('settlement.result_other_deduction') }}</span>
                <span class="text-red-500">- ₩{{ number_format((int) ($other_deduction ?? 0)) }}</span>
            </div>
            {{-- 2026-08-06 (jin) — 실현 환차 참고 라인. 1차 정산에 이미 반영돼 있어 여기서 재가산하지 않는다. --}}
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
