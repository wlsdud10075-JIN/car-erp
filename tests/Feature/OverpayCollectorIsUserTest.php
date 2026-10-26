<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 과입금 → 잡손실·회사이익 전환의 회수이력 `collector_id` 는 **users.id** 여야 한다 (2026-10-26 ssancarerp 실사고).
 * Salesman id 를 넣고 있어서, 명부 미러(10-14)로 Salesman 이 생긴 재무·관리 일반사용자는 FK 오류로 전환이 안 됐고
 * 업무관리자(Salesman 없음)만 됐다 — «관리는 안 되고 업무관리자는 된다».
 */
class OverpayCollectorIsUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');   // FK 를 켜야 운영(MySQL)과 같은 실패가 재현된다
    }

    private function overpaidVehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'OVER BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'OVR-1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'dhl_request' => false, 'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01', 'sale_price' => 10000, 'transport_fee' => 0,
        ]);
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 13000, 'payment_date' => '2026-05-02', 'confirmed_at' => now()]);

        return $v->fresh();
    }

    public function test_a_manager_role_user_with_a_salesman_mirror_can_convert_and_the_collector_is_the_user(): void
    {
        // Salesman id 가 users.id 와 어긋나게 — 운영에선 박수정 user 8 / salesman 73.
        for ($i = 0; $i < 5; $i++) {
            Salesman::create(['name' => '더미'.$i, 'type' => 'freelance']);
        }
        $user = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $mirror = Salesman::create(['name' => $user->name, 'type' => 'freelance', 'user_id' => $user->id]);
        $this->assertNotSame($user->id, $mirror->id, '픽스처: Salesman id ≠ user id 여야 버그가 드러난다');

        $v = $this->overpaidVehicle();
        $this->actingAs($user);
        Volt::test('erp.receivables.index')->set('selectedVehicleId', $v->id)->call('convertOverpayToCompanyIncome');

        $row = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'company_income')->first();
        $this->assertNotNull($row, '관리 일반사용자도 회사이익 전환이 된다');
        $this->assertSame($user->id, (int) $row->collector_id, '수집자는 users.id');
        $this->assertSame(0, (int) $v->fresh()->sale_unpaid_amount);
    }

    public function test_misc_loss_by_an_admin_without_salesman_records_the_user_too(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->overpaidVehicle();
        $this->actingAs($admin);
        Volt::test('erp.receivables.index')->set('selectedVehicleId', $v->id)->call('convertOverpayToMiscLoss');

        $this->assertSame($admin->id, (int) ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'misc_loss')->value('collector_id'));
    }
}
