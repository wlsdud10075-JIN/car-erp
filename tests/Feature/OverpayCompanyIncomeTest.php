<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💰 과입금 → 회사이익 전환 (jin 2026-10-16 «잡손실같이 떨구는 게 아니라 회사이익으로 전환 — B 맞아»).
 *
 * 잡손실과 같은 배관(잔금 감액 → 회수이력 한 줄, 미수 0, 바이어 크레딧 없음)이지만 **관리자 대시보드 회사 순이익에 더해진다**.
 * 잡손실은 종전대로 안 더한다. 월결산 알림톡·월정산 카드엔 안 들어간다(정산 단위).
 */
class OverpayCompanyIncomeTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    /** 바이어가 10,030 EUR 보냈고(수수료 명목 30 더) 잔금도 10,030 확정 → 미수 −30. 환율 1,500. */
    private function overpaidVehicle(): array
    {
        $sm = Salesman::create(['name' => 'S'.random_int(1000, 9999), 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'INCOME BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '88조8888', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-09-01', 'sale_price' => 10000,
        ]);
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10030, 'payment_date' => '2026-09-02', 'confirmed_at' => now()]);

        return [$v->fresh(), $buyer];
    }

    private function companyNet(User $admin): array
    {
        $this->actingAs($admin);

        return Volt::test('admin.dashboard')->set('dateFrom', now()->subDay()->toDateString())->set('dateTo', now()->addDay()->toDateString())->instance()->companyProfit;
    }

    public function test_company_income_zeroes_the_receivable_leaves_a_row_and_adds_to_dashboard_profit(): void
    {
        [$v, $buyer] = $this->overpaidVehicle();
        $this->assertSame(-30.0, (float) $v->sale_unpaid_amount, '전제 — 과입금 30');
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $before = $this->companyNet($admin);

        $c = Volt::actingAs($this->finance())->test('erp.receivables.index')->call('openPanel', $v->id);
        $this->assertStringContainsString('data-overpay-company-income', $c->html(), '과입금 상자에 세 번째 버튼');
        $c->call('convertOverpayToCompanyIncome');

        $v->refresh();
        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '미수가 0 이 안 됐다');
        $this->assertSame(0.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'), '회사이익인데 바이어 적립금이 생겼다');
        $row = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'company_income')->sole();
        $this->assertSame('30.00', (string) $row->amount);
        $this->assertContains('company_income', Vehicle::MIRRORED_RECEIVABLE_METHODS, '미수 제외 목록에 없으면 정리 직후 미수가 다시 음수가 된다');
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Vehicle::class, 'auditable_id' => $v->id, 'action' => 'overpay_converted_to_company_income', 'column_name' => 'company_income']);

        // 관리자 대시보드 회사 순이익 += 30 × 1,500 = 45,000 (전환일 기준 기간). 잡손실은 안 더한다.
        $after = $this->companyNet($admin);
        $this->assertSame(45_000, $after['overpay_income']);
        $this->assertSame($before['company_net'] + 45_000, $after['company_net'], '회사 순이익에 과입금 회사이익이 더해져야 한다');
        $html = Volt::test('admin.dashboard')->set('dateFrom', now()->subDay()->toDateString())->set('dateTo', now()->addDay()->toDateString())->html();
        $this->assertStringContainsString('data-overpay-income', $html);
        $this->assertStringContainsString('45,000', $html);
    }

    public function test_misc_loss_still_does_not_touch_dashboard_profit(): void
    {
        [$v] = $this->overpaidVehicle();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $before = $this->companyNet($admin);

        Volt::actingAs($this->finance())->test('erp.receivables.index')->set('selectedVehicleId', $v->id)->call('convertOverpayToMiscLoss');

        $after = $this->companyNet($admin);
        $this->assertSame(0, $after['overpay_income']);
        $this->assertSame($before['company_net'], $after['company_net'], '잡손실은 기록만 — 숫자 불변');
        $this->assertNotContains('company_income', ReceivableHistory::MANUAL_METHODS, '회사이익은 버튼 전용 — 손으로 고를 수 없다');
    }
}
