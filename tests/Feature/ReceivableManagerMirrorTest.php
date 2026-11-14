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
 * 👤 채권담당자 = 따로 지정 안 하면 영업담당자 (jin 2026-11-14 «미러로, 다를 때만 수동»).
 * DB 에는 안 쓴다 — 담당 영업을 바꾸면 따라간다. 실측 11-14: 3사 미수 차 498대 중 지정 0대.
 */
class ReceivableManagerMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function unpaidVehicle(Salesman $sm): Vehicle
    {
        $buyer = Buyer::create(['name' => 'MIRROR BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '55가5555', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 5_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 9_000_000, 'sale_date' => '2026-10-02',
        ]);
        $v->refresh()->refreshCaches();

        return $v->fresh();
    }

    public function test_salesman_is_the_receivable_manager_until_someone_else_is_assigned(): void
    {
        $sm = Salesman::create(['name' => '미러영업', 'type' => 'employee', 'is_active' => true]);
        $v = $this->unpaidVehicle($sm);
        $this->assertSame('미러영업', $v->load('receivableManager', 'salesman')->receivable_manager_name);

        $other = User::factory()->create(['name' => '따로담당', 'permission' => 'user', 'role' => '재무']);
        $v->update(['receivable_manager_id' => $other->id]);
        $this->assertSame('따로담당', $v->fresh()->load('receivableManager', 'salesman')->receivable_manager_name);
    }

    public function test_receivables_screen_shows_the_salesman_and_the_panel_says_so(): void
    {
        $sm = Salesman::create(['name' => '미러영업', 'type' => 'employee', 'is_active' => true]);
        $v = $this->unpaidVehicle($sm);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));

        $c = Volt::test('erp.receivables.index')->call('applySort', 'manager');   // 정렬식 SQL 이 깨지지 않는지
        $c->assertSee('미러영업');
        $c->call('openPanel', $v->id)->assertSee('영업담당자와 같게 (미러영업)');
    }
}
