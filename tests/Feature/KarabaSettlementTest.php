<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * karaba 이익율 정산 (Phase 3, 2026-07-22) — 엑셀 매입대장 실측 공식.
 *   영업이익 = 판매가(차대금×환율) − (구매가+매도비 + 부대비용 − 매입세액VAT)
 *   tier ≥6%→20% / 5~6%→15% / <5%→10% (배타경계, 음수→0) · 매입세액 미입력 시 정산 확정 차단.
 */
class KarabaSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
        Settlement::flushParamMemo();
    }

    private function make(array $attrs = []): Settlement
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'KB-1',
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1450,
            'sale_price' => 10000, 'sale_date' => '2026-05-01',
            'purchase_price' => 12_000_000, 'selling_fee' => 200_000,
            'purchase_vat_amount' => 1_000_000,
            'dhl_request' => false,
        ], $attrs));

        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_status' => 'pending',
        ]);
    }

    public function test_operating_profit_and_tier_high(): void
    {
        $s = $this->make();
        // 판매가 = 10000 × 1450 = 14,500,000 / 구매가합 12,200,000 / VAT 1,000,000
        // 영업이익 = 14,500,000 − (12,200,000 − 1,000,000) = 3,300,000
        $this->assertSame(3_300_000, $s->karaba_operating_profit);
        // 이익율 22.7% ≥6% → ×20% = 660,000
        $this->assertSame(660_000, $s->settlement_amount);
    }

    public function test_tier_low_under_5pct(): void
    {
        // 영업이익 500,000 / 판매가 14,500,000 = 3.45% <5% → ×10% = 50,000
        $s = $this->make(['purchase_price' => 14_000_000, 'selling_fee' => 0, 'purchase_vat_amount' => 0]);
        $this->assertSame(500_000, $s->karaba_operating_profit);
        $this->assertSame(50_000, $s->settlement_amount);
    }

    public function test_negative_profit_floors_to_zero(): void
    {
        $s = $this->make(['purchase_price' => 20_000_000, 'purchase_vat_amount' => 0]);
        $this->assertTrue($s->karaba_operating_profit < 0);
        $this->assertSame(0, $s->settlement_amount);
    }

    public function test_confirm_blocked_without_vat(): void
    {
        $s = $this->make(['purchase_vat_amount' => null]);   // 매입세액 미입력
        $this->expectException(ValidationException::class);
        $s->settlement_status = 'confirmed';
        $s->save();
    }

    public function test_confirm_passes_with_vat_zero(): void
    {
        // 불공제 = 0 명시 입력 → 통과 (null 만 차단)
        $s = $this->make(['purchase_vat_amount' => 0]);
        $s->settlement_status = 'confirmed';
        $s->save();
        $this->assertSame('confirmed', $s->fresh()->settlement_status);
    }
}
