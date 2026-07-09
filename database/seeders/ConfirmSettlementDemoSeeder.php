<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * [CONFIRMDEMO] 정산 확정 버튼 데모 (2026-07-09, jin 요청) — 정산관리 행 「확정」 버튼 +
 *   「이 달 일괄 확정」 버튼을 눈으로 테스트하기 위한 더미.
 *
 * 프리랜서(ratio) 2명 + 사내직원(per_unit) 2명, 각자 이번 달 귀속 pending 정산 1건씩(총 4건).
 * 전부 완납 상태라 정산 확정만 하면 되는 정상 케이스. per_unit_amount=null(사내직원)로 차등tier 자동 반영.
 * E2 는 매입 1.2억(≥1억)이라 고율 tier(25%) 확인용.
 *
 * 시더는 미인증 실행이라 FinalPayment::saved 자동 정산생성이 안 뜸 → 수동 pending 만 남음(중복 없음).
 *
 * 마커: vehicle_number 'CONFIRMDEMO-*' / salesman '[CONFIRMDEMO] *' / settlement note 'CONFIRMDEMO'.
 * 재실행 시 자동 정리 후 재생성:
 *   php artisan db:seed --class=Database\\Seeders\\ConfirmSettlementDemoSeeder
 */
class ConfirmSettlementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->cleanup();

        $month = now()->format('Y-m');
        $buyer = Buyer::firstOrCreate(['name' => '[CONFIRMDEMO] BUYER'], ['is_active' => true]);

        $smFree1 = Salesman::firstOrCreate(['name' => '[CONFIRMDEMO] 프리랜서 김프리'], ['is_active' => true, 'type' => 'freelance']);
        $smFree2 = Salesman::firstOrCreate(['name' => '[CONFIRMDEMO] 프리랜서 이랜서'], ['is_active' => true, 'type' => 'freelance']);
        $smEmp1 = Salesman::firstOrCreate(['name' => '[CONFIRMDEMO] 사내직원 박사원'], ['is_active' => true, 'type' => 'employee']);
        $smEmp2 = Salesman::firstOrCreate(['name' => '[CONFIRMDEMO] 사내직원 최직원'], ['is_active' => true, 'type' => 'employee']);

        // 프리랜서 (ratio 50%)
        $this->pendingCase('CONFIRMDEMO-F1', $buyer->id, $smFree1->id, $month, 'ratio', purchase: 8_000_000, sale: 12_000_000);
        $this->pendingCase('CONFIRMDEMO-F2', $buyer->id, $smFree2->id, $month, 'ratio', purchase: 15_000_000, sale: 22_000_000);

        // 사내직원 (per_unit, tier 자동) — E2 는 매입 1.2억 → 고율 25%
        $this->pendingCase('CONFIRMDEMO-E1', $buyer->id, $smEmp1->id, $month, 'per_unit', purchase: 9_000_000, sale: 13_000_000);
        $this->pendingCase('CONFIRMDEMO-E2', $buyer->id, $smEmp2->id, $month, 'per_unit', purchase: 120_000_000, sale: 135_000_000);

        $this->command?->info("[CONFIRMDEMO] 생성: 프리랜서 2 + 사내직원 2, 각자 이번달({$month}) pending 정산 1건씩(총 4건).");
        $this->command?->info('  정산관리에서 각 행 「확정」 버튼 / 월 선택 후 「이 달 일괄 확정」 버튼 테스트.');
    }

    private function pendingCase(string $no, int $buyerId, int $salesmanId, string $month, string $type, int $purchase, int $sale): void
    {
        $v = Vehicle::create([
            'vehicle_number' => $no,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => $purchase,
            'purchase_date' => now()->subMonth()->format('Y-m-d'),
            'sale_price' => $sale,
            'sale_date' => now()->subMonth()->format('Y-m-d'),
            'buyer_id' => $buyerId,
            'salesman_id' => $salesmanId,
        ]);

        // 완납 (판매가 전액) → 정상 정산 대상
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => $sale,
            'type' => 'balance',
            'payment_date' => now()->subMonth()->format('Y-m-d'),
            'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesmanId,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => null,          // 사내직원은 차등 tier 자동, 프리랜서는 비율 사용
            'settlement_status' => 'pending',   // ← 확정 버튼 테스트 대상
            'attributed_month' => $month.'-01',
            'note' => 'CONFIRMDEMO',
        ]);
    }

    /** 마커 기준 [CONFIRMDEMO] 데이터 제거 (settlements/FP는 vehicle 명시 삭제). */
    private function cleanup(): void
    {
        $vehicleIds = Vehicle::where('vehicle_number', 'like', 'CONFIRMDEMO-%')->pluck('id');
        if ($vehicleIds->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            FinalPayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            Vehicle::whereIn('id', $vehicleIds)->forceDelete();
        }
        Salesman::where('name', 'like', '[CONFIRMDEMO]%')->forceDelete();
    }
}
