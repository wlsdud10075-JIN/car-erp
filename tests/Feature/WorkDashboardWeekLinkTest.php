<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\Dashboard\WorkDashboardData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔗 업무 대시보드 수출통관 「이번 주 배」 → 차량관리 (jin 2026-11-18 «금 20일 선적 50대 MV YOUNG SHIN V.2611 누르면 그 50대»).
 * 항목이 센 차량 id 를 그대로 넘긴다 — 이름이 겹치는 배(V.2611A)·취소 차가 섞이지 않고 대수가 같다.
 */
class WorkDashboardWeekLinkTest extends TestCase
{
    use RefreshDatabase;

    private function v(string $no, string $ship, ?string $vsl, array $extra = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'S'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'shipping_date' => $ship, 'vessel_name' => $vsl,
        ], $extra));
    }

    public function test_each_ship_entry_carries_exactly_the_counted_ids(): void
    {
        $monday = CarbonImmutable::parse('2026-11-16');
        $a = $this->v('11가1111', '2026-11-20', 'MV YOUNG SHIN V.2611');
        $b = $this->v('22나2222', '2026-11-20', 'MV YOUNG SHIN V.2611');
        $other = $this->v('33다3333', '2026-11-20', 'MV YOUNG SHIN V.2611A');   // 이름이 겹치는 다른 배
        $this->v('44라4444', '2026-11-20', 'MV YOUNG SHIN V.2611', ['cancel_status' => Vehicle::CANCEL_CLOSED]);   // 취소 차는 안 센다
        $eta = $this->v('55마5555', '2026-11-10', 'X', ['eta_date' => '2026-11-21']);

        $week = app(WorkDashboardData::class)->weekCalendar(null, $monday);
        $fri = collect($week['days'])->first(fn ($d) => $d['date']->toDateString() === '2026-11-20');
        $ship = collect($fri['ships'])->firstWhere('vessel', 'MV YOUNG SHIN V.2611');

        $this->assertSame(2, $ship['count']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ship['ids']);
        $this->assertSame([$other->id], collect($fri['ships'])->firstWhere('vessel', 'MV YOUNG SHIN V.2611A')['ids']);

        $sat = collect($week['days'])->first(fn ($d) => $d['date']->toDateString() === '2026-11-21');
        $this->assertSame(1, $sat['eta']);
        $this->assertSame([$eta->id], $sat['eta_ids']);
        $this->assertSame(3, $week['ship_total']);
    }
}
