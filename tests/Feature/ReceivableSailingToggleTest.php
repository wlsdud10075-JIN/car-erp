<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 🚢 운항중 / ⚓ 도착예정 토글 (jin 2026-10-26) — 차량관리와 같은 판정(Vehicle::scopeSailing).
 * 알바니아 RORO 처럼 미수 100% 로 실린 차를 「선적사 RORO 리스트를 넣은 날」 바로 보려는 것.
 */
class ReceivableSailingToggleTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    private function sold(array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'SAIL-'.++$this->counter, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-01-01', 'purchase_price' => 100,
            'sale_date' => '2026-01-10', 'sale_price' => 1_000_000, 'bl_loading_location' => 'PUSAN',
        ], $extra));
        $v->refreshCaches();

        return $v->refresh();
    }

    private function ids($c): array
    {
        return $c->instance()->vehicles->pluck('id')->values()->all();
    }

    public function test_toggle_narrows_to_in_transit_or_arrived_and_combines_with_buyer_filter(): void
    {
        $b1 = Buyer::create(['name' => 'MCK', 'is_active' => true]);
        $b2 = Buyer::create(['name' => 'TEVI', 'is_active' => true]);
        $transit = $this->sold(['buyer_id' => $b1->id, 'shipping_date' => now()->subDays(5), 'eta_date' => now()->addDays(10)]);
        $arrived = $this->sold(['buyer_id' => $b1->id, 'shipping_date' => now()->subDays(30), 'eta_date' => now()->subDays(3)]);
        $arrivedOther = $this->sold(['buyer_id' => $b2->id, 'shipping_date' => now()->subDays(30), 'eta_date' => now()->subDay()]);
        $notSailed = $this->sold(['buyer_id' => $b1->id]);

        $c = Volt::test('erp.receivables.index');
        $this->assertCount(4, $this->ids($c));
        $counts = $c->instance()->sailingCounts;
        $this->assertSame(1, $counts['in_transit']['count']);
        $this->assertSame(2, $counts['arrived']['count']);
        $this->assertSame(2_000_000, $counts['arrived']['unpaid_krw']);

        $c->call('toggleSailing', 'in_transit');
        $this->assertSame('in_transit', $c->get('sailingFilter'));
        $this->assertSame([$transit->id], $this->ids($c));

        $c->call('toggleSailing', 'arrived');
        $this->assertEqualsCanonicalizing([$arrived->id, $arrivedOther->id], $this->ids($c));

        $c->set('buyerFilter', (string) $b1->id);
        $this->assertSame([$arrived->id], $this->ids($c), '바이어 필터와 AND');

        $c->call('toggleSailing', 'arrived');
        $this->assertSame('', $c->get('sailingFilter'), '같은 걸 다시 누르면 해제');
        $this->assertEqualsCanonicalizing([$transit->id, $arrived->id, $notSailed->id], $this->ids($c));
    }

    public function test_kpi_follows_the_toggle_and_unknown_phase_is_ignored(): void
    {
        $this->sold(['shipping_date' => now()->subDays(5), 'eta_date' => now()->addDays(10), 'sale_price' => 300]);
        $this->sold(['sale_price' => 700]);

        $c = Volt::test('erp.receivables.index');
        $this->assertSame(1000, $c->instance()->summary['total_unpaid_krw']);

        $c->call('toggleSailing', 'in_transit');
        $this->assertSame(300, $c->instance()->summary['total_unpaid_krw'], 'KPI 도 다른 필터처럼 따라간다');

        $c->call('toggleSailing', 'bogus');
        $this->assertSame('in_transit', $c->get('sailingFilter'));
    }

    public function test_receivable_screen_does_not_copy_the_sailing_condition(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/receivables/index.blade.php'));
        $this->assertStringContainsString('->sailing($this->sailingFilter)', $src);
        $this->assertDoesNotMatchRegularExpression('/eta_date.{0,40}(>|<=).{0,40}endOfToday/', $src, '운항 판정을 옮겨 적지 말 것 — Vehicle::scopeSailing 단일 출처');
    }
}
