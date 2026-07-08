<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * vehicles:convert-legacy-payment-types — 계약금/중도금/선수금1 → 잔금(balance) 일괄 전환.
 * 판매탭 단순화 후속. 미수·총입금 불변, 감사로그 기록, 이체링크 스킵.
 */
class ConvertLegacyPaymentTypesTest extends TestCase
{
    use RefreshDatabase;

    private function soldVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-06-01',
            'buyer_id' => Buyer::create(['name' => 'B', 'is_active' => true])->id,
        ]);
    }

    public function test_converts_legacy_types_to_balance_with_note_and_audit(): void
    {
        $v = $this->soldVehicle();
        $v->finalPayments()->create(['amount' => 3000, 'type' => 'deposit_down', 'payment_date' => '2026-06-01', 'exchange_rate' => 1300]);
        $v->finalPayments()->create(['amount' => 2000, 'type' => 'interim', 'payment_date' => '2026-06-02', 'exchange_rate' => 1300]);
        $v->finalPayments()->create(['amount' => 1000, 'type' => 'advance_1', 'payment_date' => '2026-06-03', 'exchange_rate' => 1300]);

        $unpaidBefore = $v->fresh()->sale_unpaid_amount;

        $this->artisan('vehicles:convert-legacy-payment-types --apply')->assertSuccessful();

        $this->assertSame(0, $v->finalPayments()->whereIn('type', ['deposit_down', 'interim', 'advance_1'])->count());
        $this->assertSame(3, $v->finalPayments()->where('type', 'balance')->count());
        // 원 유형 비고 표기
        $this->assertStringContainsString('구 계약금', (string) $v->finalPayments()->where('amount', 3000)->value('note'));
        // 미수 불변
        $this->assertSame($unpaidBefore, $v->fresh()->sale_unpaid_amount);
        // 감사로그
        $this->assertSame(3, AuditLog::where('action', 'payment_type_converted')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $v = $this->soldVehicle();
        $v->finalPayments()->create(['amount' => 3000, 'type' => 'deposit_down', 'payment_date' => '2026-06-01', 'exchange_rate' => 1300]);

        $this->artisan('vehicles:convert-legacy-payment-types')->assertSuccessful();

        $this->assertSame(1, $v->finalPayments()->where('type', 'deposit_down')->count());
        $this->assertSame(0, AuditLog::where('action', 'payment_type_converted')->count());
    }

    public function test_confirmed_legacy_row_is_converted(): void
    {
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 5000, 'type' => 'deposit_down', 'payment_date' => '2026-06-01',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);

        $this->artisan('vehicles:convert-legacy-payment-types --apply')->assertSuccessful();

        $this->assertSame(1, $v->finalPayments()->where('type', 'balance')->whereNotNull('confirmed_at')->count());
    }

    public function test_missing_exchange_rate_is_backfilled_from_vehicle(): void
    {
        $v = $this->soldVehicle();
        $v->finalPayments()->create(['amount' => 4000, 'type' => 'interim', 'payment_date' => '2026-06-01', 'exchange_rate' => null]);

        $this->artisan('vehicles:convert-legacy-payment-types --apply')->assertSuccessful();

        $row = $v->finalPayments()->where('amount', 4000)->first();
        $this->assertSame('balance', $row->type);
        $this->assertSame(1300, (int) $row->exchange_rate);       // 차량 판매환율로 보정
        $this->assertSame(5_200_000, (int) $row->amount_krw);     // 4000 × 1300 재계산
    }
}
