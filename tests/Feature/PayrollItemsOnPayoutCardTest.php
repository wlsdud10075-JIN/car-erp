<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Services\Payout\PersonPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 월정산 카드 펼침에 **기입된 급여 항목만** (jin 2026-10-14 «기본급·식대·자가운전·복리 이렇게 기입됐으면 그것만 보여야. 18개 다 기입되는 경우는 드무니까»).
 */
class PayrollItemsOnPayoutCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakdown_carries_only_entered_items_in_order(): void
    {
        $sm = Salesman::create(['name' => '김관리', 'type' => 'staff', 'is_active' => true]);
        PayrollEntry::replaceFor($sm->id, '2026-09', [
            ['label' => '복리', 'amount' => 240_000], ['label' => '기본급', 'amount' => 2_300_000], ['label' => '식대', 'amount' => 200_000],
            ['label' => '명절상여', 'amount' => 100_000, 'is_custom' => true],
        ]);

        $p = PersonPayoutBreakdown::build($sm, '2026-09', collect(), collect());

        $this->assertSame(['기본급', '식대', '복리', '명절상여'], array_column($p['payroll_items'], 'label'), '고정 항목 순서 → 직접 추가 순');
        $this->assertSame(2_840_000, $p['payroll']);
        $this->assertSame(2_840_000, $p['payout']);
    }

    public function test_card_lists_entered_items_and_nothing_else(): void
    {
        $base = ['salesman_id' => 1, 'name' => '김관리', 'type' => 'staff', 'type_label' => '관리', 'month' => '2026-09', 'count' => 0, 'vehicles' => [],
            'payroll' => 2_740_000, 'payroll_items' => [['label' => '기본급', 'amount' => 2_300_000], ['label' => '식대', 'amount' => 200_000], ['label' => '복리', 'amount' => 240_000]],
            'incentive' => 0, 'adj_manual' => 0, 'adj_carry' => 0, 'deposit' => null, 'tier' => false, 'margin_rate' => null, 'unsupported' => false,
            'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
            'settlement_pay' => 0, 'payout' => 2_740_000, 'total_margin' => 0, 'shipping' => 0, 'company_contribution' => null,
            'margin_after_pay' => null, 'excess_ratio' => null];

        $html = view('components.payout.person-card', ['person' => $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();

        foreach (['기본급', '식대', '복리'] as $label) {
            $this->assertStringContainsString('data-payroll-item="'.$label.'"', $html);
        }
        $this->assertStringNotContainsString('data-payroll-item="자가운전"', $html, '기입 안 한 항목은 안 그린다');
        $this->assertSame(3, substr_count($html, 'data-payroll-item='));

        // 옛 박제(payroll_items 키 없음)도 그대로 그려진다
        unset($base['payroll_items']);
        $old = view('components.payout.person-card', ['person' => $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringNotContainsString('data-payroll-item=', $old);
    }
}
