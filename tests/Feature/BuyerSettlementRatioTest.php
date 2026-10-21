<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\BatchPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🤝 바이어별 정산 비율 (jin 2026-10-21) — 바이어 드로어 「정산 방식」 기본 / 5:5 / 7:3.
 *
 * 7:3 바이어 차 = 담당자가 사내직원이든 프리랜서든 **총마진 × 30% 만**(차등 없음 · 음수면 음수).
 * 서류비 5만은 프리랜서만. 정산이 생길 때 박제 → 칸을 나중에 바꿔도 이미 생긴 정산은 그대로.
 * 바이어 담당자가 바뀌면 기본으로.
 * KRW 차: 매입 1천만 → 총마진 = ((판매가 − 1천만) + 90만) × 0.9
 */
class BuyerSettlementRatioTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function sale(Salesman $sm, Buyer $buyer, int $sale): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'BR'.++$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => $sale, 'sale_date' => '2026-10-02',
        ]);

        return $v->fresh()->createSettlementNow('test')->fresh();
    }

    public function test_employee_on_a_7_3_buyer_gets_30_percent_only_without_document_fee(): void
    {
        $musa = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => true]);
        $buyer = Buyer::create(['name' => '신규바이어', 'salesman_id' => $musa->id, 'settlement_ratio_pct' => 30]);

        $s = $this->sale($musa, $buyer, 12_000_000);                 // 총마진 2,610,000
        $this->assertSame('ratio', $s->settlement_type);
        $this->assertSame('buyer', $s->ratio_source);
        $this->assertSame(783_000, $s->settlement_amount, '2,610,000 × 30% — 차등 20만은 없다');
        $this->assertSame(0, $s->document_fee, '사내직원은 서류비 없음(jin «빼지마»)');
        $this->assertSame(783_000, $s->actual_payout);

        $loss = $this->sale($musa, $buyer, 8_000_000);               // 총마진 (−2,000,000 + 900,000) × 0.9 = −990,000
        $this->assertSame(-297_000, $loss->actual_payout, '손해 차는 마이너스 30%(jin «음수든 양수든»)');

        $other = Buyer::create(['name' => '기존바이어', 'salesman_id' => $musa->id]);
        $this->assertSame(200_000, $this->sale($musa, $other, 12_000_000)->actual_payout, '기본 바이어는 종전 차등 그대로');
    }

    public function test_freelancer_on_a_7_3_buyer_gets_30_percent_minus_document_fee(): void
    {
        $f = Salesman::create(['name' => '프리', 'type' => 'freelance', 'is_active' => true]);
        $buyer = Buyer::create(['name' => '신규', 'salesman_id' => $f->id, 'settlement_ratio_pct' => 30]);

        $s = $this->sale($f, $buyer, 12_000_000);
        $this->assertSame(783_000 - 50_000, $s->actual_payout, '30% − 서류비 5만(프리랜서는 종전대로)');
        $this->assertSame(50_000, $s->document_fee);
    }

    public function test_changing_the_buyer_later_does_not_touch_existing_settlements(): void
    {
        $musa = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => true]);
        $buyer = Buyer::create(['name' => '바이어', 'salesman_id' => $musa->id]);
        $before = $this->sale($musa, $buyer, 12_000_000);

        $buyer->update(['settlement_ratio_pct' => 30]);
        $this->assertSame(200_000, $before->fresh()->actual_payout, '정한 뒤부터 — 이미 생긴 정산은 그대로');
        $this->assertSame(783_000, $this->sale($musa, $buyer, 12_000_000)->actual_payout, '새 정산부터 7:3');
    }

    public function test_handover_resets_the_buyer_to_default(): void
    {
        $a = Salesman::create(['name' => 'A', 'type' => 'employee', 'is_active' => true]);
        $b = Salesman::create(['name' => 'B', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => '바이어', 'salesman_id' => $a->id, 'settlement_ratio_pct' => 30]);

        $buyer->update(['salesman_id' => $b->id]);
        $this->assertNull($buyer->fresh()->settlement_ratio_pct, '담당자가 바뀌면 기본(jin «승계를 하면 기본»)');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $buyer->id, 'column_name' => 'settlement_ratio_pct']);

        $buyer->update(['salesman_id' => $a->id, 'settlement_ratio_pct' => 50]);
        $this->assertSame(50, (int) $buyer->fresh()->settlement_ratio_pct, '같은 저장에서 직접 고르면 그 값');
    }

    public function test_buyer_drawer_saves_the_split_and_the_card_labels_it(): void
    {
        $musa = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => '신규', 'salesman_id' => $musa->id]);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('erp.buyers.index')->call('openEdit', $buyer->id)
            ->assertSeeHtml('data-buyer-settlement-ratio')
            ->set('settlement_ratio_pct_str', '30')->call('save')->assertHasNoErrors();
        $this->assertSame(30, (int) $buyer->fresh()->settlement_ratio_pct);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $buyer->id, 'column_name' => 'settlement_ratio_pct', 'new_value' => '30']);

        Volt::test('erp.buyers.index')->call('openEdit', $buyer->id)->set('settlement_ratio_pct_str', '77')->call('save');
        $this->assertNull($buyer->fresh()->settlement_ratio_pct, '선택지 밖 값은 기본');

        $buyer->update(['settlement_ratio_pct' => 30]);
        $s = $this->sale($musa, $buyer->fresh(), 12_000_000);
        $person = collect(BatchPayoutBreakdown::forMonthPreview('2026-10', Settlement::whereKey($s->id)->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get())['people'])
            ->firstWhere('salesman_id', $musa->id);
        $this->assertSame('바이어 7:3', $person['vehicles'][0]['type_label']);
    }
}
