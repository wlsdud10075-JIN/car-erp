<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔀 누적검색 재설계 (jin 2026-10-21) — 검색할 때마다 찾은 차가 **목록에 쌓인다**.
 *   «1번 차량 검색 후 2번 차량 검색, 3번 차량 검색 하면 그 검색한 차량 3개가 나타나는 걸 원한 거거든»
 *   - 쌓이는 곳 = 메인 목록(`ids`) · 여러 대 걸리면 전부 · 일괄 작업 대상은 체크해야(jin).
 * + 목록 합계에 운임비 USD(기록용) 합 · 미수금 합(통화별, 남은 미수만).
 */
class VehicleAccumSearchStackTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private ?Salesman $sm = null;

    private function car(string $no, array $over = []): Vehicle
    {
        $this->sm ??= Salesman::create(['name' => '김영업', 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create(array_merge(['vehicle_number' => $no, 'sales_channel' => 'export', 'salesman_id' => $this->sm->id], $over));
    }

    public function test_three_searches_stack_three_vehicles_in_the_list(): void
    {
        $a = $this->car('11가1111');
        $b = $this->car('22나2222');
        $c = $this->car('33다3333');
        $this->car('44라4444');
        $this->actingAs($this->clearanceUser());

        $t = Volt::test('erp.vehicles.index');
        foreach (['11가1111', '22나2222', '33다3333'] as $term) {
            $t->set('accumSearchTerm', $term)->call('searchAccum');
        }

        $t->assertSet('ids', implode(',', [$a->id, $b->id, $c->id]))
            ->assertSet('search', '')
            ->assertSet('accumSearchTerm', '')
            ->assertSet('shipDocIds', []);   // 체크해야 일괄 작업 대상 (jin)
        $this->assertSame([$a->id, $b->id, $c->id], $t->get('vehicles')->pluck('id')->sort()->values()->all());
        $this->assertStringContainsString(__('vehicle.accum.pill', ['count' => 3]), $t->html(), '접힌 드로어에도 쌓인 대수');
        $html = $t->call('toggleAccumSearch')->html();
        $this->assertStringContainsString('data-accum-list', $html);
        $this->assertStringContainsString(__('vehicle.accum.list', ['count' => 3]), $html);
        $this->assertStringNotContainsString('44라4444', $html);
    }

    public function test_a_term_matching_several_adds_all_and_duplicates_are_not_added_twice(): void
    {
        $a = $this->car('11가1234');
        $b = $this->car('22나1234');
        $this->actingAs($this->clearanceUser());

        $t = Volt::test('erp.vehicles.index')
            ->set('accumSearchTerm', '11가1234')->call('searchAccum')
            ->set('accumSearchTerm', '1234')->call('searchAccum');   // 2대 걸림, 1대는 이미 쌓임

        $t->assertSet('ids', $a->id.','.$b->id)
            ->assertDispatched('notify', type: 'success');
        $t->set('accumSearchTerm', '1234')->call('searchAccum')
            ->assertSet('ids', $a->id.','.$b->id)
            ->assertDispatched('notify', type: 'warning');
    }

    public function test_no_match_keeps_the_stack_and_remove_and_clear_work(): void
    {
        $a = $this->car('11가1111');
        $b = $this->car('22나2222');
        $this->actingAs($this->clearanceUser());

        $t = Volt::test('erp.vehicles.index')
            ->set('accumSearchTerm', '11가1111')->call('searchAccum')
            ->set('accumSearchTerm', '22나2222')->call('searchAccum')
            ->set('accumSearchTerm', '없는차999')->call('searchAccum')
            ->assertSet('ids', $a->id.','.$b->id)
            ->assertDispatched('notify', type: 'error');

        $t->call('removeAccumVehicle', $a->id)->assertSet('ids', (string) $b->id);
        $t->call('clearAccum')->assertSet('ids', '');
        $this->assertSame(2, $t->get('vehicles')->total(), '비우면 목록이 평소대로');
    }

    public function test_sales_user_cannot_stack_or_see_other_salesmens_cars(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $mine = Salesman::create(['name' => '내영업', 'user_id' => $user->id, 'is_active' => true, 'type' => 'employee']);
        $own = $this->car('11가1111', ['salesman_id' => $mine->id]);
        $other = $this->car('99하9999');
        $this->actingAs($user);

        Volt::test('erp.vehicles.index')
            ->set('accumSearchTerm', '99하9999')->call('searchAccum')
            ->assertSet('ids', '');

        // URL 로 남의 차 id 가 와도 칩에 번호가 안 그려진다
        $t = Volt::test('erp.vehicles.index', ['ids' => $own->id.','.$other->id]);
        $this->assertSame([$own->id], $t->get('accumVehicles')->pluck('id')->all());
        $this->assertStringNotContainsString('99하9999', $t->html());
    }

    public function test_export_mirrors_the_stacked_ids(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $a = $this->car('11가1111');
        $this->car('22나2222');

        $this->actingAs($admin)->get(route('erp.vehicles.export', ['scope' => 'current', 'ids' => (string) $a->id]))->assertOk();
        $this->assertDatabaseHas('export_logs', ['user_id' => $admin->id, 'target' => 'vehicles', 'row_count' => 1]);
    }

    public function test_totals_show_usd_memo_freight_and_remaining_unpaid_per_currency(): void
    {
        $this->actingAs($this->clearanceUser());
        $base = ['sale_date' => '2026-09-01', 'buyer_id' => Buyer::create(['name' => 'B1', 'is_active' => true])->id];
        $usd = $this->car('11가1111', $base + ['currency' => 'USD', 'exchange_rate' => 1400, 'sale_price' => 10_000, 'transport_fee_usd' => 1_200]);
        FinalPayment::create(['vehicle_id' => $usd->id, 'type' => 'balance', 'amount' => 3_000, 'payment_date' => '2026-10-01', 'confirmed_at' => now()]);
        $over = $this->car('22나2222', $base + ['currency' => 'USD', 'exchange_rate' => 1400, 'sale_price' => 1_000, 'transport_fee_usd' => 800]);
        FinalPayment::create(['vehicle_id' => $over->id, 'type' => 'balance', 'amount' => 1_500, 'payment_date' => '2026-10-01', 'confirmed_at' => now()]);   // 과입금 −500
        $this->car('33다3333', $base + ['currency' => 'EUR', 'exchange_rate' => 1500, 'sale_price' => 2_000]);
        foreach ([$usd, $over] as $v) {
            $v->refresh()->refreshCaches();
        }

        $t = Volt::test('erp.vehicles.index');
        $byCur = collect($t->get('freightTotalsByCurrency'))->keyBy('currency');
        $this->assertSame(7_000, $byCur['USD']['unpaid'], '과입금 −500 은 빼지 않는다 — 남은 미수만');
        $this->assertSame(2_000, $byCur['EUR']['unpaid']);
        $this->assertSame(2_000, $t->get('freightTotals')['freight_usd_sum'], 'USD 기록용 운임비는 통화와 무관한 한 합계');

        $html = $t->html();
        $this->assertStringContainsString('data-stat-unpaid', $html);
        $this->assertStringContainsString('USD 7,000', $html);
        $this->assertStringContainsString('EUR 2,000', $html);
        $this->assertStringContainsString('USD 2,000', $html);
    }
}
