<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 회의확장씬 #6 보강 (2026-05-23) — final_payments.amount_krw snapshot 저장 검증.
 *
 * 사용자 명세: 환산 KRW 를 row 별로 저장 → 1차/2차 정산·환차익 시 별도 계산 없이 SUM(amount_krw).
 * FinalPayment::saving 훅이 amount × exchange_rate 자동 계산.
 */
class FinalPaymentAmountKrwTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeUsdVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'KRW-FP-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 1000,
            'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
    }

    public function test_amount_krw_column_exists_and_is_fillable(): void
    {
        $this->assertTrue(Schema::hasColumn('final_payments', 'amount_krw'));
        $this->assertContains('amount_krw', (new FinalPayment)->getFillable());
    }

    public function test_saving_hook_computes_amount_krw_on_create(): void
    {
        $v = $this->makeUsdVehicle();
        $fp = $v->finalPayments()->create([
            'amount' => 400,
            'exchange_rate' => 1350,
            'payment_date' => '2026-05-15',
        ]);
        // 400 × 1350 = 540000
        $this->assertSame('540000.00', (string) $fp->amount_krw);
    }

    public function test_saving_hook_recomputes_amount_krw_when_amount_changes(): void
    {
        $v = $this->makeUsdVehicle();
        $fp = $v->finalPayments()->create([
            'amount' => 400,
            'exchange_rate' => 1350,
            'payment_date' => '2026-05-15',
        ]);
        $this->assertSame('540000.00', (string) $fp->amount_krw);

        $fp->amount = 500;
        $fp->save();
        // 500 × 1350 = 675000
        $this->assertSame('675000.00', (string) $fp->fresh()->amount_krw);
    }

    public function test_saving_hook_recomputes_amount_krw_when_rate_changes(): void
    {
        $v = $this->makeUsdVehicle();
        $fp = $v->finalPayments()->create([
            'amount' => 400,
            'exchange_rate' => 1350,
            'payment_date' => '2026-05-15',
        ]);
        $fp->exchange_rate = 1400;
        $fp->save();
        // 400 × 1400 = 560000
        $this->assertSame('560000.00', (string) $fp->fresh()->amount_krw);
    }

    public function test_null_exchange_rate_keeps_amount_krw_null(): void
    {
        $v = $this->makeUsdVehicle();
        $fp = $v->finalPayments()->create([
            'amount' => 400,
            'exchange_rate' => null,
            'payment_date' => '2026-05-15',
        ]);
        $this->assertNull($fp->amount_krw);
    }

    public function test_krw_currency_amount_krw_equals_amount(): void
    {
        // Vehicle::saving 훅이 KRW currency → exchange_rate=1 normalize.
        // 따라서 amount_krw = amount × 1 = amount.
        $v = Vehicle::create([
            'vehicle_number' => 'KRW-CURR-'.++$this->counter,
            'sales_channel' => 'heyman',
            'currency' => 'KRW',
            'dhl_request' => false,
            'sale_price' => 5_000_000,
            'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $fp = $v->finalPayments()->create([
            'amount' => 5_000_000,
            'exchange_rate' => $v->exchange_rate,  // 1 (normalize됨)
            'payment_date' => '2026-05-15',
        ]);
        $this->assertSame('5000000.00', (string) $fp->amount_krw);
    }
}
