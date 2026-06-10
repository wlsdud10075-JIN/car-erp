<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Salesman::unconsumed_carryover accessor (2026-06-10).
 * Σ closed 정산 carryover_out − Σ carryover_in = 흡수 안 된 이월 잔액.
 * Settlement::creating 흡수 훅(SKILLS §5-5)과 동일 공식 = 단일 출처 검증.
 */
class SalesmanUnconsumedCarryoverTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function makeVehicle(int $salesmanId): Vehicle
    {
        $this->counter++;

        return Vehicle::create([
            'vehicle_number' => 'UCC-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $salesmanId,
        ]);
    }

    public function test_no_settlements_means_zero(): void
    {
        $salesman = Salesman::create(['name' => '이월0', 'type' => 'freelance']);

        $this->assertSame(0, $salesman->unconsumed_carryover);
    }

    public function test_closed_carryover_out_with_no_next_settlement_is_stranded(): void
    {
        $salesman = Salesman::create(['name' => '퇴사영업', 'is_active' => false, 'type' => 'freelance']);
        $v = $this->makeVehicle($salesman->id);

        // closed + carryover_out, 흡수할 다음 정산 없음 → stranded
        Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'carryover_out_krw' => 50000,
        ]);

        // 양수 = 담당자에게 지급 대기
        $this->assertSame(50000, $salesman->fresh()->unconsumed_carryover);
    }

    public function test_negative_carryover_out_is_collectible(): void
    {
        $salesman = Salesman::create(['name' => '환차손영업', 'type' => 'freelance']);
        $v = $this->makeVehicle($salesman->id);

        Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'carryover_out_krw' => -30000,
        ]);

        // 음수 = 담당자에게 청구 대상
        $this->assertSame(-30000, $salesman->fresh()->unconsumed_carryover);
    }

    public function test_next_settlement_absorbs_carryover_back_to_zero(): void
    {
        $salesman = Salesman::create(['name' => '흡수영업', 'type' => 'freelance']);
        $vA = $this->makeVehicle($salesman->id);

        Settlement::create([
            'vehicle_id' => $vA->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'carryover_out_krw' => 50000,
        ]);
        $this->assertSame(50000, $salesman->fresh()->unconsumed_carryover, '흡수 전 stranded 50000');

        // 같은 담당자 새 정산 → creating 훅이 carryover_in 으로 50000 흡수
        $vB = $this->makeVehicle($salesman->id);
        Settlement::create([
            'vehicle_id' => $vB->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'pending',
        ]);

        $this->assertSame(0, $salesman->fresh()->unconsumed_carryover, '다음 정산이 흡수 → 0');
    }
}
