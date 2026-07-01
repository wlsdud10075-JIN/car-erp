<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 면허비 묶음 n/1 (선적요청 화면 「2차 비용」 탭) — 총액을 묶음 차량 수로 나눠(첫 차량에 나머지) cost_license 기입.
 */
class CostLicenseBundleTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeBundle(int $count): array
    {
        $salesman = Salesman::create(['name' => 'S'.++$this->counter, 'is_active' => true]);
        $vehicles = [];
        foreach (range(1, $count) as $i) {
            $v = Vehicle::create([
                'vehicle_number' => 'LIC-'.$this->counter.'-'.$i,
                'sales_channel' => 'export',
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'salesman_id' => $salesman->id,
                'purchase_price' => 1000000,
                'cost_license' => 11000,
                'dhl_request' => false,
            ]);
            // 시드 데이터 — paid 전환 가드(canApprove) 우회 위해 이벤트 없이 생성.
            Settlement::withoutEvents(fn () => Settlement::create([
                'vehicle_id' => $v->id,
                'salesman_id' => $salesman->id,
                'settlement_type' => 'ratio',
                'settlement_status' => 'paid',
                'secondary_status' => 'pending',
                'paid_at' => now(),
            ]));
            ShippingRequest::create([
                'batch_id' => 'BATCH-'.$this->counter,
                'vehicle_id' => $v->id,
                'shipping_method' => 'RORO',
                'requested_by_email' => 'x@a.com',
                'status' => 'done',
                'requested_at' => now(),
            ]);
            $vehicles[] = $v;
        }

        return [$salesman, $vehicles, 'BATCH-'.$this->counter];
    }

    public function test_license_n1_split_sums_exactly_with_remainder_on_first(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        [$s, $vehicles, $batch] = $this->makeBundle(3);

        Volt::test('erp.shipping-requests.index')
            ->call('setViewTab', 'cost')
            ->call('openLicenseFee', $batch)
            ->set('licenseTotal', '200000')
            ->call('applyLicenseFee')
            ->assertSet('licenseBatch', '');

        $fees = collect($vehicles)->map(fn ($v) => (int) $v->fresh()->cost_license);
        // 200,000 / 3 = 66,666 + 나머지 2 → 첫 차량 66,668, 나머지 66,666
        $this->assertSame(200000, $fees->sum());
        $this->assertSame(1, $fees->filter(fn ($f) => $f === 66668)->count());
        $this->assertSame(2, $fees->filter(fn ($f) => $f === 66666)->count());
    }

    public function test_cost_tab_only_lists_secondary_pending_bundles(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        [$s, $vehicles, $batch] = $this->makeBundle(2);

        // 2차 pending 묶음 → 탭에 멤버 차량번호 노출.
        Volt::test('erp.shipping-requests.index')
            ->call('setViewTab', 'cost')
            ->assertSee($vehicles[0]->vehicle_number);
    }

    public function test_non_approver_cannot_apply_license_fee(): void
    {
        // 수출통관은 화면(clearance)엔 들어오지만 canApprove 아니라 openLicenseFee 403.
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $this->actingAs($clearance);
        [$s, $vehicles, $batch] = $this->makeBundle(2);

        Volt::test('erp.shipping-requests.index')
            ->call('openLicenseFee', $batch)
            ->assertStatus(403);
    }
}
