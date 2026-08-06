<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 정산관리 담당자 카드에 「매입취소 손실 (미반영)」 노출 (jin 2026-08-06).
 *
 * 실무자가 정산관리만 보다가 「월배치 지급」의 손실 요약을 못 봐서 추가했다.
 *
 * 🚨 표시 전용이다 — 실제 차감은 월배치 지급의 담당자 조정 1곳에서만 한다.
 *    여기 정산 합계(actual_payout_sum)에 섞으면 **이중 청구**가 된다. 그 경계를 테스트로 고정한다.
 */
class SettlementCancelLossCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(Salesman $s, array $overrides = []): Vehicle
    {
        static $n = 0;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'CLC-'.++$n,
            'sales_channel' => 'export',
            'dhl_request' => false,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'salesman_id' => $s->id,
            'purchase_date' => '2026-07-01',
            'purchase_price' => 10_000_000,
        ], $overrides));
    }

    public function test_sums_freelancer_half_per_salesman(): void
    {
        $free = Salesman::create(['name' => '프리', 'type' => 'freelance']);

        $this->makeVehicle($free, [
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => 499_408,
            'cancelled_at' => now(),
        ]);
        $this->makeVehicle($free, [
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => 100_000,
            'cancelled_at' => now(),
        ]);

        $map = Vehicle::unsettledCancelLossBySalesman();

        // 249,704 (intdiv 절사) + 50,000
        $this->assertSame(299_704, $map[$free->id]['sum']);
        $this->assertCount(2, $map[$free->id]['plates']);
    }

    public function test_excludes_employees_and_already_settled_and_active_cancels(): void
    {
        $emp = Salesman::create(['name' => '사내', 'type' => 'employee']);
        $free = Salesman::create(['name' => '프리2', 'type' => 'freelance']);

        // 사내직원 = 회사 전액 부담
        $this->makeVehicle($emp, [
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => 500_000, 'cancelled_at' => now(),
        ]);
        // 이미 월배치에 반영됨
        $this->makeVehicle($free, [
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => 500_000, 'cancelled_at' => now(),
            'cancel_loss_settled_at' => now(),
        ]);
        // 미수마감 전(진행중 취소) — 부족분 미동결
        $this->makeVehicle($free, [
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
            'cancelled_at' => now(),
        ]);

        $this->assertSame([], Vehicle::unsettledCancelLossBySalesman());
    }

    public function test_card_shows_loss_but_keeps_it_out_of_the_payout_total(): void
    {
        $this->actingAs(User::factory()->create([
            'permission' => 'admin', 'email_verified_at' => now(),
        ]));

        $free = Salesman::create(['name' => '가태웅', 'type' => 'freelance']);

        // 정산 1건 (손실과 무관한 정상 건)
        $sold = $this->makeVehicle($free, [
            'sale_price' => 5_000_000, 'sale_date' => '2026-08-01',
        ]);
        Settlement::create([
            'vehicle_id' => $sold->id,
            'salesman_id' => $free->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 100_000,
            'settlement_status' => 'pending',
        ]);

        // 미반영 매입취소 손실
        $this->makeVehicle($free, [
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => 499_408,
            'cancelled_at' => now(),
        ]);

        $summaries = Volt::test('erp.settlements.index')->get('salesmanSummaries');
        $row = collect($summaries)->firstWhere('salesman_id', $free->id);

        $this->assertNotNull($row, '담당자 카드가 없다');
        $this->assertSame(249_704, $row['cancel_loss']);
        $this->assertSame(
            100_000,
            $row['actual_payout_sum'],
            '매입취소 손실이 정산 지급합계에 섞였다 — 월배치와 이중 청구된다'
        );
    }
}
