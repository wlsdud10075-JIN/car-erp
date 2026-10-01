<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 **받은 몫의 환차** (jin 2026-10-01 B안).
 *
 * jin: *「예외로 두면 그건 정산을 해주겠다라는 뜻이니까 환차가 보이고 정산되면 환차가 이월되어야 하지 않아?」*
 *
 * 게이트 예외(미수인 채 지급·마감)가 열려 있는데 정산환율은 미완납이면 판매환율로 통째 폴백해,
 * 예외 건 156(ssancarerp)의 환차가 완납 전엔 영영 담당자에게 안 갔다. 미수가 잔돈이라 완납도 안 온다.
 * ⇒ 받은 외화는 실입금 환율, 미수 외화는 판매환율로 **섞는다**. 미수 원금은 환율로 둔갑하지 않는다.
 *
 * 단일 출처 = `Vehicle::settlement_exchange_rate` · `Settlement::computeExchangeDifference`.
 */
class SettlementPartialFxTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(string $plate = '29어9543'): Vehicle
    {
        $sm = Salesman::create(['name' => '김연아', 'type' => 'freelance', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $plate,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1000,
            'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000,
            'purchase_date' => '2026-08-01',
            'sale_price' => 10_000,
            'sale_date' => '2026-09-01',
        ]);
    }

    private function pay(Vehicle $v, float $amount, float $rate): void
    {
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => $amount, 'exchange_rate' => $rate,
            'payment_date' => '2026-09-05', 'confirmed_at' => now(),
        ]);
        $v->refresh();
    }

    /** 미수인 채 지급까지 끝난 예외 정산 — 2차 대기 중. */
    private function overriddenPaidSettlement(Vehicle $v): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $v->salesman_id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
            'secondary_status' => 'pending',
            'gate_override_at' => now(),
            'gate_override_reason' => '운임비 잔돈 — 추후 입금',
        ]);
    }

    // ── 정산환율 ───────────────────────────────────────────────

    public function test_blended_rate_moves_between_sale_rate_and_effective_rate(): void
    {
        $v = $this->vehicle();
        $this->assertSame(1000.0, (float) $v->settlement_exchange_rate, '입금 0 = 판매환율');

        $this->pay($v, 4_000, 1050);
        // (4,000×1050 + 6,000×1000) ÷ 10,000 = 1020
        $this->assertEqualsWithDelta(1020.0, $v->settlement_exchange_rate, 1e-9, '받은 4,000 만 실효환율');

        $this->pay($v, 6_000, 1050);
        $this->assertEqualsWithDelta(1050.0, $v->settlement_exchange_rate, 1e-9, '완납 = 종전 실효환율');
    }

    // ── 환차 ─────────────────────────────────────────────────

    /** 🚨 미수 원금이 환차손으로 둔갑하지 않는다 — 받은 4,000 의 +50/USD 만 센다. */
    public function test_exchange_difference_counts_the_received_portion_only(): void
    {
        $v = $this->vehicle();
        $this->pay($v, 4_000, 1050);
        $s = $this->overriddenPaidSettlement($v);

        $this->assertEqualsWithDelta(200_000.0, $s->computeExchangeDifference(), 0.01,
            '구 식(실입금 − 총판매가×판매환율)이면 −5,800,000 — 미수 6,000 이 손실로 둔갑한다');
        $this->assertEqualsWithDelta(200_000.0, $s->display_exchange_difference, 0.01);
        $this->assertTrue($s->isExchangeDifferencePartial());
    }

    /**
     * 게이트 예외로 미수인 채 2차를 마감하면 **받은 몫의 환차**가 저장된다.
     * 구 식이면 −5,800,000 이 「확정 환차」로 영구 박제됐다(관리자 대시보드 fx 합산이 그 컬럼을 읽는다).
     */
    public function test_closing_with_a_gate_override_stores_the_partial_difference(): void
    {
        $v = $this->vehicle();
        $this->pay($v, 4_000, 1050);
        $s = $this->overriddenPaidSettlement($v);

        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $s->refresh();
        $this->assertSame('closed', $s->secondary_status, '예외 건인데 미완납이라 안 닫혔다');
        $this->assertEqualsWithDelta(200_000.0, (float) $s->exchange_difference_krw, 0.01);
        $this->assertFalse($s->isExchangeDifferencePartial(), '마감되면 저장값이 권위 — 「부분」 표식을 떼야 한다');
    }

    // ── 드로어 명세 ──────────────────────────────────────────

    /** 명세 세 줄(실입금 · 기준액 · 환차)이 미완납에서도 닫혀야 한다 — 기준액 = 받은 외화 × 판매환율. */
    public function test_krw_breakdown_still_ties_while_unpaid(): void
    {
        $v = $this->vehicle();
        $this->pay($v, 4_000, 1050);
        $s = $this->overriddenPaidSettlement($v);

        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        $c = Volt::test('erp.settlements.index')->call('selectVehicle', $v->id)->call('openEdit', $s->id);
        $kb = $c->instance()->krwBreakdown();

        $this->assertTrue($kb['is_partial']);
        $this->assertSame(6_000.0, (float) $kb['unpaid_fx']);
        $this->assertSame(4_000_000, $kb['baseline_krw'], '받은 4,000 × 1000');
        $this->assertEqualsWithDelta($kb['received_krw'] - $kb['baseline_krw'], $kb['exchange_diff'], 0.01,
            '실입금 − 기준액 ≠ 환차 — 명세가 안 닫힌다');
        $c->assertSee(__('settlement.krw_baseline_sub_partial'))
            ->assertSee(__('settlement.krw_unpaid_fx'));
    }
}
