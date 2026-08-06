<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 환차는 실지급액에 **재가산하지 않는다** (jin 2026-08-06).
 *
 * 구(2026-05-23 회의확장씬 #6+7): 2차 마감 + 프리랜서면 exchange_difference_krw 를 1:1 가산.
 * 신: 환차는 판매금원화의 환율(Vehicle::settlement_exchange_rate)로 **1차 정산에 이미 반영**된다.
 *     여기서 또 더하면 이중계상이므로 가산 로직을 제거했다.
 *     exchange_difference_krw 컬럼은 실현 환차 총액의 감사·참고 기록으로 남는다.
 *
 * 1차 반영 자체의 검증은 SettlementReceiptRateTest.
 */
class SettlementExchangeDiffPayoutTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** 미완납 차량 — 실효환율이 안 잡히므로 판매환율 그대로. 환차 가산 여부만 격리해서 본다. */
    private function makeSettlement(string $type, array $extra = []): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'EXD-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 10000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000,
            'selling_fee' => 100_000,
        ]);

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => $type === 'per_unit' ? 100_000 : null,
            'settlement_status' => 'paid',
            'confirmed_at' => now(), 'paid_at' => now(),
        ], $extra));
    }

    public function test_ratio_closed_positive_diff_is_not_added_to_payout(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,
        ]);

        $this->assertSame(
            $s->settlement_amount - $s->document_fee,
            $s->actual_payout,
            '환차가 실지급액에 다시 더해졌다 — 1차 반영분과 이중계상이다'
        );
    }

    public function test_ratio_closed_negative_diff_is_not_subtracted_from_payout(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => -8000,
        ]);

        $this->assertSame($s->settlement_amount - $s->document_fee, $s->actual_payout);
    }

    public function test_per_unit_closed_with_diff_does_not_change_payout(): void
    {
        $s = $this->makeSettlement('per_unit', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,
        ]);

        $this->assertSame($s->settlement_amount, $s->actual_payout);
    }

    /** 이월(carryover)은 그대로 살아 있어야 한다 — 2차가 넘기는 건 이제 비용 변동분이다. */
    public function test_carryover_in_still_applies(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,
            'carryover_in_krw' => 30000,
        ]);

        $this->assertSame($s->settlement_amount - $s->document_fee + 30000, $s->actual_payout);
    }
}
