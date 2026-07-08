<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * [HELDDEMO] 미수 지급보류 데모 (2026-07-08, jin 요청) — 정산관리 「미수 지급보류」 배지 +
 *   재무 대시보드 「미수로 지급보류」 액션을 눈으로 확인하기 위한 더미.
 *
 * 시나리오: 판매가는 다 받아 완납으로 정산 확정(confirmed) 됐는데, 운임비/추가금이 나중에
 *   붙어 미수가 재발한 상태 → 지급 게이트가 월배치에서 제외(지급보류). 실사례 41라0856.
 *
 * 확정(confirmed·미배치) 정산 2건 = 사내직원(per_unit) + 프리랜서(ratio), 둘 다 차량 미수>0.
 * 대조용 완납 confirmed 1건(배지 안 뜸)도 생성.
 *
 * 마커: vehicle_number 'HELDDEMO-*' / salesman '[HELDDEMO] *' / settlement note 'HELDDEMO'.
 * 재실행 시 자동 정리 후 재생성:
 *   php artisan db:seed --class=Database\\Seeders\\PayoutHeldDemoSeeder
 */
class PayoutHeldDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->cleanup();

        $month = now()->format('Y-m');
        $buyer = Buyer::firstOrCreate(['name' => '[HELDDEMO] BUYER'], ['is_active' => true]);
        $smEmp = Salesman::firstOrCreate(['name' => '[HELDDEMO] 김보류'], ['is_active' => true, 'type' => 'employee']);
        $smFree = Salesman::firstOrCreate(['name' => '[HELDDEMO] 이보류'], ['is_active' => true, 'type' => 'freelance']);

        // ① 지급보류 — 운임비 후입력으로 미수(사내직원, per_unit)
        $v1 = $this->vehicle('HELDDEMO-1', $buyer->id, $smEmp->id, salePrice: 10_000_000, transportFee: 500_000);
        $this->pay($v1, 10_000_000);   // 판매가만 받음 → 운임비 50만 미수
        $this->confirmedSettlement($v1, $smEmp->id, $month, type: 'per_unit');

        // ② 지급보류 — 부분입금 미수(프리랜서, ratio)
        $v2 = $this->vehicle('HELDDEMO-2', $buyer->id, $smFree->id, salePrice: 20_000_000, transportFee: 0);
        $this->pay($v2, 15_000_000);   // 500만 미수
        $this->confirmedSettlement($v2, $smFree->id, $month, type: 'ratio');

        // ③ 대조군 — 완납 confirmed(배지 안 뜸, 정상 지급 대상)
        $v3 = $this->vehicle('HELDDEMO-3', $buyer->id, $smEmp->id, salePrice: 8_000_000, transportFee: 0);
        $this->pay($v3, 8_000_000);    // 완납
        $this->confirmedSettlement($v3, $smEmp->id, $month, type: 'per_unit');

        $this->command?->info('[HELDDEMO] 생성: 지급보류 2건(HELDDEMO-1·2) + 완납 대조 1건(HELDDEMO-3).');
        $this->command?->info('  정산관리에서 「지급보류만」 토글 / 재무 대시보드(/erp/dashboard 재무 탭) 「미수로 지급보류」 확인.');
    }

    private function vehicle(string $no, int $buyerId, int $salesmanId, int $salePrice, int $transportFee): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $no,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => $salePrice,
            'transport_fee' => $transportFee,
            'sale_date' => now()->subMonth()->format('Y-m-d'),
            'buyer_id' => $buyerId,
            'salesman_id' => $salesmanId,
        ]);
    }

    private function pay(Vehicle $v, int $amount): void
    {
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => $amount,
            'type' => 'balance',
            'payment_date' => now()->subMonth()->format('Y-m-d'),
            'confirmed_at' => now(),
        ]);
        $v->refreshCaches();
    }

    private function confirmedSettlement(Vehicle $v, int $salesmanId, string $month, string $type): void
    {
        Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesmanId,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => $type === 'per_unit' ? 100_000 : null,
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
            'attributed_month' => $month.'-01',
            'note' => 'HELDDEMO',
        ]);
    }

    /** 마커 기준 [HELDDEMO] 데이터 제거 (settlements/FP는 vehicle 명시 삭제). */
    private function cleanup(): void
    {
        $vehicleIds = Vehicle::where('vehicle_number', 'like', 'HELDDEMO-%')->pluck('id');
        if ($vehicleIds->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            FinalPayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            Vehicle::whereIn('id', $vehicleIds)->forceDelete();
        }
        Salesman::where('name', 'like', '[HELDDEMO]%')->forceDelete();
    }
}
