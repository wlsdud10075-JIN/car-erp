<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량관리 → 「선적요청으로 묶기」(ERP 자체 묶음) + 누적검색 + 포워딩사 [관리] 열람 (jin 2026-07-08).
 * board 지연 대비 — 관리/통관이 차량을 골라 선적요청 묶음을 직접 만들고, 이후는 board 발과 동일 파이프라인.
 */
class ErpShippingBundleTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function salesUserWithSalesman(): array
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $salesman = Salesman::create(['name' => '내영업', 'user_id' => $user->id, 'is_active' => true, 'type' => 'employee']);

        return [$user, $salesman];
    }

    public function test_clearance_user_bundles_selected_export_vehicles_into_one_requested_batch(): void
    {
        $buyer = Buyer::create(['name' => '바이어A', 'is_active' => true]);
        $v1 = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'export_buyer_id' => $buyer->id, 'shipping_method' => 'RORO']);
        $v2 = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'export_buyer_id' => $buyer->id, 'shipping_method' => 'CONTAINER']);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v1->id, $v2->id])
            ->call('bundleToShipping')
            ->assertRedirect();

        $rows = ShippingRequest::all();
        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->pluck('batch_id')->unique(), '한 batch 로 묶여야');
        $this->assertTrue($rows->every(fn ($r) => $r->status === ShippingRequest::STATUS_REQUESTED));
        // 각 차량 export 값 그대로 복사
        $this->assertSame('RORO', ShippingRequest::where('vehicle_id', $v1->id)->value('shipping_method'));
        $this->assertSame('CONTAINER', ShippingRequest::where('vehicle_id', $v2->id)->value('shipping_method'));
        $this->assertSame($buyer->id, (int) ShippingRequest::where('vehicle_id', $v1->id)->value('buyer_id'));
        // 선적요청 알람(수출통관) 발생
        $this->assertSame(2, TaskAlarm::where('type', 'shipping_requested')->whereNull('resolved_at')->count());
    }

    public function test_bundle_skips_non_export_and_already_open_vehicles(): void
    {
        $export = Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export']);
        $heyman = Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'heyman']);
        $alreadyOpen = Vehicle::create(['vehicle_number' => '55마5555', 'sales_channel' => 'export']);
        ShippingRequest::create([
            'batch_id' => 'existing', 'vehicle_id' => $alreadyOpen->id, 'shipping_method' => 'RORO',
            'requested_by_email' => 'x@a.com', 'status' => ShippingRequest::STATUS_IN_PROGRESS, 'requested_at' => now(),
        ]);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$export->id, $heyman->id, $alreadyOpen->id])
            ->call('bundleToShipping')
            ->assertRedirect();

        // 신규 묶음엔 export 차 1대만 (heyman·이미진행중 제외)
        $newBatch = ShippingRequest::where('batch_id', '!=', 'existing')->get();
        $this->assertCount(1, $newBatch);
        $this->assertSame($export->id, $newBatch->first()->vehicle_id);
    }

    public function test_bundle_with_only_ineligible_vehicles_creates_nothing_and_toasts(): void
    {
        $heyman = Vehicle::create(['vehicle_number' => '66바6666', 'sales_channel' => 'heyman']);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$heyman->id])
            ->call('bundleToShipping')
            ->assertDispatched('notify');

        $this->assertSame(0, ShippingRequest::count());
    }

    public function test_sales_user_cannot_bundle(): void
    {
        [$user, $salesman] = $this->salesUserWithSalesman();
        $v = Vehicle::create(['vehicle_number' => '77사7777', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);

        $this->actingAs($user);

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v->id])
            ->call('bundleToShipping')
            ->assertForbidden();

        $this->assertSame(0, ShippingRequest::count());
    }

    public function test_accumulation_add_scopes_to_own_salesman(): void
    {
        [$user, $salesman] = $this->salesUserWithSalesman();
        $mine = Vehicle::create(['vehicle_number' => '88아8888', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);

        $other = Salesman::create(['name' => '남영업', 'is_active' => true, 'type' => 'employee']);
        $theirs = Vehicle::create(['vehicle_number' => '99자9999', 'sales_channel' => 'export', 'salesman_id' => $other->id]);

        $this->actingAs($user);

        // 남의 차 → 스코프 밖, 누적 안 됨
        Volt::test('erp.vehicles.index')
            ->set('accumSearchTerm', '99자9999')
            ->call('addToAccumulation')
            ->assertSet('shipDocIds', [])
            ->assertDispatched('notify')
            // 내 차 → 누적됨
            ->set('accumSearchTerm', '88아8888')
            ->call('addToAccumulation')
            ->assertSet('shipDocIds', [$mine->id]);
    }

    public function test_accumulation_matches_by_vin(): void
    {
        $sm = Salesman::create(['name' => '김영업', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create([
            'vehicle_number' => '12가3456', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'KMHAB00CDEF123456',
        ]);

        $this->actingAs($this->clearanceUser());

        // 차대번호 끝 6자리로 검색해도 누적됨
        Volt::test('erp.vehicles.index')
            ->set('accumSearchTerm', '123456')
            ->call('addToAccumulation')
            ->assertSet('shipDocIds', [$v->id]);
    }

    public function test_date_type_balance_filters_by_final_payment_date(): void
    {
        $buyer = Buyer::create(['name' => '바이어', 'is_active' => true]);
        $paid = Vehicle::create([
            'vehicle_number' => '77바7777', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'sale_price' => 10_000_000, 'sale_date' => '2026-07-01', 'buyer_id' => $buyer->id,
        ]);
        $paid->finalPayments()->create(['amount' => 5_000_000, 'type' => 'balance', 'payment_date' => '2026-07-05', 'confirmed_at' => now()]);

        $june = Vehicle::create([
            'vehicle_number' => '55다5555', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'sale_price' => 8_000_000, 'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id,
        ]);
        $june->finalPayments()->create(['amount' => 3_000_000, 'type' => 'balance', 'payment_date' => '2026-06-10', 'confirmed_at' => now()]);

        $this->actingAs($this->clearanceUser());

        // 잔금입금 모드 + 7월 선택 → 7월에 잔금 입금된 차량만
        Volt::test('erp.vehicles.index')
            ->set('dateType', 'balance')
            ->set('dateFrom', '2026-07-01')
            ->set('dateTo', '2026-07-31')
            ->assertSee('77바7777')
            ->assertDontSee('55다5555');
    }

    public function test_manager_role_can_open_forwarding_but_sales_forbidden(): void
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        $this->actingAs($manager)->get('/erp/forwarding-companies')->assertOk();
        $this->actingAs($sales)->get('/erp/forwarding-companies')->assertForbidden();
    }
}
