<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * - 저장하면 누적(vehicles.savings_used)에 더하고 칸은 비워진다. 이력·합계는 판매탭에 안 둔다(바이어 적립금 탭·「입금 외 정리」 줄).
 * - 잔액 초과는 저장 거부(채권관리·바이어 드로어와 같은 규칙 — 판매탭만 빠져 있던 구멍).
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

        $c->set('savings_used_str', '100')->call('save')->assertHasNoErrors();
        $this->assertSame(100.0, (float) $v->fresh()->savings_used);
        $this->assertSame('', $c->get('savings_used_str'), '저장하면 칸이 비워진다');
        $this->assertFalse(method_exists($c->instance(), 'savingsUsageRows'), '판매탭엔 이력·합계·되돌리기가 없다(jin «굳이 여기서 볼 필요 없다») — 바이어 적립금 탭에서');

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

    public function test_savings_entered_when_the_vehicle_is_created_reaches_the_ledger_and_receivables(): void
    {
        // jin 2026-10-19 ssancarerp 61거7486 — 신규 등록 저장에 적립금 200 을 같이 넣으면 insert 라 H6 가 안 돌아
        //   원장 차감·회수이력이 0 이었다(판매탭에만 200, 바이어 잔액은 그대로 → 또 쓸 수 있다). 운영 7대.
        [$seed, $admin, $buyer] = $this->soldVehicle(500);
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => '61거7486', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1480,
            'buyer_id' => $buyer->id, 'sale_price' => 2_549, 'sale_date' => '2026-10-15', 'savings_used' => 200,
        ]);

        $this->assertSame([-200.0], SavingsStatus::where('vehicle_id', $v->id)->pluck('savings')->map(fn ($x) => (float) $x)->all(), '원장에 USED 200 한 줄');
        $this->assertSame(300.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->orderByDesc('id')->value('balance'), '바이어 잔액 500 − 200');
        $this->assertSame([200.0], ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'savings')->pluck('amount')->map(fn ($x) => (float) $x)->all(), '채권 회수이력에도 한 줄');

        $logged = AuditLog::where('auditable_type', Vehicle::class)->where('auditable_id', $v->id)->whereNotNull('column_name')->pluck('new_value', 'column_name');
        $this->assertSame(200.0, (float) $logged['savings_used'], '등록 때 넣은 적립금 사용이 감사로그에 남는다');
        $this->assertArrayHasKey('sale_price', $logged->all());
        $this->assertArrayNotHasKey('tax_dc', $logged->all(), '0·빈 값은 적지 않는다');

        // 같은 인스턴스로 다시 저장(파일·캐시 갱신 같은 후속 save) — 두 번 기록하지 않는다
        $v->memo = '후속 저장';
        $v->save();
        $v->save();
        $this->assertSame(1, SavingsStatus::where('vehicle_id', $v->id)->count(), '후속 저장이 또 차감하지 않는다');

        // 그 뒤 진짜 변경은 종전대로 delta 만
        $v->update(['savings_used' => 250]);
        $this->assertSame([-200.0, -50.0], SavingsStatus::where('vehicle_id', $v->id)->orderBy('id')->pluck('savings')->map(fn ($x) => (float) $x)->all());
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

    public function test_history_sum_still_matches_the_column(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);
        $this->panel($v)->set('savings_used_str', '300')->call('save');
        $this->panel($v)->set('savings_used_str', '150')->call('save');
        $v->refresh();
        $v->savings_used = 150;   // 원장 쪽 정정(첫 사용 300 되돌림)과 같은 효과
        $v->save();

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
        // 과입금 정리 기록(misc_loss)은 미수에서 안 빼는 미러 행 — 이 줄에도 안 들어가야 미수 식과 맞는다
        ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-10-12', 'collector_id' => $admin->id, 'method' => 'misc_loss', 'amount' => 999, 'note' => '과입금 정리 기록']);

        $c = $this->panel($v);
        $c->assertSeeHtml('data-non-cash-recoveries')->assertSee('입금 외 정리')
            ->assertSee('적립금 사용 EUR 878')->assertSee('기타 EUR 100')->assertSee('손실(셀러부담) EUR 62')->assertSee('= EUR 1,040')->assertDontSee('잡손실');
        $this->assertSame(1_040.0, $c->get('panelNonCashRecoveries')['total'], 'misc_loss 는 합계 밖(Vehicle::MIRRORED_RECEIVABLE_METHODS 와 같은 제외)');
        $this->assertSame(2_000.0, (float) $v->fresh()->sale_unpaid_amount, '3,040 − 878 − 100 − 62');
    }
}
