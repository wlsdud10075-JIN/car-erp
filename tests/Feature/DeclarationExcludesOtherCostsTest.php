<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 면장금액은 기타 판매비용을 따라가지 않는다 (jin 2026-08-27).
 *
 * B/L 재발급 수수료(55·68·100달러)처럼 **완납 뒤에 생기는 비용**을 「기타 판매비용」에 넣으면
 *   받을 돈(총판매가·미수)  → 늘어야 한다
 *   세관 신고 금액(면장)     → 그대로여야 한다   ← 이미 신고를 마쳤으므로
 *
 * 두 숫자가 한 글자 차이라 헷갈리기 쉬워 여기서 못박는다.
 *   총판매가   = 판매가 + 운임비 + **기타판매비용** + 커미션 + AutoLoading − TAX D/C
 *   면장 기준액 = 판매가 + 운임비 +                커미션 + AutoLoading − TAX D/C
 *
 * 운임비는 CIF 신고에 들어가므로 **면장에 남는다** — 기타 판매비용만 뺀다.
 */
class DeclarationExcludesOtherCostsTest extends TestCase
{
    use RefreshDatabase;

    private function soldVehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'DECL BUYER', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => 'DECL-'.fake()->unique()->numberBetween(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1700,
            'dhl_request' => false, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-08-01', 'sale_price' => 15000, 'transport_fee' => 1500,
        ])->fresh();
    }

    public function test_declaration_follows_the_sale_total_when_there_is_no_other_cost(): void
    {
        // 지금 동작 — 기타 판매비용이 0이면 두 값이 같아야 한다(기존 차량 전부가 이 상태다).
        $v = $this->soldVehicle();

        $this->assertSame(16500.0, (float) $v->sale_total_amount);
        $this->assertSame(16500.0, (float) $v->declaration_base_amount);
        $this->assertEqualsWithDelta(16500, (float) $v->export_declaration_amount, 0.01);
    }

    public function test_adding_an_other_cost_raises_the_receivable_but_not_the_declaration(): void
    {
        $v = $this->soldVehicle();
        $declBefore = (float) $v->export_declaration_amount;

        $v->update(['sale_other_costs' => 55]);   // B/L 재발급비
        $v->refresh();

        $this->assertSame(16555.0, (float) $v->sale_total_amount, '받을 돈이 안 늘었다');
        $this->assertSame(16555.0, (float) $v->sale_unpaid_amount, '미수가 안 늘었다');
        $this->assertEqualsWithDelta($declBefore, (float) $v->export_declaration_amount, 0.01,
            '면장금액이 기타 판매비용을 따라 올라갔다');
    }

    public function test_declaration_still_follows_a_sale_price_change(): void
    {
        // 기타 판매비용이 붙은 뒤에도 판매가를 고치면 면장은 정상적으로 따라와야 한다.
        $v = $this->soldVehicle();
        $v->update(['sale_other_costs' => 55]);

        $v->update(['sale_price' => 16000]);
        $v->refresh();

        $this->assertSame(17555.0, (float) $v->sale_total_amount);
        $this->assertEqualsWithDelta(17500, (float) $v->export_declaration_amount, 0.01,
            '면장이 새 판매가를 안 따라옴 (17,500 = 16,000 + 운임 1,500)');
    }

    public function test_transport_fee_stays_in_the_declaration(): void
    {
        // 운임비는 CIF 신고 금액에 들어간다 — 같이 빼면 안 된다.
        $v = $this->soldVehicle();

        $v->update(['transport_fee' => 2000]);
        $v->refresh();

        $this->assertEqualsWithDelta(17000, (float) $v->export_declaration_amount, 0.01);
    }

    public function test_a_manually_entered_declaration_is_never_touched(): void
    {
        // CIF/FOB 를 손으로 넣은 차는 무슨 일이 있어도 보존한다(기존 규칙).
        $v = $this->soldVehicle();
        $v->update(['export_declaration_amount' => 12345]);

        $v->update(['sale_other_costs' => 55, 'sale_price' => 16000]);
        $v->refresh();

        $this->assertEqualsWithDelta(12345, (float) $v->export_declaration_amount, 0.01);
    }

    public function test_sync_command_uses_the_same_base(): void
    {
        // 🚫 저장 훅과 보정 커맨드가 다른 기준을 쓰면 서로 되돌린다.
        $source = file_get_contents(app_path('Console/Commands/SyncDeclarationAmount.php'));

        $this->assertStringContainsString('declaration_base_amount', $source);
        $this->assertStringNotContainsString('$v->sale_total_amount', $source,
            '보정 커맨드가 아직 총판매가를 기준으로 쓴다');
    }
}
