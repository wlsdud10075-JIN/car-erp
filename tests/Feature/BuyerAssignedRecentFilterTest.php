<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🆕 차량관리 「바이어 지정됨 · 최근 5일」 필터 (jin 2026-11-18).
 *
 * 「바이어 미정」 차에 바이어를 넣으면 저장 훅이 미정 플래그를 조용히 내려 필터에서 그냥 사라졌다 → 실무자가 어느 차인지 몰랐다.
 * 감사로그(buyer_undecided 1→0)를 읽어 최근 5일 안에 바이어가 정해진 차를 모은다.
 */
class BuyerAssignedRecentFilterTest extends TestCase
{
    use RefreshDatabase;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = Buyer::create(['name' => 'NEW BUYER', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]));
    }

    private function undecided(string $no): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'S'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
            'buyer_undecided' => true,
        ]);
    }

    public function test_assigning_a_buyer_puts_the_vehicle_in_the_recent_filter(): void
    {
        $assigned = $this->undecided('11가1111');
        $still = $this->undecided('22나2222');
        $assigned->update(['buyer_id' => $this->buyer->id]);   // 저장 훅이 미정을 내리고 감사로그 1→0 이 남는다

        $this->assertFalse((bool) $assigned->fresh()->buyer_undecided);
        $ids = Vehicle::query()->buyerAssignedRecently()->pluck('id')->all();
        $this->assertSame([$assigned->id], $ids);
        $this->assertNotContains($still->id, $ids);
    }

    public function test_older_than_five_days_drops_out(): void
    {
        $this->travelTo(now()->subDays(6));
        $v = $this->undecided('33다3333');
        $v->update(['buyer_id' => $this->buyer->id]);
        $this->travelBack();

        $this->assertSame(0, Vehicle::query()->buyerAssignedRecently()->count());
    }

    public function test_set_back_to_undecided_is_excluded(): void
    {
        $v = $this->undecided('44라4444');
        $v->update(['buyer_id' => $this->buyer->id]);
        $v->update(['buyer_id' => null, 'buyer_undecided' => true]);

        $this->assertSame(0, Vehicle::query()->buyerAssignedRecently()->count());
    }

    public function test_list_pill_shows_count_and_filters(): void
    {
        $assigned = $this->undecided('55마5555');
        $this->undecided('66바6666');
        $assigned->update(['buyer_id' => $this->buyer->id]);

        Volt::test('erp.vehicles.index')
            ->assertSeeHtml('data-filter-buyer-assigned')
            ->assertSee('바이어지정 (1)')
            ->call('toggleTask', 'buyer_assigned')
            ->assertSee('55마5555')
            ->assertDontSee('66바6666');
    }
}
