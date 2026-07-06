<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 결제대기(grace) 10일 유예 규칙 (jin 2026-07-06 A안).
 * 선적 전 미수는 판매일+10일 지나야 채권, 그 전엔 grace. 선적 후는 즉시.
 */
class ReceivableGraceTest extends TestCase
{
    use RefreshDatabase;

    private function sold(int $daysAgo, bool $shipped, string $num): Vehicle
    {
        $buyer = Buyer::create(['name' => '딜러'.$num, 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => 10000,
            'sale_date' => now()->subDays($daysAgo)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'bl_loading_location' => $shipped ? '부산항' : null,
        ]);
    }

    public function test_pre_shipping_within_grace_is_grace_not_receivable(): void
    {
        $v = $this->sold(5, false, '11가1111');   // 선적 전, 5일 전 판매, 미수

        $this->assertSame('grace', $v->receivable_risk_computed);
        $this->assertSame('grace', $v->fresh()->receivable_risk);   // 캐시 컬럼도
        $this->assertSame('결제대기', $v->receivable_risk_label);

        // 판매미입금 알림/뱃지 대상 아님
        $this->assertNotContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_pre_shipping_past_grace_is_receivable(): void
    {
        $v = $this->sold(15, false, '22나2222');   // 선적 전, 15일 전 판매, 미수

        $this->assertNotSame('grace', $v->receivable_risk_computed);
        $this->assertContains($v->receivable_risk_computed, ['caution', 'danger', 'critical']);
        $this->assertContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_post_shipping_is_receivable_immediately(): void
    {
        $v = $this->sold(0, true, '33다3333');   // 선적 후(반입지 있음), 오늘 판매, 미수

        $this->assertNotSame('grace', $v->receivable_risk_computed);   // 유예 없음
        $this->assertContains($v->receivable_risk_computed, ['caution', 'danger', 'critical']);
        $this->assertContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_fully_paid_is_safe(): void
    {
        $v = $this->sold(5, false, '44라4444');
        $v->finalPayments()->create(['amount' => 10000, 'payment_date' => now()->toDateString(), 'type' => 'balance', 'confirmed_at' => now()]);
        $v->refreshProgressCache();

        $this->assertSame('safe', $v->fresh()->receivable_risk_computed);
    }
}
