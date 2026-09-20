<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📊 **마진율 · 환차 표시** (jin 2026-09-18).
 *
 * jin: *「차량 하나하나에 관한 마진율이 표시되며, 그 담당자의 총 마진율은 몇이다. 이게 나와야해.」*
 *      *「내수는 마진률이 없지. 그냥 넣지말아버려. 그것도 마진률의 평균에도 들어가지않게.」*
 *      *「1차정산 이후부터는 환차가 있어야 할텐데?? 다시 한번 봐줄래?」*
 *
 * 공식 근거 = 정본 `## 2026 수출 정산_26.07.xlsx` 인원 탭 **CK 열** (`CH / CC`), 합계행은 `CH합 / CC합`.
 *
 * 🚫 **7월 엑셀과 숫자를 맞추려 하지 말 것** — ERP 7월 귀속분은 소급 적재라 판매환율로 폴백해
 *    엑셀 CC 와 분모가 다르다(설계상 그렇다, 기획서 §4⑤). 여기서 지키는 것은 **식의 성질**이다.
 */
class SettlementMarginRateTest extends TestCase
{
    use RefreshDatabase;

    private function salesman(string $name = '담당'): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'freelance', 'is_active' => true]);
    }

    /**
     * 외화 미완납 차량 — 정산환율이 판매환율로 폴백해 계산이 예측 가능해진다.
     * 총마진 = ((판매금원화 − 매입) + 매입×9%) × 0.9
     */
    private function vehicle(Salesman $sm, int $salePrice, int $purchase, string $plate): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $plate,
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1000,
            'salesman_id' => $sm->id,
            'purchase_price' => $purchase,
            'purchase_date' => '2026-08-01',
            'sale_price' => $salePrice,
            'sale_date' => '2026-09-01',
        ]);
    }

    private function settlement(Vehicle $v, array $attrs = []): Settlement
    {
        return Settlement::create(array_merge([
            'vehicle_id' => $v->id,
            'salesman_id' => $v->salesman_id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
        ], $attrs));
    }

    // ── 차량 한 대 ──────────────────────────────────────────────────────

    /** 엑셀 CK 그대로 — 총마진 ÷ 판매금원화. 비율로 돌려준다(화면에서 % 로 그린다). */
    public function test_one_vehicle_matches_total_margin_over_sales_amount(): void
    {
        $s = $this->settlement($this->vehicle($this->salesman(), 10_000, 5_000_000, '11가1111'));

        $this->assertSame(10_000_000, $s->sales_amount_krw);
        $this->assertSame(4_905_000, $s->total_margin);   // ((10,000,000−5,000,000)+450,000)×0.9
        $this->assertEqualsWithDelta(0.4905, $s->margin_rate, 1e-9);
    }

    /** 판매금원화 0 → 「−」. 🚫 0% 로 그리면 합계를 눈으로 검산할 때 어긋난다. */
    public function test_zero_denominator_is_null_not_zero(): void
    {
        $sm = $this->salesman();
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'currency' => 'EUR',
            'exchange_rate' => 1000, 'salesman_id' => $sm->id, 'purchase_price' => 3_000_000,
        ]);

        $this->assertNull($this->settlement($v)->margin_rate);
    }

    /** 🏠 내수는 마진율 자체가 없다 — 「−」 (jin 2026-09-18). */
    public function test_domestic_has_no_margin_rate(): void
    {
        $s = $this->settlement(
            $this->vehicle($this->salesman(), 10_000, 5_000_000, '33다3333'),
            ['is_domestic' => true]
        );

        $this->assertNull($s->margin_rate, '내수에 마진율이 붙었다');
    }

    // ── 합계 ────────────────────────────────────────────────────────────

    /**
     * 🚨 **합계는 평균이 아니다** — 금액이 100배 차이나는 두 대로 드러낸다.
     *    평균을 쓰면 소액 차량이 과대 반영돼 숫자가 통째로 달라진다.
     */
    public function test_the_subtotal_is_weighted_not_averaged(): void
    {
        $sm = $this->salesman();
        $big = $this->settlement($this->vehicle($sm, 10_000, 9_000_000, '44라4444'));
        $small = $this->settlement($this->vehicle($sm, 100, 10_000, '55마5555'));

        $rows = collect([$big, $small]);

        // 가중 = Σ총마진 / Σ판매금원화
        $expected = ($big->total_margin + $small->total_margin)
            / ($big->sales_amount_krw + $small->sales_amount_krw);
        $average = ($big->margin_rate + $small->margin_rate) / 2;

        $this->assertEqualsWithDelta($expected, Settlement::marginRateOf($rows), 1e-9);
        $this->assertGreaterThan(0.2, abs($average - $expected),
            '두 방식이 충분히 안 갈려 이 테스트가 아무것도 안 지킨다 — 표본을 더 벌릴 것');
    }

    /**
     * 🔑 **「−」로 그려진 줄은 소계를 1원도 안 움직인다** — 분자·분모 **양쪽**에서 빠진다.
     *    한쪽에서만 빼면 마진율이 부풀거나(분모만) 깎인다(분자만).
     */
    public function test_a_dashed_row_never_moves_the_subtotal(): void
    {
        $sm = $this->salesman();
        $export = $this->settlement($this->vehicle($sm, 10_000, 5_000_000, '66바6666'));
        $alone = Settlement::marginRateOf(collect([$export]));

        $domestic = $this->settlement(
            $this->vehicle($sm, 8_000, 1_000_000, '77사7777'),
            ['is_domestic' => true]
        );
        $withDomestic = Settlement::marginRateOf(collect([$export, $domestic]));

        $this->assertNotNull($alone);
        $this->assertEqualsWithDelta($alone, $withDomestic, 1e-9, '내수가 소계를 움직였다');
    }

    /** 모수가 통째로 비면 null — 「−」. (내수만 있는 담당자) */
    public function test_all_dashed_gives_null(): void
    {
        $s = $this->settlement(
            $this->vehicle($this->salesman(), 10_000, 5_000_000, '88아8888'),
            ['is_domestic' => true]
        );

        $this->assertNull(Settlement::marginRateOf(collect([$s])));
        $this->assertNull(Settlement::marginRateOf(collect([])));
    }

    // ── 환차 표시 ───────────────────────────────────────────────────────

    /**
     * 🚨 **미완납이면 환차를 말하지 않는다** — 미리보기 식은 덜 받은 돈을 그대로 마이너스로 뱉는다.
     *    그걸 「환차」로 인쇄하면 미수가 환차로 둔갑한다. 실측 ssancarerp 2026-08 배치에
     *    그런 차가 **155건**(미수 지급보류 게이트 예외분)이었다.
     */
    public function test_an_unpaid_vehicle_shows_no_exchange_difference(): void
    {
        $s = $this->settlement($this->vehicle($this->salesman(), 10_000, 5_000_000, '99자9999'));

        $this->assertGreaterThan(0, $s->vehicle->sale_unpaid_amount, '표본이 미완납이 아니다');
        $this->assertNull($s->display_exchange_difference,
            '미완납 차에 환차가 찍혔다 — 미수가 환차로 둔갑한다');

        // 계산 본체는 여전히 값을 낸다(2차 마감 게이트 예외 경로가 그걸 쓴다).
        $this->assertNotNull($s->computeExchangeDifference());
    }

    /** ✅ 완납이면 1차만 된 상태에서도 환차가 보인다 — jin 이 지적한 그 자리. */
    public function test_a_fully_paid_vehicle_shows_the_preview_before_close(): void
    {
        $sm = $this->salesman();
        $v = $this->vehicle($sm, 10_000, 5_000_000, '10가1010');
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1050,
            'payment_date' => '2026-09-05', 'confirmed_at' => now(),
        ]);
        $v->refresh();
        $s = $this->settlement($v);

        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '표본이 완납이 아니다');
        // 실입금 10,000×1050 = 10,500,000 / baseline 10,000×1000 = 10,000,000
        $this->assertEqualsWithDelta(500_000.0, $s->display_exchange_difference, 0.01);
        $this->assertTrue($s->isExchangeDifferencePreview(), '마감 전인데 확정값으로 표시된다');
    }

    /** 마감된 건은 **저장값 그대로** — 미리보기로 덮지 않는다(그 뒤 들어온 돈은 반영 대상이 아니다). */
    public function test_a_closed_settlement_keeps_its_stored_value(): void
    {
        $s = $this->settlement($this->vehicle($this->salesman(), 10_000, 5_000_000, '12나1212'), [
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);
        $s->forceFill(['secondary_status' => 'closed', 'exchange_difference_krw' => 12_345])->save();

        $this->assertEqualsWithDelta(12_345.0, $s->fresh()->display_exchange_difference, 0.01);
        $this->assertFalse($s->fresh()->isExchangeDifferencePreview());
    }

    /**
     * 🔒 **화면과 모델이 같은 환차를 말한다** — 정산관리 편집 패널의 KRW 명세는 이제 모델 식을 쓴다.
     *    둘이 갈리면 「패널 3,000원 ↔ 엑셀 2,900원」이 된다(§8 #44·#45).
     *    ⚠️ 식을 옮겨 적었는지는 문자열 검사로 못 잡는다 — **두 값을 실제로 비교**한다.
     */
    public function test_the_settlement_panel_and_the_model_agree(): void
    {
        $sm = $this->salesman();
        $v = $this->vehicle($sm, 10_000, 5_000_000, '13다1313');
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1050,
            'payment_date' => '2026-09-05', 'confirmed_at' => now(),
        ]);
        $v->refresh();
        $s = $this->settlement($v);

        $this->actingAs(User::factory()->create([
            'permission' => 'admin', 'email_verified_at' => now(),
        ]));

        $kb = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->instance()->krwBreakdown();

        $this->assertEqualsWithDelta(
            (float) $s->computeExchangeDifference(), (float) $kb['exchange_diff'], 0.01,
            '편집 패널이 모델과 다른 환차를 말한다'
        );
    }
}
