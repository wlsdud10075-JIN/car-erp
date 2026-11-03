<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 정산처리 목록 「정산방식」 비율 표시 (jin 2026-11-03 «프리랜서가 비율 0.0% 로 나오는데 버그인가?»).
 * 자동 건은 settlement_ratio 가 NULL 이라 0.0% 로 찍혔다 — 정산액 계산과 같은 규칙의 실제 적용 비율을 보인다
 * (건당 ₩0 을 고친 2026-10-30 과 같은 형태).
 */
class SettlementRatioDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function settlement(?float $ratio): Settlement
    {
        $sm = Salesman::create(['name' => '최딜러', 'type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '12가'.random_int(1000, 9999), 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 12_000_000, 'sale_date' => '2026-10-02',
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => $ratio,
            'settlement_status' => 'pending',
        ]);
    }

    public function test_auto_ratio_shows_the_default_not_zero(): void
    {
        $auto = $this->settlement(null);
        $this->assertSame((float) Settlement::FREELANCE_RATIO_DEFAULT, $auto->fresh()->applied_ratio);
        $this->assertSame(30.0, $this->settlement(30)->fresh()->applied_ratio, '직접 넣은 비율은 그대로');

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $html = Volt::actingAs($admin)->test('erp.settlements.index')->html();
        $this->assertStringContainsString(__('settlement.ratio_unit', ['ratio' => '50.0']), $html);
        $this->assertStringNotContainsString(__('settlement.ratio_unit', ['ratio' => '0.0']), $html, '자동 건이 0.0% 로 찍히면 안 된다');
    }

    public function test_karaba_auto_ratio_follows_the_profit_tier(): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
        Settlement::flushParamMemo();
        $s = $this->settlement(null)->fresh();

        $this->assertSame((float) $s->karaba_tier_percent, $s->applied_ratio, 'karaba 자동 건 = 이익률 구간표(정산액 계산과 같은 값)');
        $this->assertSame(17.5, $this->settlement(17.5)->fresh()->applied_ratio, '칸에 넣은 값이 있으면 그 값');
    }

    /** 바이어 7:3 → 「바이어 7:3 · 비율 30.0%」, 사내직원 차등 25% 구간 → 금액 옆 「(차등 · 총마진 25%)」 (jin 2026-11-03 «각 항목에 맞게»). */
    public function test_buyer_split_and_employee_tier_rate_are_labelled(): void
    {
        $buyerRatio = $this->settlement(30);
        $buyerRatio->forceFill(['ratio_source' => 'buyer'])->save();

        $emp = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '34나5678', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $emp->id, 'purchase_price' => 120_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 140_000_000, 'sale_date' => '2026-10-02',
        ]);
        $tier = Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $emp->id, 'settlement_type' => 'per_unit', 'settlement_status' => 'pending']);
        $this->assertSame(25, $tier->fresh()->per_unit_tier_rate, '매입합계 1억↑ = 총마진 × 25%');
        $low = $this->settlement(null);   // 비율 건은 차등 표시 없음
        $this->assertNull($low->fresh()->per_unit_tier_rate);

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $html = Volt::actingAs($admin)->test('erp.settlements.index')->html();
        // Livewire 가 @if 블록 사이에 주석 표식을 넣는다 — 표식은 건너뛰고 본다.
        $this->assertMatchesRegularExpression('/바이어 7:3 · \s*(<!--.*?-->\s*)*비율 30\.0%/u', $html);
        $this->assertStringContainsString(__('settlement.per_unit_tier_rate', ['rate' => 25]), $html);
    }
}
