<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 적립금 사용(savings_used)의 미수 반영 (jin 2026-07-09).
 *   적립금 = buyer×currency 크레딧 풀(원 차량 입금에서 이미 빠진 돈) → 이 차량에 쓰면 잔금(미수) 차감.
 *   실입금KRW(2차 환차)에도 판매환율(FX중립)로 포함. 통화별 매칭(그 차량 통화 풀).
 */
class SavingsUsedUnpaidTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function vehicle(string $currency = 'KRW', float $rate = 1, int $salePrice = 1_000_000): Vehicle
    {
        $buyer = Buyer::create(['name' => 'SB'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'SS'.$this->c, 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create([
            'vehicle_number' => 'SV'.$this->c, 'sales_channel' => 'export',
            'currency' => $currency, 'exchange_rate' => $rate, 'sale_price' => $salePrice,
            'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    private function seedSavings(Vehicle $v, float $balance): void
    {
        SavingsStatus::create([
            'buyer_id' => $v->buyer_id, 'currency' => $v->currency,
            'transaction_type' => 'EARNED', 'savings' => $balance, 'balance' => $balance,
        ]);
    }

    public function test_savings_used_reduces_unpaid(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $this->assertEqualsWithDelta(1_000_000, $v->fresh()->sale_unpaid_amount, 0.01, '사용 전 미수 100만');

        $v->update(['savings_used' => 300_000]);
        $this->assertEqualsWithDelta(700_000, $v->fresh()->sale_unpaid_amount, 0.01, '적립금 30만 사용 → 미수 70만');
    }

    public function test_savings_used_full_balance_marks_complete(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 1_500_000);

        $v->update(['savings_used' => 1_000_000]);   // 판매가 전액을 적립금으로 결제
        $v->refresh();
        $this->assertEqualsWithDelta(0, $v->sale_unpaid_amount, 0.01, '전액 적립금 결제 → 미수 0');
        $this->assertSame('판매완료', $v->progress_status, '적립금 완납 → 판매완료');
    }

    public function test_savings_used_included_in_received_krw(): void
    {
        // 외화 환차 대칭 — 실입금KRW 에 적립금이 판매환율로 포함돼야 (누락 시 거짓 환차손).
        $v = $this->vehicle('USD', 1300, 1_000);
        $this->seedSavings($v, 2_000);

        $v->update(['savings_used' => 400]);   // 400 USD 적립금 사용
        $v->refresh();
        // 실입금KRW = 400 × 1300 = 520,000 (잔금·기타회수 없음)
        $this->assertSame(520_000, $v->sale_received_krw_accumulated, '적립금 사용분 실입금KRW 포함');
        $this->assertEqualsWithDelta(600, $v->sale_unpaid_amount, 0.01, '미수 = 1000 - 400 = 600 USD');
    }
}
