<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use App\Services\SettlementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 **2차 차액 분해 — 1차 지급액 → 환차분 → 2차 차액(비용) → 이월금액(최종)** (jin 2026-10-06).
 *
 * jin: *「1차 정산에서 실지급액 준 거 대비 +,- 가 되어서 차액이 표시되는 행이 보여지면 좋겠고, 결국은 환차, 2차 차액,
 *      이월금액(최종) 이렇게 되는 그림 … 월정산 하기 전까지 실무자가 얼마나 차액이 발생되는지 몰라서」*.
 *
 * 단일 출처 = `Settlement::secondaryBreakdown()`. 드로어·행·담당자 카드·엑셀이 전부 이것을 그린다.
 * 🔑 **분해의 합 = 전체 차액**(닫힘, §8 #64) · 마감하면 박제돼 뒤늦은 입금에도 안 움직인다.
 */
class SettlementSecondaryBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    /** EUR 10,000 판매 · 7,000 확정 입금(미수 3,000) · 지급(paid, 스냅샷) · 2차 대기. 프리랜서 50%. */
    private function paid(string $type = 'ratio', string $plate = '14더3753'): array
    {
        $sm = Salesman::create(['name' => $type === 'ratio' ? '프리' : '직원', 'type' => $type === 'ratio' ? 'freelance' : 'employee', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => '2026-07-01',
            'sale_price' => 10_000, 'sale_date' => '2026-07-10',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 7_000, 'exchange_rate' => 1500, 'payment_date' => '2026-07-20', 'confirmed_at' => now()]);
        $v->refresh()->refreshCaches();

        Settlement::$allowBatchPayout = true;
        $s = Settlement::create(array_merge([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => $type,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-07-01',
        ], $type === 'ratio' ? ['settlement_ratio' => 50] : ['per_unit_amount' => 100_000]));
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        return [$v->fresh(), $s->fresh()];
    }

    /** 지급 직후엔 전부 0. 비용(탁송비) 100,000 기입 → 2차 차액 = −100,000 × 0.9 × 50% = −45,000, 환차 0, 합 −45,000. */
    public function test_cost_entry_shows_up_as_the_second_close_delta_exactly(): void
    {
        [$v, $s] = $this->paid();
        $bd = $s->secondaryBreakdown();
        $this->assertSame(['fx' => 0, 'cost' => 0, 'other' => 0, 'total' => 0], array_intersect_key($bd, array_flip(['fx', 'cost', 'other', 'total'])));
        $this->assertFalse($bd['frozen']);
        $this->assertSame((int) $s->confirmed_snapshot['actual_payout'], $bd['base']);

        $v->update(['cost_towing' => 100_000]);
        $bd = $s->fresh()->secondaryBreakdown();
        $this->assertSame(-45_000, $bd['cost'], '비용 100,000 × 0.9 × 50% 가 2차 차액이어야 한다');
        $this->assertSame(0, $bd['fx']);
        $this->assertSame(0, $bd['other']);
        $this->assertSame(-45_000, $bd['total']);
    }

    /**
     * 🔑 **닫힘** — 환율이 움직이고(나머지 3,000 EUR 를 1,600 에 받음) 비용도 바뀐 뒤에도 환차 + 비용 + 기타 = 전체 차액.
     *    환차분은 양수(받은 환율이 판매환율보다 높다), 비용분은 음수, 기타는 0(공제 변동 없음).
     */
    public function test_fx_and_cost_portions_add_up_to_the_whole_delta(): void
    {
        [$v, $s] = $this->paid();
        $finance = $this->finance();
        $v->update(['cost_towing' => 100_000]);
        $fp = $v->fresh()->finalPayments()->create(['type' => 'balance', 'amount' => 3_000, 'exchange_rate' => 1600, 'payment_date' => '2026-08-05']);
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);
        $v = $v->fresh();
        $v->refreshCaches();

        $s = $s->fresh();
        $bd = $s->secondaryBreakdown();
        $expectedTotal = (int) $s->actual_payout - (int) $s->confirmed_snapshot['actual_payout'];
        $this->assertSame($expectedTotal, $bd['total']);
        $this->assertSame($bd['total'], $bd['fx'] + $bd['cost'] + $bd['other'], '분해의 합이 전체 차액과 다르다');
        $this->assertGreaterThan(0, $bd['fx'], '1,600 에 받았으니 환차분은 양수여야 한다');
        $this->assertSame(-45_000, $bd['cost']);
        $this->assertSame(0, $bd['other']);
    }

    /** 사내직원(건당 고정, paid 때 동결)은 비용·환율이 바뀌어도 전부 0 — 회사 몫이다. */
    public function test_per_unit_employee_has_no_delta(): void
    {
        [$v, $s] = $this->paid('per_unit', '22나2222');
        $v->update(['cost_towing' => 300_000]);
        $bd = $s->fresh()->secondaryBreakdown();
        $this->assertSame(['fx' => 0, 'cost' => 0, 'other' => 0, 'total' => 0], array_intersect_key($bd, array_flip(['fx', 'cost', 'other', 'total'])));
    }

    /** 지급 전(스냅샷 없음)은 null — 화면은 「—」, 이월도 없다. */
    public function test_no_snapshot_means_no_breakdown(): void
    {
        $sm = Salesman::create(['name' => '프리', 'type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500, 'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => '2026-07-01', 'sale_price' => 10_000, 'sale_date' => '2026-07-10']);
        $s = Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending']);
        $this->assertNull($s->secondaryBreakdown());
    }

    /**
     * 🔒 마감하면 박제 — 분해가 컬럼에 남고, 미수가 남은 채 마감한 차에 뒤늦게 돈이 들어와도(환율 1,700) 값이 안 움직인다.
     *    실시간으로 다시 계산하면 바뀌는 값이다 — 그래서 저장값만 읽는지 「마감 뒤 입금」으로 확인한다.
     */
    public function test_closing_freezes_the_breakdown_against_later_payments(): void
    {
        [$v, $s] = $this->paid();
        $finance = $this->finance();
        $v->update(['cost_towing' => 100_000]);
        $this->actingAs($finance);
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);
        $s = $s->fresh();
        $this->assertSame('closed', $s->secondary_status);
        $frozen = $s->secondaryBreakdown();
        $this->assertTrue($frozen['frozen']);
        $this->assertSame(-45_000, $frozen['cost']);
        $this->assertSame(-45_000, (int) round((float) $s->secondary_cost_krw), '비용분이 컬럼에 박제되지 않았다');
        $this->assertSame((int) round((float) $s->carryover_out_krw), $frozen['total']);

        // 마감 뒤 미수 3,000 을 1,700 에 받는다 — 실시간이면 환차분이 크게 뛸 상황
        $fp = $v->fresh()->finalPayments()->create(['type' => 'balance', 'amount' => 3_000, 'exchange_rate' => 1700, 'payment_date' => '2026-11-05']);
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);
        $v->fresh()->refreshCaches();

        $after = $s->fresh()->secondaryBreakdown();
        $this->assertSame($frozen, $after, '마감 뒤 들어온 돈이 분해를 바꿨다 — 마감은 1회 확정이다');
        $this->assertNotSame($after['total'], (int) $s->fresh()->actual_payout - $after['base'], '실시간 값과는 달라야 박제가 증명된다');
    }

    /** 드로어에 4줄(1차 지급액·환차분·2차 차액·이월금액(최종))이 그려지고, 행에도 「이월 ±N」이 붙는다. */
    public function test_drawer_and_row_render_the_breakdown(): void
    {
        [$v, $s] = $this->paid();
        $v->update(['cost_towing' => 100_000]);
        $this->actingAs($this->finance());

        $html = Volt::test('erp.settlements.index')->set('monthFilter', '2026-07')->call('openEdit', $s->id)->html();
        $this->assertMatchesRegularExpression('/data-secondary-breakdown[\s\S]*?'.preg_quote(__('settlement.breakdown.base'), '/').'[\s\S]*?'
            .preg_quote(__('settlement.breakdown.fx'), '/').'[\s\S]*?'.preg_quote(__('settlement.breakdown.cost'), '/').'[\s\S]*?'
            .preg_quote(__('settlement.breakdown.total'), '/').'[\s\S]*?−₩45,000/u', $html, '드로어 분해 블록이 없거나 순서·값이 다르다');
        $this->assertMatchesRegularExpression('/data-row-carry[^>]*>\s*'.preg_quote(__('settlement.breakdown.row_label'), '/').'\s*−45,000/u', $html, '행의 이월 미리보기가 없다');
    }

    /** 엑셀 — 명세에 환차분(2차)·2차 차액(비용)·이월(최종), 요약에 미청산 이월·미반영 매입취소 손실. */
    public function test_excel_carries_the_breakdown_and_the_per_person_balances(): void
    {
        [$v, $s] = $this->paid();
        $v->update(['cost_towing' => 100_000]);
        $svc = new SettlementExportService;
        $labels = $svc->columnLabels();
        foreach (['환차분(2차)', '2차 차액(비용)', '이월(최종)'] as $l) {
            $this->assertContains($l, $labels, "명세 열 「{$l}」이 없다");
        }
        $book = $svc->build(Settlement::with('vehicle', 'salesman')->get());
        $detail = $book->getSheet(1);
        $col = 1;
        $found = [];
        while (($h = $detail->getCell([$col, 1])->getValue()) !== null && $h !== '') {
            $found[$h] = $detail->getCell([$col, 2])->getValue();
            $col++;
        }
        $this->assertSame(-45_000, (int) $found['2차 차액(비용)']);
        $this->assertSame(-45_000, (int) $found['이월(최종)']);

        $summary = $book->getSheetByName('요약');
        $heads = [];
        for ($c = 1; $c <= 9; $c++) {
            $heads[] = $summary->getCell([$c, 1])->getValue();
        }
        $this->assertSame('미청산 이월', $heads[7]);
        $this->assertSame('미반영 매입취소 손실', $heads[8]);
    }
}
