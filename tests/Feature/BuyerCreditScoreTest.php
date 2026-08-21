<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerCreditScore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 신용도 (jin 2026-08-21) — **제안만 한다.**
 *
 * 🚨 이 테스트가 지키는 것 중 가장 중요한 건 「아무것도 자동으로 바뀌지 않는다」다.
 *    락은 차가 나가느냐 마느냐를 결정한다. 점수가 조용히 내려가 선적이 막히면 영업은 이유를 모른다.
 *
 * 배점은 jin 지정 30/30/20/20. 축 내부 구간은 운영 실측(heymanerp 2026-08-21)에 맞췄다:
 *   거래완료 R.S.H 60건 · 2위 15건 · 중앙 2건 / write_off 실질 0건 / 완납 중앙 8일·선입금 33%.
 */
class BuyerCreditScoreTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        // ⚠️ fresh() 로 되읽는다 — create() 직후 인스턴스는 지정하지 않은 컬럼이 attributes 에 없어서
        //    `effectiveUnsecuredLimit()` 의 미로드 가드에 걸린다. 실사용은 Buyer::find() 라 전부 실린다.
        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id])->fresh();
    }

    /**
     * @param  bool  $completed  거래완료로 만들 것인가 (B/L 발급 = v4 cascade 상 거래완료)
     * @param  int  $payDelayDays  판매일 대비 최종 입금일 (음수 = 선입금)
     */
    private function vehicle(Buyer $b, int $salePrice, int $paidKrw, bool $completed = false, int $payDelayDays = 5): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'CS'.++$this->n.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-06-01', 'purchase_price' => 10_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-06-10',
            'bl_document' => $completed ? 'bl/x.pdf' : null,
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => Carbon::parse('2026-06-10')->addDays($payDelayDays)->toDateString(),
                'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    // ── 평가 불가 = 0점이 아니다 ────────────────────────────────

    public function test_a_brand_new_buyer_is_not_scored_at_all(): void
    {
        $credit = BuyerCreditScore::for($this->buyer());

        $this->assertFalse($credit['available'],
            '거래 이력이 없는 바이어를 최하 등급으로 떨어뜨리면 첫 거래가 통째로 막힌다');
        $this->assertSame('-', $credit['grade']);
    }

    // ── 축별 계산 ──────────────────────────────────────────────

    /** 거래 이력은 **구간**이다 — 건수 비례로 주면 최대 거래처만 만점이고 나머지는 바닥이 된다. */
    public function test_trade_history_is_banded_not_proportional(): void
    {
        $small = $this->buyer();
        $this->vehicle($small, 10_000_000, 10_000_000, completed: true);
        $this->vehicle($small, 10_000_000, 10_000_000, completed: true);   // 2건

        $big = $this->buyer();
        for ($i = 0; $i < 8; $i++) {
            $this->vehicle($big, 10_000_000, 10_000_000, completed: true);   // 8건
        }

        $s = BuyerCreditScore::for($small)['axes']['trade'];
        $b = BuyerCreditScore::for($big)['axes']['trade'];

        $this->assertGreaterThan($s['score'], $b['score'], '거래가 많으면 점수가 높아야 한다');
        // 8건은 2건의 4배지만 점수는 4배가 아니어야 한다(구간이므로).
        $this->assertLessThan($s['score'] * 4, $b['score'],
            '건수 비례로 주면 최대 거래처가 독식하고 나머지는 변별이 안 된다');
    }

    /** 손실이 없으면 만점이고, **왜 만점인지**가 화면 문구에 담겨야 한다. */
    public function test_no_write_off_is_full_marks_and_says_why(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true);

        $axis = BuyerCreditScore::for($b)['axes']['loss'];

        $this->assertSame($axis['max'], $axis['score']);
        $this->assertStringContainsString('없음', $axis['why'],
            '전원이 만점인 축이라 이유를 안 찍으면 총점이 실제보다 근거 있어 보인다');
    }

    public function test_write_off_caps_the_grade(): void
    {
        $b = $this->buyer();
        // 거래·입금은 완벽하지만 손실 이력이 있는 바이어
        for ($i = 0; $i < 25; $i++) {
            $v = $this->vehicle($b, 10_000_000, 10_000_000, completed: true, payDelayDays: -3);
        }
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'write_off', 'amount' => 3_000_000,
            'collected_at' => '2026-07-01',
        ]);

        $credit = BuyerCreditScore::for($b->fresh());

        $this->assertTrue($credit['capped_by_loss'], '손실 이력은 거래량으로 희석되면 안 된다');
        $this->assertContains($credit['grade'], ['C', 'D', 'E']);
    }

    /** 지급 행태는 **완납 차량**만 본다 — 미납은 익스포저 축이 이미 세므로 이중 계상이 된다. */
    public function test_payment_behaviour_only_looks_at_settled_vehicles(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true, payDelayDays: 3);
        $this->vehicle($b, 10_000_000, 1_000_000);   // 미납 — 표본에 들어가면 안 된다

        $facts = BuyerCreditScore::facts($b->fresh());

        $this->assertSame(1, $facts['paid_sample']);
        $this->assertSame(3, $facts['paid_median_days']);
    }

    // ── 배점 ───────────────────────────────────────────────────

    public function test_weights_default_to_jins_numbers(): void
    {
        $this->assertSame(['trade' => 30, 'loss' => 30, 'exposure' => 20, 'payment' => 20],
            BuyerCreditScore::weights());
    }

    public function test_super_can_change_the_weights_and_it_moves_the_score(): void
    {
        $b = $this->buyer();
        // 거래는 얕고(1건) 손실은 없는 바이어 — 두 축의 가중치를 뒤집으면 점수가 크게 움직인다.
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true);

        $before = BuyerCreditScore::for($b)['score'];

        Setting::updateOrCreate(
            ['key' => 'credit_score_weights_'.Setting::companyTemplateSet()],
            ['value' => json_encode(['trade' => 70, 'loss' => 10, 'exposure' => 10, 'payment' => 10]), 'type' => 'string'],
        );

        $after = BuyerCreditScore::for($b)['score'];
        $this->assertNotSame($before, $after, '배점을 바꿨는데 점수가 그대로면 설정이 안 먹는 것');
    }

    /** 합이 100 이 아니어도 그 합으로 환산한다 — 한 축만 올리고 싶을 수 있다. */
    public function test_weights_need_not_sum_to_100(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true);

        Setting::updateOrCreate(
            ['key' => 'credit_score_weights_'.Setting::companyTemplateSet()],
            ['value' => json_encode(['trade' => 60, 'loss' => 60, 'exposure' => 40, 'payment' => 40]), 'type' => 'string'],
        );

        $score = BuyerCreditScore::for($b)['score'];
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    // ── 🚨 아무것도 자동으로 바뀌지 않는다 ─────────────────────

    public function test_apply_only_fills_the_inputs_and_never_writes_to_the_database(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true);
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);

        $c = Volt::actingAs($super)->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('applyRecommendedLocks');

        $recommended = BuyerCreditScore::for($b->fresh())['recommended'];
        $c->assertSet('lock_shipping_entry_pct_str', (string) $recommended['shipping_entry']);

        $this->assertNull($b->fresh()->lock_shipping_entry_pct,
            '[적용]이 DB 까지 쓰면 사람이 확인할 기회 없이 락이 바뀐다 — 저장은 [저장]이 해야 한다');
    }

    public function test_scoring_a_buyer_never_changes_their_locks(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 0);   // 미수 100% — 점수가 낮게 나올 상황

        BuyerCreditScore::for($b);

        $b->refresh();
        $this->assertNull($b->lock_shipping_entry_pct, '점수 계산이 컬럼을 건드리면 안 된다');
        $this->assertNull($b->lock_purchase_registration_pct);
    }

    public function test_non_super_cannot_apply(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 10_000_000, 10_000_000, completed: true);
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        Volt::actingAs($admin)->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('applyRecommendedLocks')
            ->assertStatus(403);
    }

    /** B 등급 권장값이 현행 전역값과 같아야 한다 — 평범한 바이어에게 갑자기 다른 기준을 권하면 안 된다. */
    public function test_grade_b_recommendation_matches_the_current_global_defaults(): void
    {
        $this->assertSame(60, BuyerCreditScore::RECOMMENDED['B']['shipping_entry']);
        $this->assertSame(50, BuyerCreditScore::RECOMMENDED['B']['purchase_registration']);
    }

    /** 신용이 좋을수록 요구 입금률이 낮아야 한다(느슨해야 한다) — 방향이 뒤집히면 정반대로 작동한다. */
    public function test_better_grades_get_looser_thresholds(): void
    {
        $prev = null;
        foreach (['A', 'B', 'C', 'D', 'E'] as $grade) {
            foreach (['shipping_entry', 'purchase_registration'] as $lock) {
                $v = BuyerCreditScore::RECOMMENDED[$grade][$lock];
                if ($prev !== null && isset($prev[$lock])) {
                    $this->assertGreaterThan($prev[$lock], $v,
                        "{$grade} 등급의 {$lock} 권장값이 상위 등급보다 낮다 — 신용이 나쁠수록 엄격해야 한다");
                }
            }
            $prev = BuyerCreditScore::RECOMMENDED[$grade];
        }
    }
}
