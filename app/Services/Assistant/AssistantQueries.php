<?php

namespace App\Services\Assistant;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\CapitalStatusService;
use Illuminate\Support\Facades\DB;

/**
 * 사내 업무 도우미 — B단계 검증된 조회 함수 (jin 2026-07-24).
 *
 * ⭐ 불변식: LLM 은 여기 함수를 "고르기만" 하고, 숫자는 이 클래스가 DB 에서 직접 계산해 반환.
 *   LLM 이 SQL 을 짜거나 숫자를 재기술하지 않는다.
 * ⭐ 진실 정합: 미수는 대시보드 receivableKpis 와 동일 소스
 *   (`sale_unpaid_amount_krw_cache` + `excludeReceivableGrace`/`onlyReceivableGrace`, 출고일 pivot).
 *   → 챗봇 숫자와 대시보드 숫자가 구조적으로 일치.
 * 권한 게이트는 AssistantService 가 라우팅 단계에서 건다(capital/profit=canViewCapital).
 */
class AssistantQueries
{
    /** 인원별 미수 현황 (grace 제외, 미수 내림차순). */
    public function receivableBySalesman(int $limit = 10): array
    {
        $rows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->excludeReceivableGrace()
            ->whereNotNull('salesman_id')
            ->select('salesman_id',
                DB::raw('SUM(sale_unpaid_amount_krw_cache) as unpaid'),
                DB::raw('COUNT(*) as cnt'))
            ->groupBy('salesman_id')
            ->orderByDesc('unpaid')
            ->limit($limit)
            ->get();

        $names = Salesman::whereIn('id', $rows->pluck('salesman_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->salesman_id] ?? "#{$r->salesman_id}",
            'unpaid' => (int) $r->unpaid,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /** 바이어별 미수 현황 (grace 제외, 미수 내림차순). */
    public function receivableByBuyer(int $limit = 10): array
    {
        $rows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->excludeReceivableGrace()
            ->whereNotNull('buyer_id')
            ->select('buyer_id',
                DB::raw('SUM(sale_unpaid_amount_krw_cache) as unpaid'),
                DB::raw('COUNT(*) as cnt'))
            ->groupBy('buyer_id')
            ->orderByDesc('unpaid')
            ->limit($limit)
            ->get();

        $names = Buyer::whereIn('id', $rows->pluck('buyer_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->buyer_id] ?? "#{$r->buyer_id}",
            'unpaid' => (int) $r->unpaid,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * 인원별 매출 현황.
     * 관리자 대시보드 salesmanPerformance와 같은 판매일·환율·매입취소 제외 기준을 사용한다.
     *
     * @param  array<int>|null  $salesmanIds  null = 전체(최고관리자·업무관리자) / 배열 = [관리] 담당 영업만
     */
    public function salesBySalesman(string $dateFrom, string $dateTo, ?array $salesmanIds = null, int $limit = 10): array
    {
        if ($salesmanIds !== null && ! $salesmanIds) {
            return [];   // 담당 영업 0명 — whereIn([]) 로 전량 스캔하지 않는다
        }
        $aggregates = [];

        Vehicle::query()
            ->when($salesmanIds !== null, fn ($q) => $q->whereIn('salesman_id', $salesmanIds))
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereNotNull('salesman_id')
            ->where('sale_price', '>', 0)
            ->where('cancel_status', Vehicle::CANCEL_NONE)
            ->select('id', 'salesman_id', 'sale_price', 'currency', 'exchange_rate')
            ->orderBy('id')   // chunk 은 limit/offset — 정렬이 없으면 1000건 초과 시 행 누락·중복이 난다
            ->chunk(1000, function ($rows) use (&$aggregates) {
                foreach ($rows as $vehicle) {
                    $krw = $vehicle->currency === 'KRW'
                        ? (int) $vehicle->sale_price
                        : ($vehicle->exchange_rate > 0 ? (int) ($vehicle->sale_price * $vehicle->exchange_rate) : 0);
                    $id = $vehicle->salesman_id;
                    if (! isset($aggregates[$id])) {
                        $aggregates[$id] = ['count' => 0, 'sales_krw' => 0];
                    }
                    $aggregates[$id]['count']++;
                    $aggregates[$id]['sales_krw'] += $krw;
                }
            });

        uasort($aggregates, fn ($a, $b) => $b['sales_krw'] <=> $a['sales_krw']);
        $topIds = array_slice(array_keys($aggregates), 0, $limit);
        $names = Salesman::whereIn('id', $topIds)->pluck('name', 'id');

        return collect($topIds)->map(fn ($id) => [
            'name' => $names[$id] ?? "#{$id}",
            'sales_krw' => $aggregates[$id]['sales_krw'],
            'count' => $aggregates[$id]['count'],
        ])->all();
    }

    /** 채권관리 요약 — 총 미수 + 선적전/후 분류(출고일 pivot) + 결제대기(grace). */
    public function receivableSummary(): array
    {
        $active = Vehicle::query()
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->excludeReceivableGrace();

        $total = (int) (clone $active)->sum('sale_unpaid_amount_krw_cache');
        $before = (clone $active)->whereNull('warehouse_out_date')
            ->selectRaw('COUNT(*) as cnt, SUM(sale_unpaid_amount_krw_cache) as amt')->first();
        $after = (clone $active)->whereNotNull('warehouse_out_date')
            ->selectRaw('COUNT(*) as cnt, SUM(sale_unpaid_amount_krw_cache) as amt')->first();
        $grace = Vehicle::query()
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->onlyReceivableGrace()
            ->selectRaw('COUNT(*) as cnt, SUM(sale_unpaid_amount_krw_cache) as amt')->first();

        return [
            'total_unpaid' => $total,
            'before_shipping' => ['count' => (int) ($before->cnt ?? 0), 'unpaid' => (int) ($before->amt ?? 0)],
            'after_shipping' => ['count' => (int) ($after->cnt ?? 0), 'unpaid' => (int) ($after->amt ?? 0)],
            'grace' => ['count' => (int) ($grace->cnt ?? 0), 'unpaid' => (int) ($grace->amt ?? 0)],
        ];
    }

    /** 자금 현황 — 통장현금·굴리는자금·손익 (super/admin 심화, CapitalStatusService 재사용). */
    public function capitalStatus(): array
    {
        $svc = app(CapitalStatusService::class);

        return $svc->derive($svc->latest());
    }
}
