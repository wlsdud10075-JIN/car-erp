<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
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

    public function test_display_margin_is_operating_profit_and_page_shows_label(): void
    {
        $s = $this->make();
        $this->assertSame($s->karaba_operating_profit, $s->display_margin);

        // 정산 화면이 karaba 라벨(영업이익)로 렌더 — 총마진 표기 아님
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        Volt::test('erp.settlements.index')->assertSee('영업이익');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 비율칸 사용 (jin 2026-08-21)
    //
    // 구: 이익률 tier 를 코드가 직접 곱했고 `settlement_ratio` 는 **읽히지도 않았다**.
    //     그런데 화면은 그 칸을 필수로 받아서 «넣은 비율이 반영되겠지» 로 읽혔다(실제로는 무시).
    // 신: 비율칸에 tier 값이 자동으로 채워지고, 고치면 고친 값으로 계산된다.
    // ─────────────────────────────────────────────────────────────────────────

    /** 비율칸이 비어 있으면 종전대로 tier 자동값 — 손대지 않으면 결과가 같다. */
    public function test_blank_ratio_still_uses_the_tier(): void
    {
        $s = $this->make();

        $this->assertNull($s->settlement_ratio);
        $this->assertSame(20, $s->karaba_tier_percent);
        $this->assertSame(660_000, $s->settlement_amount);
    }

    /** 🚨 비율을 고치면 **그 값으로** 계산된다 — 이게 이번 변경의 핵심이다. */
    public function test_edited_ratio_overrides_the_tier(): void
    {
        $s = $this->make();
        $s->settlement_ratio = 10;
        $s->save();

        // 영업이익 3,300,000 × 10% = 330,000 (tier 20% 였다면 660,000)
        $this->assertSame(330_000, $s->fresh()->settlement_amount);
    }

    /** 사내직원(per_unit)은 이익률과 무관하게 건당 정산이다. */
    public function test_employee_is_paid_per_unit_not_by_tier(): void
    {
        $s = $this->make();
        $s->settlement_type = 'per_unit';
        $s->per_unit_amount = 150_000;
        $s->save();

        $this->assertSame(150_000, $s->fresh()->settlement_amount);
    }

    /** tier 경계는 배타적이다 — 정확히 6%면 20%, 그 아래면 15%. */
    public function test_tier_boundary_is_exclusive_at_six_percent(): void
    {
        $this->assertSame(20, Settlement::karabaTierPercent(6.0));
        $this->assertSame(15, Settlement::karabaTierPercent(5.99));
        $this->assertSame(15, Settlement::karabaTierPercent(5.0));
        $this->assertSame(10, Settlement::karabaTierPercent(4.99));
        $this->assertSame(0, Settlement::karabaTierPercent(null));
    }

    /** 영업이익이 0 이하면 비율을 뭘 넣든 0이다(손실을 담당자에게 물리지 않는다). */
    public function test_negative_profit_stays_zero_even_with_a_ratio(): void
    {
        $s = $this->make(['purchase_price' => 20_000_000, 'purchase_vat_amount' => 0]);
        $s->settlement_ratio = 50;
        $s->save();

        $this->assertSame(0, $s->fresh()->settlement_amount);
    }

    /** 편집 화면을 열면 비율칸에 tier 자동값이 채워진다(비어 있던 옛 정산도). */
    public function test_opening_the_panel_fills_the_ratio(): void
    {
        $s = $this->make();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->assertSet('settlement_ratio', 20);
    }

    /** 🚨 사람이 넣은 값을 덮지 않는다 — 덮으면 조정이 매번 되돌아간다. */
    public function test_opening_the_panel_keeps_an_edited_ratio(): void
    {
        $s = $this->make();
        $s->settlement_ratio = 12;
        $s->save();

        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->assertSet('settlement_ratio', 12.0);
    }

    /**
     * 🚨 **자동값 그대로 저장하면 DB 에 굳히지 않는다.**
     *
     * karaba 의 2차 정산은 **비용 보정**이 본업이라 영업이익이 자주 움직인다. 자동값을 숫자로
     * 굳히면 이익률이 내려가도 옛 요율이 이겨서 **정산액이 그대로 남는다**.
     * 실측(고치기 전): 22.8%(20%) 에서 저장 → 비용 보정으로 4.8%(10%) 가 됐는데 20% 로 남아 2배였다.
     * 화면을 열고 저장만 해도 그렇게 되므로 «사용자가 안 건드렸는데» 틀어진다.
     */
    public function test_auto_ratio_is_not_frozen_into_the_row(): void
    {
        $s = $this->make();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        // 화면을 열고(자동값 20 이 채워진다) 그냥 저장한다.
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->assertSet('settlement_ratio', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($s->fresh()->settlement_ratio, '자동값이 DB 에 굳었다 — tier 가 얼어붙는다');

        // 2차 정산에서 비용을 올려 이익률을 떨어뜨리면 요율이 따라 내려가야 한다.
        $s->vehicle->update(['cost_deregistration' => 2_600_000]);
        $fresh = $s->fresh()->load('vehicle');

        $this->assertLessThan(6, $fresh->karaba_profit_rate);
        $this->assertSame(10, $fresh->karaba_tier_percent);
        $this->assertSame((int) (floor($fresh->karaba_operating_profit * 10 / 100 / 10) * 10),
            $fresh->settlement_amount, '비용을 보정했는데 옛 요율로 계산된다');
    }

    /** 사람이 고친 값은 저장되고 유지된다(자동값과 다르면 override 로 본다). */
    public function test_edited_ratio_is_persisted(): void
    {
        $s = $this->make();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('settlement_ratio', 12)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12.0, (float) $s->fresh()->settlement_ratio);

        // 다시 열어도 자동값이 덮지 않는다.
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->assertSet('settlement_ratio', 12.0);
    }
}
