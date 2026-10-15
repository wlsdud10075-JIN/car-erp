<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💳 판매탭 「적립금 사용」 = 이번에 쓸 금액 1회성 (jin 2026-10-15 «100 쓰고 빈칸, 300 쓰고 FIFO 로 누적 — 보통 이런 씬»).
 *
 * - 저장하면 누적(vehicles.savings_used)에 더하고 칸은 비워진다. 아래에 합계·사용 이력(USED/REFUND).
 * - 잔액 초과는 저장 거부(채권관리·바이어 드로어와 같은 규칙 — 판매탭만 빠져 있던 구멍).
 * - 사용 한 건 되돌리기 = REFUND 행(original_transaction_id) + 미수 복귀.
 * - 「입금 외 정리」 줄 = 채권관리에서 돈 없이 턴 것 + 적립금 사용. 잔금 행에 안 섞는다.
 */
class SavingsUseOnceTest extends TestCase
{
    use RefreshDatabase;

    private function soldVehicle(float $earned = 1_000): array
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'SAVINGS BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '41소7691', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1642,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 12_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => 3_040, 'sale_date' => '2026-10-01',
        ]);
        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'amount' => 12_000_000, 'payment_date' => '2026-09-01', 'confirmed_at' => now()]);
        if ($earned > 0) {
            $v->syncSavingsDeposit($earned);
        }
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $admin, $buyer];
    }

    private function panel(Vehicle $v)
    {
        return Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
    }

    public function test_the_field_is_blank_on_open_and_adds_to_the_running_total(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        $c = $this->panel($v);
        $this->assertSame('', $c->get('savings_used_str'), '누적값을 칸에 다시 보여주지 않는다');
        $this->assertSame(0.0, $c->get('panelSavingsUsedTotal'));

        $c->set('savings_used_str', '100')->call('save')->assertHasNoErrors();
        $this->assertSame(100.0, (float) $v->fresh()->savings_used);
        $this->assertSame('', $c->get('savings_used_str'), '저장하면 칸이 비워진다');
        $this->assertSame(100.0, $c->get('panelSavingsUsedTotal'));

        // 300 을 더 쓴다 — 400 으로 고쳐 쓰는 게 아니라 300 을 친다
        $this->panel($v)->set('savings_used_str', '300')->call('save')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame(400.0, (float) $v->savings_used);
        $this->assertSame(2_640.0, (float) $v->sale_unpaid_amount, '3,040 − 400');
        $this->assertSame(['USED', 'USED'], SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', '!=', 'EARNED')->orderBy('id')->pluck('transaction_type')->all(), '원장엔 사용 두 줄(FIFO 누적)');
        $this->assertSame(600.0, (float) SavingsStatus::where('buyer_id', $v->buyer_id)->orderByDesc('id')->value('balance'));

        // 빈 칸으로 저장(판매탭 다른 칸만 고침) — 누적은 그대로
        $this->panel($v)->set('transport_fee_str', '0')->call('save');
        $this->assertSame(400.0, (float) $v->fresh()->savings_used, '빈 칸 저장이 누적을 0 으로 덮지 않는다');
    }

    public function test_using_more_than_the_balance_is_refused(): void
    {
        [$v, $admin] = $this->soldVehicle(500);
        $this->actingAs($admin);

        $c = $this->panel($v)->set('savings_used_str', '600')->call('save');
        $c->assertHasErrors('savings_used_str');
        $this->assertStringContainsString('잔액', $c->errors()->first('savings_used_str'));
        $this->assertSame(0.0, (float) $v->fresh()->savings_used, '저장 자체가 안 된다');
        $this->assertSame(0, SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', 'USED')->count());

        $this->panel($v)->set('savings_used_str', '500')->call('save')->assertHasNoErrors();
        $this->assertSame(500.0, (float) $v->fresh()->savings_used, '잔액까지는 된다');
    }

    public function test_reverting_one_use_restores_the_balance_and_the_unpaid(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);
        $this->panel($v)->set('savings_used_str', '878')->call('save')->assertHasNoErrors();
        $used = SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', 'USED')->first();
        $this->assertSame(2_162.0, (float) $v->fresh()->sale_unpaid_amount);

        $c = $this->panel($v);
        $rows = $c->get('savingsUsageRows');
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['reverted']);
        $c->assertSeeHtml('data-savings-history')->call('revertSavingsUse', $used->id)->assertHasNoErrors();

        $v->refresh();
        $this->assertSame(0.0, (float) $v->savings_used);
        $this->assertSame(3_040.0, (float) $v->sale_unpaid_amount, '미수가 되돌아온다');
        $refund = SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', 'REFUND')->first();
        $this->assertNotNull($refund);
        $this->assertSame($used->id, (int) $refund->original_transaction_id, '어느 사용을 되돌렸는지 짝을 남긴다');
        $this->assertSame(1_000.0, (float) SavingsStatus::where('buyer_id', $v->buyer_id)->orderByDesc('id')->value('balance'));
        $this->assertTrue($c->get('savingsUsageRows')[0]['reverted']);

        // 같은 건 두 번 못 되돌린다
        $c->call('revertSavingsUse', $used->id);
        $this->assertSame(1, SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', 'REFUND')->count());
        $this->assertSame(0.0, (float) $v->fresh()->savings_used);
    }

    public function test_history_sum_still_matches_the_column(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);
        $this->panel($v)->set('savings_used_str', '300')->call('save');
        $this->panel($v)->set('savings_used_str', '150')->call('save');
        $used = SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', 'USED')->orderBy('id')->first();
        $this->panel($v)->call('revertSavingsUse', $used->id);

        $this->assertSame(150.0, (float) $v->fresh()->savings_used);
        $this->assertSame(150.0, (float) ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'savings')->sum('amount'), '회수이력 합 = savings_used (SavingsInReceivablesTest 불변식)');
    }

    public function test_non_cash_recoveries_line_shows_what_receivables_cleared(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        $c = $this->panel($v);
        $c->assertDontSeeHtml('data-non-cash-recoveries');

        $this->panel($v)->set('savings_used_str', '878')->call('save');
        ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-10-10', 'collector_id' => $admin->id, 'method' => 'other', 'amount' => 100, 'note' => '자투리']);
        ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-10-11', 'collector_id' => $admin->id, 'method' => 'write_off', 'amount' => 62, 'note' => '손실']);

        $c = $this->panel($v);
        $c->assertSeeHtml('data-non-cash-recoveries')->assertSee('입금 외 정리')
            ->assertSee('적립금 사용 EUR 878')->assertSee('기타 EUR 100')->assertSee('손실(셀러부담) EUR 62')->assertSee('= EUR 1,040');
        $this->assertSame(2_000.0, (float) $v->fresh()->sale_unpaid_amount, '3,040 − 878 − 100 − 62');
    }
}
