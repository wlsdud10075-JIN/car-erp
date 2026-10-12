<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 월정산 v3 5일차 — 사람 카드가 세 화면(정산관리 담당자별 합계 미리보기 · 월정산 · 승인 링크)에 **같은 컴포넌트**로 뜬다.
 * 숫자 검증은 PersonPayoutBreakdownTest. 여기는 「그 화면에 그 카드가 그 값으로 그려지나」만 본다.
 */
class PayoutCardScreensTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $v = Vehicle::create([
            'vehicle_number' => 'CARD1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $e->id, 'purchase_price' => 100_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 100_000, 'sale_date' => '2026-10-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 100_000, 'exchange_rate' => 1400, 'payment_date' => '2026-10-10', 'confirmed_at' => now()]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $e->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ]);
        PayrollEntry::replaceFor($e->id, '2026-10', [['label' => '기본급', 'amount' => 2_740_000]]);

        return [$admin, $e, $s];
    }

    public function test_settlement_summary_card_shows_the_v3_preview(): void
    {
        [$admin, $e] = $this->fixture();
        $this->actingAs($admin);

        $html = Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->call('toggleSummaries')->html();

        $this->assertStringContainsString('data-v3-preview', $html, '담당자별 합계 카드에 v3 미리보기가 없다');
        $this->assertMatchesRegularExpression('/data-person-card="'.$e->id.'" data-person-type="employee"/', $html);
        $this->assertStringContainsString(__('payout_card.incentive_preview'), $html, '미리보기는 인센티브를 「제출 때 입력」으로 표시해야 한다');
        // 실지급 = 급여 2,740,000 + 정산금 100,000. 🔀 2026-10-12 — 사내직원 카드엔 환산 합계·급여공제후 마진·초과 배율이 없다(jin).
        $this->assertStringContainsString('data-payout>₩2,840,000', $html);
        $this->assertStringContainsString(__('payout_card.profit_multiple'), $html, '이익배수가 없다');
        $this->assertStringContainsString('data-profit-multiple>', $html);
        $this->assertStringNotContainsString('data-equiv-total', $html, '사내직원 카드에 프리랜서 환산 합계가 다시 들어갔다');
        $this->assertStringNotContainsString('급여공제후 마진', $html, '급여공제후 마진이 다시 보인다');
    }

    public function test_card_component_renders_each_type_from_a_breakdown_array(): void
    {
        $base = ['salesman_id' => 9, 'name' => '표본', 'month' => '2026-10', 'count' => 0, 'vehicles' => [], 'payroll' => null,
            'incentive' => 0, 'adj_manual' => 0, 'adj_carry' => 0, 'deposit' => null, 'tier' => false, 'margin_rate' => null, 'unsupported' => false,
            'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
            'settlement_pay' => 0, 'payout' => 0, 'total_margin' => 0, 'shipping' => 0, 'company_contribution' => null,
            'margin_after_pay' => null, 'excess_ratio' => null, 'share' => null];

        $inspector = view('components.payout.person-card', ['person' => ['type' => 'inspector', 'payout' => 2_450_000] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringContainsString(__('payout_card.inspector_in_net_v'), $inspector, '검차직원 카드는 공통 인건비 안내');
        $this->assertStringContainsString('data-payroll-missing', $inspector);

        $employee = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 5_840_000, 'equiv_total' => 21_501_356, 'equiv_sale_rate' => 21_560_612,
            'fx_primary' => -91_431, 'carry' => 32_175, 'margin_after_pay' => 15_661_356, 'excess_ratio' => 2.7, 'company_contribution' => 40_000_000, 'share' => 58.4,
            'payroll' => 2_740_000, 'settlement_pay' => 3_100_000, 'total_margin' => 46_000_000, 'count' => 31] + $base, 'mode' => 'batch', 'open' => true, 'changed' => ['incentive']])->render();
        // 이익배수 = (총마진 46,000,000 − 급여 2,740,000 − 정산금 3,100,000 − 발송비 0) ÷ (2,740,000 + 3,100,000) = 40,160,000 ÷ 5,840,000 = +6.9배.
        //   배열에 profit_multiple 키가 **없다** = 옛 박제 모양 → 카드가 payroll·settlement_pay·total_margin·shipping 으로 되살린다.
        $this->assertStringContainsString('+6.9'.__('payout_card.times'), $employee, '배수는 부호를 항상 붙인다(옛 박제 폴백)');
        $this->assertStringContainsString('data-total-margin', $employee, '총마진 줄이 강조돼야 한다');
        $this->assertStringContainsString('data-payout-expanded', $employee, '실지급액 줄이 강조돼야 한다');
        $this->assertStringNotContainsString('+2.7'.__('payout_card.times'), $employee, '구 초과 배율(환산 기준)이 다시 그려졌다');
        $this->assertStringContainsString('58.4%', $employee);
        $this->assertStringContainsString('data-changed', $employee, '바뀐 칸이 있으면 「변경됨」 뱃지');
        $this->assertStringNotContainsString('−91,431', $employee, '사내직원 카드에 ① 1차 환차가 다시 보인다');
        $this->assertStringContainsString('−5,840,000', $employee, '왼쪽은 총마진 − 실지급액 한 줄');
        $this->assertStringNotContainsString('−2,740,000', $employee, '급여를 왼쪽에서 따로 빼지 않는다(jin 3차 «총마진 − 실지급액 = 회사이익 이렇게만»)');

        // 「− 항목」 줄은 부호가 하나다 — 수기 +3,600,000(무사백 10월 추가금 모양)은 「−3,600,000」, 음수 이월 −200,000 은 「+200,000」.
        //   🔑 조정·인센티브를 넣어도 이익배수는 그대로 +6.9배 — 인센티브를 정하는 근거라 결과에 따라 움직이면 안 된다(jin 2026-10-12).
        $adjusted = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 9_540_000, 'company_contribution' => 36_460_000,
            'payroll' => 2_740_000, 'settlement_pay' => 3_100_000, 'adj_manual' => 3_600_000, 'adj_carry' => -200_000, 'incentive' => 300_000, 'total_margin' => 46_000_000, 'count' => 31] + $base,
            'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringContainsString('+6.9'.__('payout_card.times'), $adjusted, '인센티브·조정을 줘도 이익배수는 안 움직인다');
        $this->assertStringContainsString('−9,540,000', $adjusted, '실지급 차감 줄(조정·인센티브 포함)');
        $this->assertStringContainsString('+3,600,000', $adjusted, '수기 조정은 오른쪽 지급 내역에');
        $this->assertStringContainsString('−200,000', $adjusted, '음수 이월은 오른쪽 지급 내역에');
        $this->assertStringNotContainsString('−+', $adjusted, '부호가 두 개 찍혔다');
        $this->assertStringNotContainsString('−−', $adjusted, '부호가 두 개 찍혔다');
        $this->assertStringNotContainsString('−-', $adjusted, '부호가 두 개 찍혔다');

        // 차량별 보기 = 머리글 정렬 표(jin 2026-10-12): 차량번호 / 총마진 / 마진율 / 직책 / 건당금액. 행에 정렬 키가 data- 로 박힌다.
        $withVehicles = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 2_840_000, 'company_contribution' => 1_000_000,
            'payroll' => 2_740_000, 'settlement_pay' => 100_000, 'total_margin' => 3_840_000, 'count' => 1,
            'vehicles' => [['id' => 1, 'vehicle_number' => '12가3456', 'actual_payout' => 100_000, 'total_margin' => 3_840_000, 'margin_rate' => 0.21, 'is_domestic' => false, 'type_label' => '사내직원 건당']]] + $base,
            'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        foreach (['vh_vehicle', 'total_margin', 'vh_type', 'vh_payout'] as $k) {
            $this->assertStringContainsString(__('payout_card.'.$k), $withVehicles, "차량별 보기 머리글 $k 가 없다");
        }
        $this->assertStringContainsString('data-vehicle-sort="total_margin"', $withVehicles, '총마진 머리글이 정렬 버튼이어야 한다');
        $this->assertStringContainsString('data-total_margin="3840000"', $withVehicles, '행에 정렬 키 값이 박혀야 한다');
        $this->assertStringContainsString('사내직원 건당', $withVehicles);

        // 키가 있으면 서비스 값이 우선(박제된 새 배치).
        $keyed = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 5_840_000, 'profit_multiple' => 9.9, 'company_contribution' => 40_000_000,
            'payroll' => 2_740_000, 'settlement_pay' => 3_100_000, 'total_margin' => 46_000_000, 'count' => 31] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringContainsString('+9.9'.__('payout_card.times'), $keyed);

        $negative = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 2_800_000, 'equiv_total' => 560_000, 'equiv_sale_rate' => 560_000,
            'fx_primary' => 0, 'carry' => 0, 'margin_after_pay' => -2_240_000, 'excess_ratio' => -0.8, 'company_contribution' => -1_620_000, 'share' => -2.3,
            'payroll' => 2_600_000, 'settlement_pay' => 200_000, 'total_margin' => 1_200_000, 'count' => 2] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        // (1,200,000 − 2,600,000 − 200,000) ÷ 2,800,000 = −1,600,000 ÷ 2,800,000 = −0.6배
        $this->assertStringContainsString('−0.6'.__('payout_card.times'), $negative, '마이너스 배율은 「−」 로 보인다(색만으로 가르지 않는다)');
        $this->assertStringContainsString('−2.3%', $negative, '마이너스 지분율도 「−」');
        // 총마진이 음수면 그 줄도 회사이익처럼 빨간 배경(jin 2026-10-12) — 양수는 초록
        $this->assertMatchesRegularExpression('/bg-red-50 text-red-700" data-total-margin>/', view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 2_800_000, 'company_contribution' => -11_872_763,
            'payroll' => null, 'settlement_pay' => 2_800_000, 'total_margin' => -9_072_763, 'count' => 49] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render(), '음수 총마진 줄이 빨강이 아니다');
        $this->assertMatchesRegularExpression('/bg-emerald-50 text-emerald-800" data-total-margin>/', $employee, '양수 총마진 줄은 초록');
    }
}
