<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v5 — 도착·완납했는데 B/L 파일만 없는 옛 차량의 소급 정리 규칙 (jin 2026-08-27, 1회성).
 *
 * 🔑 이 파일이 지키는 것 두 가지:
 *   ① v5 를 **명시 지정한 행에서만** 켜진다 — 기본값 v4 는 지금과 똑같이 동작한다.
 *   ② 재계산이 돌아도 **되돌아가지 않는다** — 값이 아니라 규칙을 바꿨기 때문이다.
 *      (진행상태는 계산값이라, cache 만 써놓는 방식은 저장 한 번·새벽 05:00 재계산에 지워진다.)
 */
class ProgressRuleV5ClosedTest extends TestCase
{
    use RefreshDatabase;

    /** 운영 실측 모양 — 선적일·ETA·선사·출고일 있고 완납, B/L 서류만 없음. */
    private function arrivedVehicle(bool $fullyPaid = true, int $ruleVersion = 4): Vehicle
    {
        $buyer = Buyer::create(['name' => 'ARR BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'ARR-'.fake()->unique()->numberBetween(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1700,
            'dhl_request' => false, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 10000,
            'shipping_date' => '2026-06-01', 'eta_date' => '2026-06-25',
            'vessel_name' => 'YOUNGSHIN 05', 'warehouse_out_date' => '2026-06-01',
            'progress_status_rule_version' => $ruleVersion,
        ]);

        if ($fullyPaid) {
            FinalPayment::create([
                'vehicle_id' => $v->id, 'amount' => 10000, 'type' => 'balance',
                'payment_date' => '2026-06-01', 'exchange_rate' => 1700, 'confirmed_at' => now(),
            ]);
        }

        return $v->fresh();
    }

    public function test_v4_vehicle_without_bl_document_is_not_closed(): void
    {
        // 지금 동작 — 기본값이 바뀌면 안 된다.
        $v = $this->arrivedVehicle();

        $this->assertNotSame('거래완료', $v->progress_status, '기본 규칙(v4)이 서류 없이 거래완료가 됨');
    }

    public function test_v5_vehicle_is_closed_without_a_bl_document(): void
    {
        $v = $this->arrivedVehicle(fullyPaid: true, ruleVersion: 5);

        $this->assertSame('거래완료', $v->progress_status);
    }

    public function test_v5_still_requires_full_payment(): void
    {
        // 「완납된 것들 한해서」 (jin) — 미수가 남으면 v5 여도 거래완료가 아니다.
        $v = $this->arrivedVehicle(fullyPaid: false, ruleVersion: 5);

        $this->assertNotSame('거래완료', $v->progress_status);
    }

    public function test_v5_still_requires_departure(): void
    {
        $v = $this->arrivedVehicle(fullyPaid: true, ruleVersion: 5);
        $v->update(['warehouse_out_date' => null]);

        $this->assertNotSame('거래완료', $v->fresh()->progress_status, '출고 전인데 거래완료가 됨');
    }

    public function test_the_status_survives_a_cache_rebuild(): void
    {
        // 이게 핵심이다 — cache 만 써놓는 방식이었다면 여기서 원래대로 돌아간다.
        $v = $this->arrivedVehicle(fullyPaid: true, ruleVersion: 5);
        $this->assertSame('거래완료', $v->fresh()->progress_status_cache);

        $this->artisan('vehicles:rebuild-caches')->assertExitCode(0);

        $this->assertSame('거래완료', $v->fresh()->progress_status_cache, '재계산에 되돌아감');
    }

    public function test_a_later_real_bl_document_keeps_the_same_result(): void
    {
        // 실무자가 진짜 B/L 을 올려도 결과가 같아야 충돌이 없다.
        $v = $this->arrivedVehicle(fullyPaid: true, ruleVersion: 5);
        $v->update(['bl_document' => 'vehicles/1/bl.pdf']);

        $this->assertSame('거래완료', $v->fresh()->progress_status);
    }

    public function test_default_rule_version_stays_four_for_new_vehicles(): void
    {
        // v5 가 기본값으로 새면 3사 전 차량의 진행상태가 한꺼번에 움직인다.
        $v = Vehicle::create([
            'vehicle_number' => 'ARR-NEW', 'sales_channel' => 'export',
            'currency' => 'KRW', 'dhl_request' => false,
        ]);

        $this->assertLessThan(5, (int) $v->fresh()->progress_status_rule_version);
    }
}
