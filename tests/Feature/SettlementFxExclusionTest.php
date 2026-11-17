<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\PersonPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 💱 정산에서 환차 제외 (jin 2026-11-17 «12월 1~30일 처리분을 1월 10일 정산 — 이때 정산되는 애들부터 환차익 없는 걸로.
 *    12월 10일 지급분까지는 환차익이 있음. 회사별 토글»).
 *
 * 기준 = 정산 귀속월 ≥ 기능설정 「시작 귀속월」 → 판매금원화를 판매환율로. 지키는 것:
 *   ① 설정이 비면 종전 그대로(실효 입금환율) ② 귀속월 11월은 환차 포함, 12월부터 제외
 *   ③ 지급(paid)·배치 제출 때 박제 — 나중에 설정을 바꿔도 지급 끝난 숫자가 안 움직인다
 *
 * 숫자 = 18누0304(SettlementReceiptRateTest): 입금환율 1718 → 실지급 373,259 / 판매환율 1716 → 366,205.
 */
class SettlementFxExclusionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function fxFrom(?string $ym): void
    {
        Setting::updateOrCreate(['key' => 'settlement_fx_excluded_from_'.Setting::companyTemplateSet()], ['value' => (string) $ym, 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    /** 18누0304 — 판매환율 1716, 완납 입금 @1718 */
    private function settlement(string $attributedMonth): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'FXE-'.++$this->n, 'sales_channel' => 'export', 'dhl_request' => false,
            'currency' => 'EUR', 'exchange_rate' => 1716, 'sale_price' => 7837, 'transport_fee' => 1159,
            'sale_date' => '2026-08-01', 'purchase_date' => '2026-07-01', 'purchase_price' => 13_200_000, 'selling_fee' => 440_000,
            'cost_deregistration' => 24_000, 'cost_license' => 14_740, 'cost_towing' => 25_000, 'cost_insurance' => 7_650,
        ]);
        foreach ([5000, 3996] as $amt) {
            FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => $amt, 'exchange_rate' => 1718, 'payment_date' => '2026-08-05', 'confirmed_at' => now()]);
        }

        return Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'pending', 'attributed_month' => $attributedMonth,
        ])->fresh();
    }

    public function test_no_setting_keeps_fx_in_the_settlement(): void
    {
        $this->fxFrom(null);

        $this->assertSame(373_259, $this->settlement('2026-12-01')->actual_payout, '설정이 비면 종전(입금환율)');
    }

    public function test_from_december_attributed_month_uses_the_sale_rate(): void
    {
        $this->fxFrom('2026-12');

        $nov = $this->settlement('2026-11-01');
        $dec = $this->settlement('2026-12-01');

        $this->assertFalse($nov->fxExcluded());
        $this->assertSame(373_259, $nov->actual_payout, '귀속월 11월(12월 10일 지급분)은 환차 포함');
        $this->assertTrue($dec->fxExcluded());
        $this->assertSame(366_205, $dec->actual_payout, '귀속월 12월(1월 10일 지급분)부터 판매환율');
        $this->assertSame(1716.0, $dec->settlementRate());
    }

    public function test_paid_settlement_is_pinned_and_does_not_move_when_the_setting_changes(): void
    {
        $this->fxFrom('2026-12');
        $s = $this->settlement('2026-12-01');
        $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);
        $s->refresh();
        $this->assertTrue($s->fx_excluded, '지급 때 박제');
        $before = [$s->total_margin, $s->actual_payout, $s->company_net];

        $this->fxFrom(null);   // 나중에 환차를 다시 넣기로 해도
        $s = $s->fresh();

        $this->assertSame($before, [$s->total_margin, $s->actual_payout, $s->company_net], '지급 끝난 정산 숫자는 그대로');
    }

    public function test_unpinned_old_paid_rows_were_backfilled_as_fx_included(): void
    {
        // 마이그레이션이 기존 지급분을 false 로 박았다 — 시작 월을 과거로 잘못 넣어도 지난 지급분은 안 바뀐다.
        $s = $this->settlement('2026-09-01');
        $s->forceFill(['settlement_status' => 'paid', 'fx_excluded' => false])->saveQuietly();
        $this->fxFrom('2026-01');

        $this->assertSame(373_259, $s->fresh()->actual_payout);
    }

    public function test_batch_submission_pins_the_judgement(): void
    {
        $this->fxFrom('2026-12');
        $s = $this->settlement('2026-12-01');

        Settlement::pinFxForIds([$s->id]);

        $this->assertTrue($s->fresh()->fx_excluded);
        $this->fxFrom(null);
        $this->assertSame(366_205, $s->fresh()->actual_payout, '제출된 뒤 설정을 바꿔도 그대로');
    }

    /**
     * 2차 정산 — 환차 제외 정산은 지급 뒤 잔금이 다른 환율로 들어와도 환차분이 0, 비용 변동분만 넘어간다(jin 11-17 확인).
     * 환차 포함 정산은 종전대로 환차분이 생긴다.
     */
    public function test_secondary_carries_only_cost_changes_for_excluded_settlements(): void
    {
        $this->fxFrom('2026-12');
        foreach (['2026-12-01' => true, '2026-11-01' => false] as $month => $excluded) {
            $s = $this->settlement($month);
            $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);
            $v = $s->vehicle;
            // 지급 뒤: 잔금 하나의 환율 정정(1718 → 1800) + 탁송비 1만 원 추가
            FinalPayment::where('vehicle_id', $v->id)->first()->forceFill(['exchange_rate' => 1800])->saveQuietly();
            $v->forceFill(['cost_towing' => 35_000])->saveQuietly();
            $s = Settlement::with(['vehicle.finalPayments', 'vehicle.receivableHistories'])->find($s->id);

            $d = $s->secondaryDeltas();
            $this->assertEqualsWithDelta(-9_000.0, $d['cost'], 0.5, "{$month}: 비용 변동분은 종전 그대로");
            if ($excluded) {
                $this->assertSame(0.0, $d['fx'], '환차 제외 정산은 환차분 0');
            } else {
                $this->assertGreaterThan(0.0, $d['fx'], '환차 포함 정산은 종전대로 환차분');
            }
        }
    }

    /** 배치가 반려되면(지급 전) 박제를 푼다 — 귀속월을 옮겨 다시 제출하면 그때 다시 판정. */
    public function test_rejected_batch_releases_the_pin(): void
    {
        $this->fxFrom('2026-12');
        $sm = Salesman::create(['name' => 'FX프리', 'type' => 'freelance', 'is_active' => true]);
        $s = $this->settlement('2026-11-01');
        $s->update(['salesman_id' => $sm->id, 'settlement_status' => 'confirmed', 'confirmed_at' => now()]);
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);

        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-11');
        $this->assertFalse($s->fresh()->fx_excluded, '11월 귀속 = 환차 포함으로 박제');

        $batch->rejectBy(User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]), '다시');

        $this->assertNull($s->fresh()->fx_excluded, '반려되면 박제 해제');
        $this->assertNull($s->fresh()->payout_batch_id);
    }

    /** 환차손익(회사)은 합계에만 — 사람별 기여엔 안 들어간다(환율 사건이 담당자 순위·비중을 흔들지 않게). */
    public function test_company_fx_goes_to_the_total_not_to_the_person(): void
    {
        $this->fxFrom('2026-12');
        $sm = Salesman::create(['name' => 'FX프리2', 'type' => 'freelance', 'is_active' => true]);
        $s = $this->settlement('2026-12-01');
        $s->update(['salesman_id' => $sm->id]);
        $s = $s->fresh();
        $fx = $s->companyFxKrw();
        $this->assertSame((int) round(8996 * (1718 - 1716)), $fx, '실현 환차 = 받은 외화 × (입금환율 − 판매환율)');

        $person = PersonPayoutBreakdown::build($sm, '2026-12', collect([$s]), collect(), 0, false);
        $this->assertSame($fx, $person['company_fx']);
        $this->assertSame($s->total_margin - $s->actual_payout - $s->shipping_fee, $person['company_contribution'], '사람별 기여엔 환차 없음');
        $this->assertSame($person['company_contribution'] + $fx, $s->company_net, '회사 몫(합계)엔 환차 포함');
    }
}
