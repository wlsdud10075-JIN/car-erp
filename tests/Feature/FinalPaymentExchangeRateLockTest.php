<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 정산 재설계 선행결함1 (2026-07-06) — 재무확정 잔금의 exchange_rate/amount_krw 회계잠금.
 *
 * 신 2차정산분(Σ실입금KRW − sale_total_amount×판매환율)이 잔금별 환율(amount_krw)에 의존하는데,
 * FinalPayment::updating confirmed 락이 exchange_rate 를 빠뜨려 재무확정 후 무단수정 가능하던 갭 보강.
 * c-2 잠금해제($allowConfirmedMutation)만 우회 가능.
 */
class FinalPaymentExchangeRateLockTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedPayment(): FinalPayment
    {
        $v = Vehicle::create([
            'vehicle_number' => 'FX-LOCK-1', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1400,
            'sale_price' => 4000, 'sale_date' => '2026-06-01',
            'purchase_date' => '2026-05-01', 'dhl_request' => false,
        ]);

        return $v->finalPayments()->create([
            'amount' => 4000, 'type' => 'balance', 'exchange_rate' => 1400,
            'payment_date' => '2026-06-10', 'confirmed_at' => now(),
        ]);
    }

    public function test_confirmed_payment_exchange_rate_change_is_blocked(): void
    {
        $p = $this->confirmedPayment();

        $this->expectException(\DomainException::class);
        $p->update(['exchange_rate' => 1500]);
    }

    public function test_unlock_flag_allows_exchange_rate_change(): void
    {
        $p = $this->confirmedPayment();

        FinalPayment::$allowConfirmedMutation = true;
        try {
            $p->update(['exchange_rate' => 1500]);
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }

        $p->refresh();
        $this->assertSame('1500.0000', (string) $p->exchange_rate);
        // amount_krw = amount × exchange_rate 자동 재계산 (saving 훅).
        $this->assertSame('6000000.00', (string) $p->amount_krw);
    }
}
