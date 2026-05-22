<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SavingsStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 회의확장씬 #12 회귀 — 판매 탭 적립금 적립 (SavingsStatus EARNED 직접 거래).
 *
 * 설계: FP 안 건드림 (마이그 0) — SavingsStatus 만으로 처리.
 * - sale_unpaid_amount 분자 차감 X (회의확장씬 #12 정신)
 * - buyer×currency 누적 잔액
 * - 양쪽 입력 호환 (vehicles 판매 탭 + 바이어 페이지)
 */
class SavingsDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['name' => 'BUYER', 'is_active' => true]);
    }

    private function makeVehicle(?int $buyerId, string $currency = 'KRW'): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'SDT-'.random_int(10000, 99999),
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => 1,
            'dhl_request' => false,
            'buyer_id' => $buyerId,
        ]);
    }

    public function test_sync_savings_deposit_creates_earned_transaction(): void
    {
        $buyer = $this->makeBuyer();
        $v = $this->makeVehicle($buyer->id);

        $v->syncSavingsDeposit(500000);

        $tx = SavingsStatus::where('buyer_id', $buyer->id)->latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertSame('EARNED', $tx->transaction_type);
        $this->assertSame(500000.0, (float) $tx->savings);
        $this->assertSame(500000.0, (float) $tx->balance);
        $this->assertSame($v->id, $tx->vehicle_id);
    }

    public function test_sync_accumulates_balance_across_calls(): void
    {
        $buyer = $this->makeBuyer();
        $v = $this->makeVehicle($buyer->id);

        $v->syncSavingsDeposit(500000);
        $v->syncSavingsDeposit(300000);

        $latest = SavingsStatus::where('buyer_id', $buyer->id)->latest('id')->first();
        $this->assertSame(800000.0, (float) $latest->balance);
        $this->assertSame(2, SavingsStatus::where('buyer_id', $buyer->id)->count());
    }

    public function test_sync_ignores_non_positive_amount(): void
    {
        $buyer = $this->makeBuyer();
        $v = $this->makeVehicle($buyer->id);

        $v->syncSavingsDeposit(0);
        $v->syncSavingsDeposit(-100);

        $this->assertSame(0, SavingsStatus::where('buyer_id', $buyer->id)->count());
    }

    public function test_sync_ignores_vehicle_without_buyer(): void
    {
        $v = $this->makeVehicle(null);

        $v->syncSavingsDeposit(500000);

        $this->assertSame(0, SavingsStatus::count());
    }

    public function test_deposit_does_not_reduce_sale_unpaid_amount(): void
    {
        // 회의확장씬 #12: 적립금은 차량 미수금 분자에서 차감 X.
        $buyer = $this->makeBuyer();
        $v = $this->makeVehicle($buyer->id);
        $v->update(['sale_price' => 2500000, 'sale_date' => '2026-05-01']);
        $v->refresh();
        $beforeUnpaid = (float) $v->sale_unpaid_amount;

        $v->syncSavingsDeposit(500000);
        $v->refresh();
        $afterUnpaid = (float) $v->sale_unpaid_amount;

        $this->assertSame($beforeUnpaid, $afterUnpaid, '적립금은 미수금에서 차감되면 안 됨');
    }

    public function test_currency_separation(): void
    {
        $buyer = $this->makeBuyer();
        $vKrw = $this->makeVehicle($buyer->id, 'KRW');
        $vUsd = $this->makeVehicle($buyer->id, 'USD');

        $vKrw->syncSavingsDeposit(500000);
        $vUsd->syncSavingsDeposit(300);

        $krwBal = (float) SavingsStatus::where('buyer_id', $buyer->id)->where('currency', 'KRW')->latest('id')->value('balance');
        $usdBal = (float) SavingsStatus::where('buyer_id', $buyer->id)->where('currency', 'USD')->latest('id')->value('balance');

        $this->assertSame(500000.0, $krwBal);
        $this->assertSame(300.0, $usdBal);
    }
}
