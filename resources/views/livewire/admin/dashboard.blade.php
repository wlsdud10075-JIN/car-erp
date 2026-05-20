<?php

use App\Models\Buyer;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

    // 큐 4 8-1 — 기준일 컬럼 전환. UI 라벨: 매입일/판매일/선적일/거래완료일.
    // 'completed'는 정식 거래완료일 컬럼이 없어 B/L 발행일(bl_issue_date)로 임시 매핑.
    // TODO: 추후 transaction_completed_at 컬럼 도입 시 매핑 변경.
    #[Url] public string $dateType = 'purchase';

    /** 기준일 코드 → DB 컬럼 매핑 (kpis·차트 공용) */
    private const DATE_COLUMN_MAP = [
        'purchase'  => 'purchase_date',
        'sale'      => 'sale_date',
        'shipping'  => 'shipping_date',
        'completed' => 'bl_issue_date',  // 임시 매핑
    ];

    public function mount(): void
    {
        $this->dateFrom = $this->dateFrom ?: now()->subMonths(2)->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
    }

    private function dateColumn(): string
    {
        return self::DATE_COLUMN_MAP[$this->dateType] ?? 'purchase_date';
    }

    /**
     * 큐 4 8-1 — 빠른 범위 선택 (이번달/전월/올해/전년도).
     * 조회 버튼 패턴과 분리 — 빠른 선택 클릭 시 dateFrom/dateTo만 갱신하고,
     * 사용자가 다시 조회 버튼을 눌러야 kpis 재계산. (자동 fetch 시 풀스캔 부담 회피)
     */
    public function setQuickRange(string $range): void
    {
        $now = now();
        [$from, $to] = match ($range) {
            'this_month'  => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month'  => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            'this_year'   => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'last_year'   => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default       => [$now->copy()->subMonths(2), $now->copy()],
        };
        $this->dateFrom = $from->format('Y-m-d');
        $this->dateTo = $to->format('Y-m-d');
    }

    /**
     * 매출/KPI 전용 — 채권 위젯은 의도적으로 제외 (CLAUDE.md 9단계 결정사항 #4).
     * 채권 정보는 /erp/receivables에서 별도 관리.
     */
    #[Computed]
    public function kpis(): array
    {
        $col = $this->dateColumn();
        $base = Vehicle::query()
            ->when($this->dateFrom, fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($col, '<=', $this->dateTo));

        $vehiclesInRange = (clone $base)->count();
        $purchaseTotal = (int) (clone $base)->sum('purchase_price');

        // 판매가 KRW 환산 합계
        $saleKrw = (clone $base)->where('sale_price', '>', 0)->get()->sum(function ($v) {
            return $v->currency === 'KRW' ? $v->sale_price : $v->sale_price * ($v->exchange_rate ?: 0);
        });
        $saleCount = (clone $base)->where('sale_price', '>', 0)->count();

        // 큐 16 — by_channel 집계 제거 (채널 단일).
        $byProgress = (clone $base)
            ->selectRaw('progress_status_cache, COUNT(*) as cnt')
            ->groupBy('progress_status_cache')
            ->pluck('cnt', 'progress_status_cache')
            ->toArray();

        return [
            'vehicles' => $vehiclesInRange,
            'purchase_total' => $purchaseTotal,
            'sale_total_krw' => (int) $saleKrw,
            'sale_count' => $saleCount,
            'by_progress' => $byProgress,
        ];
    }

    /**
     * 조회 버튼 — deferred wire:model로 받은 dateFrom/dateTo를 적용해
     * Computed `kpis` 캐시를 무효화. wire:model.live였을 때 매 keystroke마다
     * Vehicle 풀스캔 + KRW 환산 SUM이 돌던 부담 제거.
     */
    public function applyFilters(): void
    {
        unset(
            $this->kpis,
            $this->monthlyChartData,
            $this->salesmanPerformance,
            $this->settlementKpis,
            $this->receivableKpis,
            $this->clearanceKpis,
        );
        // 차트는 Alpine 인스턴스라 Livewire reactivity로 자동 갱신 안 됨 — 이벤트로 데이터 푸시.
        $this->dispatch('charts-refresh', data: [
            'monthly' => $this->monthlyChartData,
            'salesman' => $this->salesmanPerformance,
            'settlement' => $this->settlementKpis,
        ]);
    }

    /**
     * 큐 4 8-2 — 연간 월별 차트 데이터.
     * X축: 1~12월 (dateFrom의 year 기준 — 사용자가 다른 해를 보려면 dateFrom 변경).
     * dateType 무관 — 매입/판매/거래완료(B/L 발행) 각 컬럼별로 월 분포 + 판매가 KRW 합계.
     *
     * SQLite/MySQL 양쪽에서 동작하도록 PHP-side 그룹핑 사용 (whereYear는 grammar
     * 자동 변환되지만 selectRaw MONTH()는 dialect 종속).
     */
    #[Computed]
    public function monthlyChartData(): array
    {
        $year = $this->dateFrom ? (int) substr($this->dateFrom, 0, 4) : now()->year;

        $countByMonth = function (string $column) use ($year): array {
            $buckets = array_fill(1, 12, 0);
            Vehicle::query()
                ->whereYear($column, $year)
                ->whereNotNull($column)
                ->select($column)
                ->orderBy($column)
                ->chunk(1000, function ($rows) use (&$buckets, $column) {
                    foreach ($rows as $r) {
                        $d = $r->{$column};
                        if ($d) {
                            $buckets[(int) $d->format('n')]++;
                        }
                    }
                });
            return array_values($buckets);
        };

        // 판매가 KRW 환산 합계 — currency=KRW면 sale_price, 외화면 sale_price * exchange_rate.
        // 환율 NULL/0이면 KRW 환산 불가 → 합계에서 제외 (큐 2.5 C1과 동일 정책).
        $salesBuckets = array_fill(1, 12, 0);
        Vehicle::query()
            ->whereYear('sale_date', $year)
            ->whereNotNull('sale_date')
            ->where('sale_price', '>', 0)
            ->select('sale_date', 'sale_price', 'currency', 'exchange_rate')
            ->orderBy('sale_date')
            ->chunk(1000, function ($rows) use (&$salesBuckets) {
                foreach ($rows as $r) {
                    if (! $r->sale_date) {
                        continue;
                    }
                    $krw = $r->currency === 'KRW'
                        ? (int) $r->sale_price
                        : ($r->exchange_rate > 0 ? (int) ($r->sale_price * $r->exchange_rate) : 0);
                    $salesBuckets[(int) $r->sale_date->format('n')] += $krw;
                }
            });

        return [
            'year' => $year,
            'labels' => array_map(fn ($m) => $m.'월', range(1, 12)),
            'counts' => [
                'purchase'  => $countByMonth('purchase_date'),
                'sale'      => $countByMonth('sale_date'),
                'completed' => $countByMonth('bl_issue_date'),  // TODO: 정식 거래완료일 컬럼 도입 시 교체
            ],
            'sales_krw' => array_values($salesBuckets),
        ];
    }

    /**
     * 큐 4 8-3 — 담당자별 판매 성과 (상위 10명).
     * dateType 컬럼 기준 in-range + sale_price > 0인 차량을 salesman_id로 집계.
     * salesman_id NULL은 제외 (담당자 미지정은 통계 노이즈).
     */
    #[Computed]
    public function salesmanPerformance(): array
    {
        $col = $this->dateColumn();
        $aggregates = [];  // salesman_id => ['count' => N, 'total_krw' => N]

        Vehicle::query()
            ->when($this->dateFrom, fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($col, '<=', $this->dateTo))
            ->whereNotNull('salesman_id')
            ->where('sale_price', '>', 0)
            ->select('salesman_id', 'sale_price', 'currency', 'exchange_rate')
            ->chunk(1000, function ($rows) use (&$aggregates) {
                foreach ($rows as $r) {
                    $krw = $r->currency === 'KRW'
                        ? (int) $r->sale_price
                        : ($r->exchange_rate > 0 ? (int) ($r->sale_price * $r->exchange_rate) : 0);
                    $id = $r->salesman_id;
                    if (! isset($aggregates[$id])) {
                        $aggregates[$id] = ['count' => 0, 'total_krw' => 0];
                    }
                    $aggregates[$id]['count']++;
                    $aggregates[$id]['total_krw'] += $krw;
                }
            });

        // 판매금액 기준 상위 10명
        uasort($aggregates, fn ($a, $b) => $b['total_krw'] <=> $a['total_krw']);
        $topIds = array_slice(array_keys($aggregates), 0, 10);
        $names = Salesman::whereIn('id', $topIds)->pluck('name', 'id')->toArray();

        $labels = [];
        $saleCount = [];
        $saleTotalKrw = [];
        $avgPerVehicle = [];
        foreach ($topIds as $id) {
            $labels[] = $names[$id] ?? '담당자 #'.$id;
            $saleCount[] = $aggregates[$id]['count'];
            $saleTotalKrw[] = $aggregates[$id]['total_krw'];
            $avgPerVehicle[] = $aggregates[$id]['count'] > 0
                ? (int) ($aggregates[$id]['total_krw'] / $aggregates[$id]['count'])
                : 0;
        }

        return [
            'labels' => $labels,
            'sale_count' => $saleCount,
            'sale_total_krw' => $saleTotalKrw,
            'avg_per_vehicle' => $avgPerVehicle,
        ];
    }

    /**
     * 큐 4 8-5 — 정산 탭 대표급 KPI.
     * 회의록 2026-05-13-admin-dashboard-kpi.md 4종:
     * 1) 인원별 정산지급액 월별 stacked bar (paid_at MONTH × salesman, dateFrom의 year)
     * 2) 정산 지급 대기 총액 (confirmed-not-paid actual_payout SUM, dateFrom~dateTo 기간)
     * 3) 정산 마진율 평균 (paid settlements의 total_margin / sales_amount_krw)
     * 4) [큐 16 제거] 채널별 평균 마진 (단일 채널로 의미 없음)
     */
    #[Computed]
    public function settlementKpis(): array
    {
        $year = $this->dateFrom ? (int) substr($this->dateFrom, 0, 4) : now()->year;

        // 1) 인원별 정산지급액 월별 stacked bar
        $monthlyBySalesman = [];
        Settlement::query()
            ->where('settlement_status', 'paid')
            ->whereNotNull('paid_at')
            ->whereYear('paid_at', $year)
            ->whereNotNull('salesman_id')
            ->with('vehicle')
            ->chunk(500, function ($rows) use (&$monthlyBySalesman) {
                foreach ($rows as $s) {
                    $id = $s->salesman_id;
                    if (! isset($monthlyBySalesman[$id])) {
                        $monthlyBySalesman[$id] = array_fill(0, 12, 0);
                    }
                    $month = (int) $s->paid_at->format('n');
                    // paid는 confirmed_snapshot 우선 (큐 10 H4 — retroactive drift 방지)
                    $payout = $s->confirmed_snapshot['actual_payout']
                        ?? $s->actual_payout
                        ?? 0;
                    $monthlyBySalesman[$id][$month - 1] += (int) $payout;
                }
            });
        $totals = array_map('array_sum', $monthlyBySalesman);
        arsort($totals);
        $topIds = array_slice(array_keys($totals), 0, 8);
        $names = Salesman::whereIn('id', $topIds)->pluck('name', 'id')->toArray();
        $colors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#06b6d4', '#84cc16', '#f43f5e'];
        $datasets = [];
        foreach ($topIds as $idx => $id) {
            $datasets[] = [
                'label' => $names[$id] ?? '담당자 #'.$id,
                'data' => $monthlyBySalesman[$id],
                'backgroundColor' => $colors[$idx % count($colors)],
            ];
        }

        // 2) 정산 지급 대기 총액
        $payoutPending = 0;
        Settlement::query()
            ->where('settlement_status', 'confirmed')
            ->when($this->dateFrom, fn ($q) => $q->where('confirmed_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('confirmed_at', '<=', $this->dateTo.' 23:59:59'))
            ->with('vehicle')
            ->chunk(500, function ($rows) use (&$payoutPending) {
                foreach ($rows as $s) {
                    $payoutPending += (int) ($s->actual_payout ?? 0);
                }
            });

        // 3) 정산 마진율 평균 — 큐 16: 채널별 평균 마진 제거 (단일 채널).
        $marginRates = [];
        Settlement::query()
            ->where('settlement_status', 'paid')
            ->when($this->dateFrom, fn ($q) => $q->where('paid_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('paid_at', '<=', $this->dateTo.' 23:59:59'))
            ->chunk(500, function ($rows) use (&$marginRates) {
                foreach ($rows as $s) {
                    $snap = $s->confirmed_snapshot;
                    $totalMargin = (int) ($snap['total_margin'] ?? $s->total_margin ?? 0);
                    $salesKrw = (int) ($snap['sales_amount_krw'] ?? $s->sales_amount_krw ?? 0);
                    if ($salesKrw > 0) {
                        $marginRates[] = $totalMargin / $salesKrw;
                    }
                }
            });
        $avgMarginRate = $marginRates ? array_sum($marginRates) / count($marginRates) : 0;

        return [
            'year' => $year,
            'monthly' => [
                'labels' => array_map(fn ($m) => $m.'월', range(1, 12)),
                'datasets' => $datasets,
            ],
            'payout_pending' => $payoutPending,
            'avg_margin_rate' => $avgMarginRate,
        ];
    }

    /**
     * 큐 4 8-6 — 채권 탭 KPI.
     * 회의록 결정:
     * - 미납률 = SUM(미수금 KRW) / SUM(총판매액 KRW). 환율 0 외화는 둘 다 제외.
     * - 담당자/바이어 TOP 10 테이블.
     * - 위험도(receivable_risk) 4단 카운트.
     *
     * SKILLS §13 단일 출처 정합:
     * - 분자 = sale_unpaid_amount_krw_cache (Vehicle saving 훅 자동 갱신)
     * - 분모 = sale_total_amount × exchange_rate (부대비용 포함, accessor 정의와 동일)
     * - sale_price만 사용하면 분자(부대비용 포함) ↔ 분모(미포함) 비대칭 → 의미 없는 비율 산출됨
     *
     * 큐 4 점검 — dateColumn 기준 dateFrom/dateTo 적용 (vehicles/index action 진입 시 동일 기간 적용).
     * 위험도 카운트는 receivable_safe/caution/danger/critical scopeAction과 SQL 100% 일치.
     */
    #[Computed]
    public function receivableKpis(): array
    {
        $col = $this->dateColumn();

        // 미수금 차량 — sale_unpaid_amount_krw_cache > 0 (NULL은 환율 미입력 외화 → 제외)
        $bySalesman = [];
        $byBuyer = [];
        $riskCounts = ['safe' => 0, 'caution' => 0, 'danger' => 0, 'critical' => 0, 'none' => 0];
        // 큐 10 확장 — G3 분류별 미수금 합산 (회의록 v5 §G3).
        // 선적전: 매입중·매입완료·말소완료·판매중·판매완료 + unpaid > 0
        // 선적후: 수출통관중·수출통관완료·선적중·선적완료 + unpaid > 0
        $beforeShippingStages = ['매입중', '매입완료', '말소완료', '판매중', '판매완료'];
        $afterShippingStages = ['수출통관중', '수출통관완료', '선적중', '선적완료'];
        $classification = [
            'before_shipping' => ['unpaid' => 0, 'count' => 0],
            'after_shipping' => ['unpaid' => 0, 'count' => 0],
        ];

        Vehicle::query()
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->when($this->dateFrom, fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($col, '<=', $this->dateTo))
            ->select('id', 'salesman_id', 'buyer_id', 'sale_price', 'transport_fee',
                'sale_other_costs', 'commission', 'auto_loading', 'tax_dc',
                'currency', 'exchange_rate', 'sale_unpaid_amount_krw_cache',
                'receivable_risk', 'progress_status_cache')
            ->chunk(1000, function ($rows) use (
                &$bySalesman, &$byBuyer, &$riskCounts, &$classification,
                $beforeShippingStages, $afterShippingStages
            ) {
                foreach ($rows as $r) {
                    // SKILLS §13 — sale_total_amount accessor와 동일 공식 (부대비용 포함).
                    $saleTotal = $r->sale_price + $r->transport_fee + $r->sale_other_costs
                        + $r->commission + $r->auto_loading - $r->tax_dc;
                    $saleKrw = $r->currency === 'KRW'
                        ? (int) $saleTotal
                        : ($r->exchange_rate > 0 ? (int) ($saleTotal * $r->exchange_rate) : 0);
                    if ($saleKrw === 0) {
                        continue;  // 환율 0/외화 KRW 환산 불가 — 분모 측정 불가
                    }
                    $unpaid = (int) $r->sale_unpaid_amount_krw_cache;

                    if ($r->salesman_id) {
                        if (! isset($bySalesman[$r->salesman_id])) {
                            $bySalesman[$r->salesman_id] = ['unpaid' => 0, 'sale_total' => 0, 'vehicle_count' => 0];
                        }
                        $bySalesman[$r->salesman_id]['unpaid'] += $unpaid;
                        $bySalesman[$r->salesman_id]['sale_total'] += $saleKrw;
                        $bySalesman[$r->salesman_id]['vehicle_count']++;
                    }
                    if ($r->buyer_id) {
                        if (! isset($byBuyer[$r->buyer_id])) {
                            $byBuyer[$r->buyer_id] = ['unpaid' => 0, 'sale_total' => 0, 'vehicle_count' => 0];
                        }
                        $byBuyer[$r->buyer_id]['unpaid'] += $unpaid;
                        $byBuyer[$r->buyer_id]['sale_total'] += $saleKrw;
                        $byBuyer[$r->buyer_id]['vehicle_count']++;
                    }
                    $risk = $r->receivable_risk ?? 'none';
                    $riskCounts[$risk] = ($riskCounts[$risk] ?? 0) + 1;

                    // 큐 10 확장 — G3 분류 합산 (회의록 v5 §G3, 사용자 결정 2026-05-18).
                    if (in_array($r->progress_status_cache, $beforeShippingStages, true)) {
                        $classification['before_shipping']['unpaid'] += $unpaid;
                        $classification['before_shipping']['count']++;
                    } elseif (in_array($r->progress_status_cache, $afterShippingStages, true)) {
                        $classification['after_shipping']['unpaid'] += $unpaid;
                        $classification['after_shipping']['count']++;
                    }
                }
            });

        // 큐 10 확장 — 디파짓(savings_used > 0)은 별도 query (sale_unpaid 무관, 적립금 사용 차량 모두).
        $depositStats = Vehicle::query()
            ->where('savings_used', '>', 0)
            ->when($this->dateFrom, fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($col, '<=', $this->dateTo))
            ->selectRaw('COUNT(*) as cnt, SUM(savings_used) as total_used')
            ->first();
        $classification['deposit'] = [
            'unpaid' => (int) ($depositStats->total_used ?? 0),
            'count' => (int) ($depositStats->cnt ?? 0),
        ];

        // 담당자/바이어 미수금 내림차순 TOP 10 + 이름 매핑
        $sortFn = fn ($a, $b) => $b['unpaid'] <=> $a['unpaid'];
        uasort($bySalesman, $sortFn);
        uasort($byBuyer, $sortFn);
        $topSalesmanIds = array_slice(array_keys($bySalesman), 0, 10);
        $topBuyerIds = array_slice(array_keys($byBuyer), 0, 10);
        $salesmanNames = Salesman::whereIn('id', $topSalesmanIds)->pluck('name', 'id')->toArray();
        $buyerNames = Buyer::whereIn('id', $topBuyerIds)->pluck('name', 'id')->toArray();

        $buildRows = function (array $ids, array $data, array $names): array {
            $rows = [];
            foreach ($ids as $id) {
                $unpaid = $data[$id]['unpaid'];
                $total = $data[$id]['sale_total'];
                $rows[] = [
                    'id' => $id,
                    'name' => $names[$id] ?? '#'.$id,
                    'unpaid' => $unpaid,
                    'sale_total' => $total,
                    'unpaid_rate' => $total > 0 ? round($unpaid / $total * 100, 1) : 0,
                    'vehicle_count' => $data[$id]['vehicle_count'],
                ];
            }
            return $rows;
        };

        return [
            'salesman_top' => $buildRows($topSalesmanIds, $bySalesman, $salesmanNames),
            'buyer_top' => $buildRows($topBuyerIds, $byBuyer, $buyerNames),
            'risk_counts' => $riskCounts,
            'total_unpaid' => array_sum(array_column($bySalesman, 'unpaid')),
            // 큐 10 확장 — G3 미수 분류 (회의록 v5 §G3, 사용자 결정 2026-05-18)
            'classification' => $classification,
        ];
    }

    /**
     * 큐 4 8-7 — 통관 탭 대표급 KPI.
     * 회의록 결정 3종 (평균 통관일수는 export_cleared_at 부재로 보류):
     * 1) 통관 정체 차량 수 — 판매완료(sale_price>0 AND unpaid<=0) +
     *    수출신고서 NULL + sale_date 30일 경과
     * 2) 수출신고서 미업로드 차량 수 — 수출통관중 단계 (export_buyer_id+shipping_date 있지만 문서 NULL)
     * 3) 포워딩사 TOP 5 진행 차량 수 — forwarding_company_id GROUP, 통관/선적 단계 한정
     *
     * SQL 100% 일치 원칙 (SKILLS.md §9):
     * - Vehicle::scopeAction의 activeOnly(dhl_request=false)와 동일
     * - dateColumn() 기준 dateFrom/dateTo 동일 적용 (vehicles/index와 동일 컨텍스트)
     */
    #[Computed]
    public function clearanceKpis(): array
    {
        $stuckThresholdDays = 30;
        $stuckDate = now()->subDays($stuckThresholdDays)->format('Y-m-d');
        $col = $this->dateColumn();

        // 공통: active 한정 + dateColumn 범위 (scopeAction과 일치)
        $applyCommonFilters = fn ($q) => $q
            ->where('dhl_request', false)
            ->when($this->dateFrom, fn ($q2) => $q2->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q2) => $q2->where($col, '<=', $this->dateTo));

        // 1) 통관 정체 — clearance_stuck action과 동일. 큐 16: sales_channel 단일.
        $stuckCount = $applyCommonFilters(Vehicle::query())
            ->where('sale_price', '>', 0)
            ->where(function ($q) {
                $q->whereNull('sale_unpaid_amount_krw_cache')
                    ->orWhere('sale_unpaid_amount_krw_cache', '<=', 0);
            })
            ->whereNull('export_declaration_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '<=', $stuckDate)
            ->count();

        // 2) 수출신고서 미업로드 — export_declaration_upload_needed action과 동일.
        $unfiledCount = $applyCommonFilters(Vehicle::query())
            ->whereNotNull('export_buyer_id')
            ->whereNotNull('shipping_date')
            ->whereNull('export_declaration_document')
            ->count();

        // 3) 포워딩사별 진행 차량 — 통관/선적 단계
        $clearanceStages = ['수출통관중', '수출통관완료', '선적중', '선적완료'];
        $byForwarder = $applyCommonFilters(Vehicle::query())
            ->whereNotNull('forwarding_company_id')
            ->whereIn('progress_status_cache', $clearanceStages)
            ->selectRaw('forwarding_company_id, COUNT(*) as cnt')
            ->groupBy('forwarding_company_id')
            ->orderByDesc('cnt')
            ->limit(5)
            ->pluck('cnt', 'forwarding_company_id')
            ->toArray();
        $forwarderNames = ForwardingCompany::whereIn('id', array_keys($byForwarder))
            ->pluck('name', 'id')->toArray();
        $forwarderTop = [];
        foreach ($byForwarder as $id => $cnt) {
            $forwarderTop[] = ['name' => $forwarderNames[$id] ?? '#'.$id, 'count' => $cnt];
        }

        return [
            'stuck_count' => $stuckCount,
            'stuck_threshold_days' => $stuckThresholdDays,
            'unfiled_count' => $unfiledCount,
            'forwarder_top' => $forwarderTop,
        ];
    }

    /**
     * 카드/뱃지 클릭 시 vehicles 목록으로 이동할 URL 빌더.
     * 모든 링크가 같은 dateFrom/dateTo + dateType 컨텍스트를 공유.
     * admin 'completed'는 vehicles에 존재 안 함 → 'bl'로 매핑 (B/L 발행일).
     */
    public function vehiclesUrl(array $extra = []): string
    {
        $vehiclesDateType = $this->dateType === 'completed' ? 'bl' : $this->dateType;
        $params = array_merge([
            'dateType' => $vehiclesDateType,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
        ], $extra);

        return route('erp.vehicles.index').'?'.http_build_query($params);
    }
}; ?>

<div x-data="adminDashboard()" class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    {{-- 헤더 — 모바일 세로 스택, 데스크탑 좌우 분리 --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">관리자 대시보드</h1>
            <p class="mt-1 text-sm text-gray-500">매출 / 차량 진행 KPI · 기간별 비즈니스 지표</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- 큐 4 8-1 — 기준일 컬럼 전환 (조회 버튼 클릭 시에만 반영) --}}
            <select wire:model="dateType" class="input-filter">
                <option value="purchase">매입일 기준</option>
                <option value="sale">판매일 기준</option>
                <option value="shipping">선적일 기준</option>
                <option value="completed">거래완료일 기준</option>
            </select>
            <input type="date" wire:model="dateFrom" class="input-filter" />
            <span class="text-xs text-gray-400">~</span>
            <input type="date" wire:model="dateTo" class="input-filter" />
            <button wire:click="applyFilters" type="button"
                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-violet-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                조회
            </button>
            <button @click="settingsOpen = true" type="button"
                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                위젯 설정
            </button>
        </div>
    </div>

    {{-- 큐 4 8-1 — 빠른 범위 선택 (클릭 시 dateFrom/dateTo만 갱신, 조회 버튼으로 재계산) --}}
    <div class="flex flex-wrap items-center gap-1.5 text-xs">
        <span class="mr-1 text-gray-400">빠른 선택:</span>
        @foreach ([
            'this_month' => '이번달',
            'last_month' => '전월',
            'this_year'  => '올해',
            'last_year'  => '전년도',
        ] as $key => $label)
        <button type="button" wire:click="setQuickRange('{{ $key }}')"
            class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-gray-600 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700">
            {{ $label }}
        </button>
        @endforeach
        <span class="ml-2 text-gray-400">→ 조회 버튼을 눌러 적용</span>
    </div>

    {{-- 큐 4 8-4 — role 탭. 위젯 필터만 적용 (신규 집계 없음). --}}
    {{-- 큐 4 점검 — '전체' 탭과 '영업' 탭이 거의 같아 통합: '영업' 1개로 단일화 --}}
    <div class="flex flex-wrap gap-1 border-b border-gray-200">
        @foreach ([
            'sales'      => '영업',
            'clearance'  => '수출통관',
            'settlement' => '재무',
            'receivable' => '채권',
        ] as $key => $label)
        <button type="button" @click="setActiveTab('{{ $key }}')"
            :class="activeTab === '{{ $key }}'
                ? 'border-violet-500 text-violet-700 bg-violet-50/50'
                : 'border-transparent text-gray-500 hover:text-gray-800 hover:bg-gray-50'"
            class="border-b-2 px-3 py-2 text-sm font-medium transition">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- 매출 KPI 카드 4개 (모두 클릭 가능) --}}
    <div id="w-kpi" x-show="isWidgetVisible('w-kpi')" class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <a href="{{ $this->vehiclesUrl() }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 차량 수</span>
                <span class="text-xs text-violet-500">상세 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->kpis['vehicles']) }}<span class="ml-1 text-sm font-normal text-gray-500">대</span></div>
        </a>
        <a href="{{ $this->vehiclesUrl(['action' => 'has_purchase']) }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 매입가 합계</span>
                <span class="text-xs text-violet-500">상세 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->kpis['purchase_total']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </a>
        <a href="{{ $this->vehiclesUrl(['action' => 'has_sale']) }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 판매가 합계 (KRW 환산)</span>
                <span class="text-xs text-violet-500">{{ $this->kpis['sale_count'] }}대 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-blue-600">{{ number_format($this->kpis['sale_total_krw']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </a>
        <a href="{{ route('erp.receivables.index') }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">채권 관리</span>
                <span class="text-xs text-violet-500">이동 →</span>
            </div>
            <div class="mt-1 text-sm font-medium text-violet-600">미수금 / 회수 이력</div>
            <div class="mt-1 text-xs text-gray-400">채권은 별도 화면에서 관리</div>
        </a>
    </div>

    {{-- 채널별 분포 --}}
    {{-- 큐 16 — w-channel 위젯 제거 (채널 단일화). --}}

    {{-- 진행 단계별 분포 — 큐 2번 파이프라인 카운트 스트립 (선택 기준일 기준) --}}
    @php
        $dateTypeLabel = ['purchase' => '매입일', 'sale' => '판매일', 'shipping' => '선적일', 'completed' => '거래완료일'][$dateType] ?? '매입일';
    @endphp
    <div id="w-progress" x-show="isWidgetVisible('w-progress')">
        <x-erp.pipeline-strip
            :counts="$this->kpis['by_progress']"
            :url-builder="fn (string $s) => $this->vehiclesUrl(['progressFilter' => $s])"
            title="진행 단계별 차량 수"
            :subtitle="$dateTypeLabel.' '.$dateFrom.' ~ '.$dateTo.' 한정'" />
    </div>

    {{-- 큐 4 8-7 — 통관 탭 KPI (clearance 탭에서만 노출) --}}
    <div id="w-clearance" x-show="isWidgetVisible('w-clearance')" class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {{-- 카운트 SQL과 action SQL 100% 일치 (Vehicle::scopeAction clearance_stuck) --}}
            <a href="{{ $this->vehiclesUrl(['action' => 'clearance_stuck']) }}" wire:navigate
               class="card border-red-200 bg-red-50/30 transition hover:bg-red-50">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">통관 정체 차량</span>
                    <span class="text-xs text-red-600">{{ $this->clearanceKpis['stuck_threshold_days'] }}일 경과</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-red-600">
                    {{ number_format($this->clearanceKpis['stuck_count']) }}<span class="ml-1 text-sm font-normal text-gray-500">대</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">판매완료·완납인데 수출신고서 미발급</p>
            </a>
            {{-- 카운트 SQL = Vehicle::scopeAction export_declaration_upload_needed --}}
            <a href="{{ $this->vehiclesUrl(['action' => 'export_declaration_upload_needed']) }}" wire:navigate
               class="card transition hover:bg-gray-50">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">수출신고서 미업로드</span>
                    <span class="text-xs text-amber-500">수출통관중 단계</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-amber-600">
                    {{ number_format($this->clearanceKpis['unfiled_count']) }}<span class="ml-1 text-sm font-normal text-gray-500">대</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">통관 바이어·선적일 있지만 문서 NULL</p>
            </a>
            <div class="card">
                <div class="section-header">
                    <span class="section-dot bg-blue-500"></span>
                    <span class="section-title">포워딩사 TOP 5 (통관·선적 진행)</span>
                </div>
                @if (empty($this->clearanceKpis['forwarder_top']))
                <p class="mt-3 text-sm text-gray-400">진행 차량 없음</p>
                @else
                <ul class="mt-2 space-y-1 text-xs">
                @foreach ($this->clearanceKpis['forwarder_top'] as $row)
                    <li class="flex items-center justify-between">
                        <span class="text-gray-700">{{ $row['name'] }}</span>
                        <span class="font-mono font-semibold text-blue-600">{{ $row['count'] }}대</span>
                    </li>
                @endforeach
                </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- 큐 4 8-5 — 정산 탭 KPI (settlement 탭에서만 노출) --}}
    <div id="w-settlement" x-show="isWidgetVisible('w-settlement')" class="space-y-4">
        {{-- 정산 KPI 카드 3종 — flex-wrap으로 가로 배치 --}}
        <div class="flex flex-wrap gap-3">
            <div class="card min-w-[260px] flex-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">정산 지급 대기 총액</span>
                    <span class="text-xs text-amber-500">확정·미지급</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-amber-600">
                    {{ number_format($this->settlementKpis['payout_pending']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">확정 상태 정산의 실지급액 합계</p>
            </div>
            <div class="card min-w-[260px] flex-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">정산 마진율 평균</span>
                    <span class="text-xs text-violet-500">지급완료 한정</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-violet-600">
                    {{ number_format($this->settlementKpis['avg_margin_rate'] * 100, 1) }}<span class="ml-1 text-sm font-normal text-gray-500">%</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">총마진 / 판매금원화 평균</p>
            </div>
            {{-- 큐 16 — '채널별 평균 마진' 카드 제거 (채널 단일화). --}}
        </div>

        {{-- 인원별 정산지급액 월별 stacked bar --}}
        <div class="card">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">인원별 정산지급액 월별 (<span x-text="chartData.settlement.year"></span>년)</span>
            </div>
            <div class="mt-3 h-72">
                <canvas x-ref="settlementMonthlyCanvas"></canvas>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">지급완료 정산만 집계. 상위 8명 누적. 스냅샷 우선 (소급 보정 방지).</p>
        </div>
    </div>

    {{-- 큐 4 8-3 — 담당자별 성과 차트 (상위 10명, dateType 기준) --}}
    <div id="w-salesman" x-show="isWidgetVisible('w-salesman')" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div class="card">
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">담당자별 판매 대수 (상위 10명)</span>
            </div>
            <div class="mt-3 h-64">
                <canvas x-ref="salesmanCountCanvas"></canvas>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">{{ $dateTypeLabel }} {{ $dateFrom }} ~ {{ $dateTo }} 기준</p>
        </div>
        <div class="card">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">담당자별 판매 금액 KRW (상위 10명)</span>
            </div>
            <div class="mt-3 h-64">
                <canvas x-ref="salesmanKrwCanvas"></canvas>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">tooltip에 평균 판매가 표시</p>
        </div>
    </div>

    {{-- 큐 4 8-2 — 연간 월별 차트 2개 (X축: 1~12월, 기준 연도: dateFrom의 year) --}}
    <div id="w-monthly" x-show="isWidgetVisible('w-monthly')" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div class="card">
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">월별 차량 대수 (<span x-text="chartData.year"></span>년)</span>
            </div>
            <div class="mt-3 h-64">
                <canvas x-ref="monthlyCountsCanvas"></canvas>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">매입·판매·거래완료(B/L 발행) 컬럼별 월 분포</p>
        </div>
        <div class="card">
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">월별 판매가 합계 KRW (<span x-text="chartData.year"></span>년)</span>
            </div>
            <div class="mt-3 h-64">
                <canvas x-ref="monthlySalesCanvas"></canvas>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">sale_date 기준. 외화는 sale_price × exchange_rate (환율 0/NULL 제외)</p>
        </div>
    </div>

    {{-- 큐 4 8-6 — 채권 탭. 위험도 카운트 + 담당자/바이어 TOP 10 + receivables 화면 링크 --}}
    <div x-show="activeTab === 'receivable'" class="space-y-4">
        {{-- 총 미수금 + 위험도 카운트 5개 — flex-wrap (Tailwind 빌드 누락 대비) --}}
        <div class="flex flex-wrap gap-3">
            <div class="card min-w-[180px] flex-1 border-red-200 bg-red-50/30">
                <div class="text-xs text-gray-500">총 미수금</div>
                <div class="mt-1 text-2xl font-bold text-red-600">
                    {{ number_format($this->receivableKpis['total_unpaid']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">환율 미입력 외화 제외</p>
            </div>
            {{-- 카운트 SQL = Vehicle::scopeAction receivable_{key} — vehicles 화면 채널 통합 라우팅 --}}
            @foreach (['safe' => ['안전', 'green'], 'caution' => ['주의', 'amber'], 'danger' => ['위험', 'amber'], 'critical' => ['심각', 'red']] as $key => [$label, $badge])
            <a href="{{ $this->vehiclesUrl(['action' => 'receivable_'.$key]) }}" wire:navigate
               class="card min-w-[160px] flex-1 transition hover:bg-gray-50">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">{{ $label }}</span>
                    <span class="badge badge-{{ $badge }}">{{ $label }}</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->receivableKpis['risk_counts'][$key] ?? 0) }}<span class="ml-1 text-sm font-normal text-gray-500">대</span></div>
            </a>
            @endforeach
        </div>

        {{-- 큐 10 확장 — G3 미수 분류 카드 3개 (회의록 v5 §G3, 사용자 결정 2026-05-18) --}}
        @php $cls = $this->receivableKpis['classification'] ?? []; @endphp
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('erp.receivables.index', ['classification' => 'before_shipping']) }}" wire:navigate
               class="card min-w-[200px] flex-1 transition hover:bg-gray-50">
                <div class="text-xs text-gray-500">선적전 미수금</div>
                <div class="mt-1 text-2xl font-bold text-blue-700">{{ number_format($cls['before_shipping']['unpaid'] ?? 0) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
                <p class="mt-1 text-[11px] text-gray-400">{{ $cls['before_shipping']['count'] ?? 0 }}대 · 매입~판매완료 단계</p>
            </a>
            <a href="{{ route('erp.receivables.index', ['classification' => 'after_shipping']) }}" wire:navigate
               class="card min-w-[200px] flex-1 transition hover:bg-gray-50">
                <div class="text-xs text-gray-500">선적후 미수금</div>
                <div class="mt-1 text-2xl font-bold text-amber-700">{{ number_format($cls['after_shipping']['unpaid'] ?? 0) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
                <p class="mt-1 text-[11px] text-gray-400">{{ $cls['after_shipping']['count'] ?? 0 }}대 · 통관~선적완료 단계</p>
            </a>
            <a href="{{ route('erp.receivables.index', ['classification' => 'deposit']) }}" wire:navigate
               class="card min-w-[200px] flex-1 transition hover:bg-gray-50">
                <div class="text-xs text-gray-500">디파짓 (적립금 사용분)</div>
                <div class="mt-1 text-2xl font-bold text-violet-700">{{ number_format($cls['deposit']['unpaid'] ?? 0) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
                <p class="mt-1 text-[11px] text-gray-400">{{ $cls['deposit']['count'] ?? 0 }}대 · savings_used &gt; 0</p>
            </a>
        </div>

        {{-- 담당자 TOP 10 / 바이어 TOP 10 테이블 2개 --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div class="card">
                <div class="section-header">
                    <span class="section-dot bg-violet-500"></span>
                    <span class="section-title">미수금 상위 담당자 TOP 10</span>
                </div>
                @if (empty($this->receivableKpis['salesman_top']))
                <p class="mt-3 text-sm text-gray-400">미수금 차량 없음</p>
                @else
                <table class="mt-3 w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="py-1.5 text-left">담당자</th>
                            <th class="py-1.5 text-right">미수금</th>
                            <th class="py-1.5 text-right">미납률</th>
                            <th class="py-1.5 text-right">차량</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($this->receivableKpis['salesman_top'] as $row)
                        <tr class="{{ $row['unpaid_rate'] >= 50 ? 'bg-amber-50' : '' }}">
                            <td class="py-1.5">{{ $row['name'] }}</td>
                            <td class="py-1.5 text-right font-mono">{{ number_format($row['unpaid']) }}</td>
                            <td class="py-1.5 text-right font-semibold {{ $row['unpaid_rate'] >= 50 ? 'text-amber-700' : 'text-gray-700' }}">{{ $row['unpaid_rate'] }}%</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $row['vehicle_count'] }}대</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
            <div class="card">
                <div class="section-header">
                    <span class="section-dot bg-blue-500"></span>
                    <span class="section-title">미수금 상위 바이어 TOP 10</span>
                </div>
                @if (empty($this->receivableKpis['buyer_top']))
                <p class="mt-3 text-sm text-gray-400">미수금 차량 없음</p>
                @else
                <table class="mt-3 w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="py-1.5 text-left">바이어</th>
                            <th class="py-1.5 text-right">미수금</th>
                            <th class="py-1.5 text-right">미납률</th>
                            <th class="py-1.5 text-right">차량</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($this->receivableKpis['buyer_top'] as $row)
                        <tr class="{{ $row['unpaid_rate'] >= 50 ? 'bg-amber-50' : '' }}">
                            <td class="py-1.5">{{ $row['name'] }}</td>
                            <td class="py-1.5 text-right font-mono">{{ number_format($row['unpaid']) }}</td>
                            <td class="py-1.5 text-right font-semibold {{ $row['unpaid_rate'] >= 50 ? 'text-amber-700' : 'text-gray-700' }}">{{ $row['unpaid_rate'] }}%</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $row['vehicle_count'] }}대</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        {{-- 채권관리 상세 링크 --}}
        <div class="card border-violet-200 bg-violet-50/30">
            <p class="text-sm text-gray-700">회수 이력·세부 차량별 정보는 채권관리 화면에서 관리됩니다.</p>
            <a href="{{ route('erp.receivables.index') }}" wire:navigate
               class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-700">
                채권관리 화면으로 이동 →
            </a>
        </div>
    </div>

    <p class="text-xs text-gray-400" x-show="activeTab !== 'receivable'">
        ⓘ 미수금·회수 이력·채권 위험도는 <a href="{{ route('erp.receivables.index') }}" wire:navigate class="text-violet-600 hover:underline">채권관리 화면</a>에서 확인하세요.
    </p>

    {{-- 위젯 설정 슬라이드 패널 --}}
    <div x-show="settingsOpen" x-cloak class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-black/30" @click="settingsOpen = false"></div>
        <div
            x-show="settingsOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="relative z-10 h-full w-80 overflow-y-auto bg-white p-6 shadow-xl">

            <div class="mb-6 flex items-center justify-between">
                <h3 class="font-bold text-gray-800">위젯 설정</h3>
                <button @click="settingsOpen = false" class="text-gray-400 hover:text-gray-600" type="button">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <p class="mb-4 text-xs text-gray-500">표시할 위젯을 선택하세요. 설정은 이 브라우저에 저장됩니다.</p>

            <div class="space-y-4">
                <template x-for="w in widgetList" :key="w.key">
                    <label class="flex items-center justify-between">
                        <span class="text-sm text-gray-700" x-text="w.label"></span>
                        <button @click="toggleWidget(w.key)" type="button"
                            :class="widgets[w.key] ? 'bg-[var(--color-primary)]' : 'bg-gray-300'"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors">
                            <span :class="widgets[w.key] ? 'translate-x-5' : 'translate-x-1'"
                                class="inline-block h-3 w-3 transform rounded-full bg-white transition-transform"></span>
                        </button>
                    </label>
                </template>
            </div>
        </div>
    </div>

    {{-- Chart.js v4 (my-crm 패턴과 동일 CDN) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <script>
        function adminDashboard() {
            return {
                settingsOpen: false,
                widgets: {},
                widgetList: [
                    { key: 'w-kpi',        label: 'KPI 카드 (4개)' },
                    // 큐 16 — w-channel 제거
                    { key: 'w-progress',   label: '진행 단계별 차량 수' },
                    { key: 'w-monthly',    label: '월별 차트 (대수·판매가)' },
                    { key: 'w-salesman',   label: '담당자별 성과 차트' },
                    { key: 'w-settlement', label: '정산 KPI (지급액·마진)' },
                    { key: 'w-clearance',  label: '통관 KPI (정체·미업로드·포워딩사)' },
                ],
                // 큐 4 8-4·8-5·8-7·8-8 — 탭별 노출 위젯 매핑
                // 점검 — 전체↔영업 통합 (영업이 회사 전체 흐름을 다 포함). 활성 탭 기본=영업.
                activeTab: 'sales',
                tabWidgets: {
                    sales:      ['w-kpi', 'w-progress', 'w-monthly', 'w-salesman'],
                    clearance:  ['w-kpi', 'w-progress', 'w-clearance'],
                    settlement: ['w-kpi', 'w-settlement'],
                    receivable: ['w-kpi'],  // 채권 탭은 별도 안내 카드 + KPI만 (8-6 완료)
                },
                chartData: {
                    monthly: @js($this->monthlyChartData),
                    salesman: @js($this->salesmanPerformance),
                    settlement: @js($this->settlementKpis),
                },
                charts: { monthlyCounts: null, monthlySales: null, salesmanCount: null, salesmanKrw: null, settlementMonthly: null },
                init() {
                    const saved = localStorage.getItem('car_erp_admin_dashboard_widgets');
                    const parsed = saved ? JSON.parse(saved) : {};
                    this.widgetList.forEach(w => {
                        this.widgets[w.key] = parsed[w.key] !== undefined ? parsed[w.key] : true;
                    });

                    const savedTab = localStorage.getItem('car_erp_admin_dashboard_tab');
                    // 'all' 탭 폐기 (전체↔영업 통합) — 옛 사용자는 sales로 마이그레이션
                    if (savedTab === 'all') {
                        this.activeTab = 'sales';
                        localStorage.setItem('car_erp_admin_dashboard_tab', 'sales');
                    } else if (savedTab && this.tabWidgets[savedTab]) {
                        this.activeTab = savedTab;
                    }

                    // 초기 차트 렌더 — DOM 안착 후 (월별 차트 위젯이 꺼져 있어도 ref는 존재)
                    this.$nextTick(() => this.renderCharts());

                    // 큐 4 8-2 — 조회 버튼 적용 시 charts-refresh 이벤트로 데이터 푸시
                    Livewire.on('charts-refresh', (event) => {
                        this.chartData = event.data;
                        this.$nextTick(() => this.renderCharts());
                    });
                },
                isWidgetVisible(key) {
                    return (this.widgets[key] ?? true) && (this.tabWidgets[this.activeTab] || []).includes(key);
                },
                setActiveTab(tab) {
                    this.activeTab = tab;
                    localStorage.setItem('car_erp_admin_dashboard_tab', tab);
                    // 탭 전환으로 새로 visible 된 canvas의 0x0 → 재렌더링
                    this.$nextTick(() => this.renderCharts());
                },
                toggleWidget(key) {
                    this.widgets[key] = !this.widgets[key];
                    localStorage.setItem('car_erp_admin_dashboard_widgets', JSON.stringify(this.widgets));
                    // 차트 위젯이 켜질 때 canvas가 0x0이었던 경우 재렌더링
                    if (['w-monthly', 'w-salesman', 'w-settlement'].includes(key) && this.widgets[key]) {
                        this.$nextTick(() => this.renderCharts());
                    }
                },
                renderCharts() {
                    if (typeof Chart === 'undefined') return;
                    this.renderMonthlyCharts();
                    this.renderSalesmanCharts();
                    this.renderSettlementChart();
                },
                renderMonthlyCharts() {
                    const m = this.chartData.monthly;

                    // 월별 차량 대수 — bar 3색
                    const c1 = this.$refs.monthlyCountsCanvas;
                    if (c1) {
                        if (this.charts.monthlyCounts) this.charts.monthlyCounts.destroy();
                        this.charts.monthlyCounts = new Chart(c1, {
                            type: 'bar',
                            data: {
                                labels: m.labels,
                                datasets: [
                                    { label: '매입',    data: m.counts.purchase,  backgroundColor: 'rgba(59, 130, 246, 0.7)',  maxBarThickness: 20 },
                                    { label: '판매',    data: m.counts.sale,      backgroundColor: 'rgba(139, 92, 246, 0.7)',  maxBarThickness: 20 },
                                    { label: '거래완료', data: m.counts.completed, backgroundColor: 'rgba(16, 185, 129, 0.7)',  maxBarThickness: 20 },
                                ],
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                            },
                        });
                    }

                    // 월별 판매가 KRW — line
                    const c2 = this.$refs.monthlySalesCanvas;
                    if (c2) {
                        if (this.charts.monthlySales) this.charts.monthlySales.destroy();
                        this.charts.monthlySales = new Chart(c2, {
                            type: 'line',
                            data: {
                                labels: m.labels,
                                datasets: [{
                                    label: '판매가 KRW',
                                    data: m.sales_krw,
                                    borderColor: 'rgba(59, 130, 246, 1)',
                                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                    fill: true,
                                    tension: 0.3,
                                }],
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { callbacks: { label: (ctx) => '₩ ' + ctx.parsed.y.toLocaleString() } },
                                },
                                scales: {
                                    y: { beginAtZero: true, ticks: { callback: (v) => (v / 1e6).toFixed(0) + 'M' } },
                                },
                            },
                        });
                    }
                },
                renderSettlementChart() {
                    const s = this.chartData.settlement;
                    if (!s || !s.monthly) return;

                    const c = this.$refs.settlementMonthlyCanvas;
                    if (!c) return;
                    if (this.charts.settlementMonthly) this.charts.settlementMonthly.destroy();
                    // stacked bar의 각 dataset에 maxBarThickness 주입 (서버에서 못 보내므로 client에서)
                    const stackedDatasets = s.monthly.datasets.map(d => ({ ...d, maxBarThickness: 32 }));
                    this.charts.settlementMonthly = new Chart(c, {
                        type: 'bar',
                        data: { labels: s.monthly.labels, datasets: stackedDatasets },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12 } },
                                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ₩ ${ctx.parsed.y.toLocaleString()}` } },
                            },
                            scales: {
                                x: { stacked: true },
                                y: { stacked: true, beginAtZero: true, ticks: { callback: (v) => (v / 1e6).toFixed(0) + 'M' } },
                            },
                        },
                    });
                },
                renderSalesmanCharts() {
                    const s = this.chartData.salesman;
                    if (!s || !s.labels || s.labels.length === 0) return;

                    // 담당자별 판매 대수 — horizontal bar (maxBarThickness로 두께 제한)
                    const c1 = this.$refs.salesmanCountCanvas;
                    if (c1) {
                        if (this.charts.salesmanCount) this.charts.salesmanCount.destroy();
                        this.charts.salesmanCount = new Chart(c1, {
                            type: 'bar',
                            data: {
                                labels: s.labels,
                                datasets: [{
                                    label: '판매 대수',
                                    data: s.sale_count,
                                    backgroundColor: 'rgba(139, 92, 246, 0.7)',
                                    maxBarThickness: 28,
                                }],
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                            },
                        });
                    }

                    // 담당자별 판매 금액 KRW — horizontal bar + 평균 tooltip
                    const c2 = this.$refs.salesmanKrwCanvas;
                    if (c2) {
                        if (this.charts.salesmanKrw) this.charts.salesmanKrw.destroy();
                        this.charts.salesmanKrw = new Chart(c2, {
                            type: 'bar',
                            data: {
                                labels: s.labels,
                                datasets: [{
                                    label: '판매 금액 KRW',
                                    data: s.sale_total_krw,
                                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                                    maxBarThickness: 28,
                                }],
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true, maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => '합계 ₩ ' + ctx.parsed.x.toLocaleString(),
                                            afterLabel: (ctx) => {
                                                const avg = s.avg_per_vehicle[ctx.dataIndex] || 0;
                                                return '평균 ₩ ' + avg.toLocaleString();
                                            },
                                        },
                                    },
                                },
                                scales: { x: { beginAtZero: true, ticks: { callback: (v) => (v / 1e6).toFixed(0) + 'M' } } },
                            },
                        });
                    }
                },
            };
        }
    </script>
</div>
