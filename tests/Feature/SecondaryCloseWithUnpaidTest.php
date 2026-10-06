<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔓 **미수가 남아 있어도 2차 마감한다** (jin 2026-10-06 *「미수 있어도 마감은 하자. 미수금액만 보여주고,
 * 그건 받아야 하는 금액으로 남기기만 하면 돼」*).
 *
 * 구(2026-07-06): 외화는 완납 후에만 마감 — 미수가 환차손으로 둔갑했기 때문. 10-01 B안 뒤로 환차 식이
 * 받은 몫만 세므로 그 왜곡이 없고, 게이트만 남아 「9/10 1차 → 10월 2차」가 보장되지 않는 실무를 막고 있었다.
 *
 * 함께 바뀐 것: 미수가 남은 마감 차량은 **미수가 0 이 될 때까지 신규 잔금이 열린다**(구: 예외 뱃지 건만).
 * 안 열면 마감 뒤 들어오는 돈을 기록할 길이 없어 「미수 표시가 그대로」가 아니라 영영 못 지우는 숫자가 된다.
 */
class SecondaryCloseWithUnpaidTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    /** EUR 10,000 판매 · 7,000 만 확정 입금(미수 3,000) · 정산 지급(paid, 스냅샷 있음) · 2차 대기. */
    private function partiallyPaidClosedCandidate(): array
    {
        $sm = Salesman::create(['name' => '프리', 'type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '14더3753', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => '2026-07-01',
            'sale_price' => 10_000, 'sale_date' => '2026-07-10',
        ]);
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 7_000, 'exchange_rate' => 1500,
            'payment_date' => '2026-07-20', 'confirmed_at' => now(),
        ]);
        $v->refresh()->refreshCaches();

        Settlement::$allowBatchPayout = true;
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-07-01',
        ]);
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();   // paid 전환 훅 → 스냅샷 + 2차 대기
        Settlement::$allowBatchPayout = false;
        // 지급 뒤 비용(탁송비) 기입 → 「2차 가능」. 일괄 마감 대상은 이것만이다(2026-10-06 3번, 훅은 SecondaryReadyFilterTest 가 본다).
        $v->fresh()->update(['cost_towing' => 150_000]);

        return [$v->fresh(), $s->fresh(), $sm];
    }

    /** 미수 3,000 EUR 가 남아 있는데도 예외 없이 닫힌다. 미수는 그대로 남는다. */
    public function test_closes_with_unpaid_and_leaves_the_receivable_in_place(): void
    {
        [$v, $s] = $this->partiallyPaidClosedCandidate();
        $this->assertSame('pending', $s->secondary_status);
        $this->assertNotNull($s->confirmed_snapshot, '지급 스냅샷이 있어야 이월이 계산된다');
        $this->assertSame(3_000.0, (float) $v->sale_unpaid_amount);
        $this->assertFalse($s->hasGateOverride(), '예외 뱃지 없이 닫혀야 한다');

        $this->actingAs($this->finance());
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id)
            ->assertDispatched('notify', fn ($name, $p) => ($p['type'] ?? '') === 'success');

        $this->assertSame('closed', $s->fresh()->secondary_status);
        $this->assertSame(3_000.0, (float) $v->fresh()->sale_unpaid_amount, '마감이 미수를 지우면 안 된다 — 받을 돈이다');
    }

    /** 일괄 마감 미리보기도 같은 판정 — 미수 건이 「닫을 것」에 들어오고 미수 금액이 같이 보인다. */
    public function test_bulk_preview_lists_the_unpaid_row_as_ready_with_its_amount(): void
    {
        [, $s] = $this->partiallyPaidClosedCandidate();
        $this->actingAs($this->finance());

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-07');
        $preview = $c->instance()->closeSecondaryPreview;

        $ids = array_column($preview['ready'], 'id');
        $this->assertContains($s->id, $ids, '미수 건이 「닫을 것」에 없다');
        $row = collect($preview['ready'])->firstWhere('id', $s->id);
        $this->assertStringContainsString('3,000', $row['unpaid'], '미수 금액이 안 보인다');
        $this->assertSame([], $preview['skipped']);
    }

    /**
     * 🔑 마감 뒤 들어온 돈을 기록할 수 있다 — 신규 잔금 생성·재무확정이 통과하고, 미수가 0 이 되면 다시 잠긴다.
     *    그리고 그 돈은 **담당자 정산을 안 움직인다** — 마감 시 확정한 `carryover_out_krw` 가 그대로다.
     */
    public function test_money_received_after_close_can_be_recorded_then_relocks_without_moving_the_carryover(): void
    {
        [$v, $s] = $this->partiallyPaidClosedCandidate();
        $finance = $this->finance();
        $this->actingAs($finance);
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);
        $s = $s->fresh();
        $carryAtClose = $s->carryover_out_krw;
        $this->assertSame('closed', $s->secondary_status);
        $this->assertFalse($v->fresh()->ledgerLockedForNewPayments(), '미수가 남은 마감 차량은 신규 잔금이 열려 있어야 한다');

        $fp = $v->fresh()->finalPayments()->create([
            'type' => 'balance', 'amount' => 3_000, 'exchange_rate' => 1600, 'payment_date' => '2026-11-05',
        ]);
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);
        $v = $v->fresh();
        $v->refreshCaches();

        $this->assertLessThanOrEqual(0, (float) $v->fresh()->sale_unpaid_amount, '수금분이 미수를 못 지웠다');
        $this->assertTrue($v->fresh()->ledgerLockedForNewPayments(), '미수가 0 이면 즉시 재잠금');
        $this->assertEquals($carryAtClose, $s->fresh()->carryover_out_krw, '마감 뒤 들어온 돈이 이월을 바꿨다 — 마감은 1회 확정이다');

        // 재잠금 뒤에는 종전대로 막힌다
        $this->expectException(\DomainException::class);
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 1, 'payment_date' => '2026-11-06']);
    }

    /** 환율 누락은 종전대로 막는다 — 없어진 것은 완납 게이트뿐이다. */
    public function test_missing_rate_still_blocks(): void
    {
        [$v, $s] = $this->partiallyPaidClosedCandidate();
        Vehicle::whereKey($v->id)->update(['exchange_rate' => 0]);
        $this->actingAs($this->finance());

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id)
            ->assertDispatched('notify', fn ($name, $p) => ($p['type'] ?? '') === 'error');
        $this->assertSame('pending', $s->fresh()->secondary_status);
    }
}
