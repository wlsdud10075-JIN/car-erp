<?php

namespace Database\Seeders;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 큐 4 8-5 — 정산 탭 차트용 시드.
 *
 * VehicleSeeder는 정산을 pending/confirmed로만 시드 → 인원별 정산지급액 차트 빈 화면.
 * 본 시더는 거래완료(dhl_request=true) 차량의 정산을 'paid'로 갱신하고
 * paid_at·confirmed_snapshot을 채워 인원별 월별 분포를 만듦.
 *
 * 실행: php artisan db:seed --class=Database\\Seeders\\SettlementDemoSeeder
 */
class SettlementDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1) 거래완료 차량 → paid (인원별 월별 분포)
        $completed = Vehicle::where('dhl_request', true)->get();
        $idx = 0;
        foreach ($completed as $v) {
            $salesman = $v->salesman ?? Salesman::inRandomOrder()->first();
            if (! $salesman) {
                continue;
            }

            // paid_at — 현재 시점 기준 최근 12개월 분포 (사용자 시스템 시각 기준 차트 표시)
            // bl_issue_date 우선, 없으면 사용자 PC year의 1~12월에 인덱스 분포
            $paidAt = $v->bl_issue_date
                ? Carbon::parse($v->bl_issue_date)->addDays(7)
                : Carbon::create(now()->year, ($idx % 12) + 1, 15);

            // confirmed_snapshot — accessor 계산값을 미리 저장 (큐 10 H4 retroactive 보정)
            $snapshot = [
                'captured_at' => now()->toIso8601String(),
                'exchange_rate' => $v->exchange_rate,
                'export_declaration_amount' => $v->export_declaration_amount,
                'transport_fee' => $v->transport_fee,
                'purchase_price' => $v->purchase_price,
                'cost_total' => $v->cost_total ?? 0,
                'sales_amount_krw' => $v->sales_channel === 'export'
                    ? (int) (($v->export_declaration_amount - ($v->transport_fee ?? 0)) * ($v->exchange_rate ?? 0))
                    : (int) (($v->sale_price ?? 0) * ($v->exchange_rate ?: 1)),
                'settlement_sales_krw' => 0,  // 아래서 채움
                'sales_margin' => 0,
                'vat_margin' => (int) (($v->purchase_price ?? 0) * 0.09),
                'total_margin' => 0,
                'settlement_amount' => 0,
                'actual_payout' => 0,
            ];
            $snapshot['settlement_sales_krw'] = $snapshot['sales_amount_krw'] - $snapshot['cost_total'];
            $snapshot['sales_margin'] = $snapshot['settlement_sales_krw'] - ($v->purchase_price ?? 0);
            $snapshot['total_margin'] = $snapshot['sales_margin'] + $snapshot['vat_margin'];
            $snapshot['settlement_amount'] = (int) ($snapshot['total_margin'] * 0.30);
            $snapshot['actual_payout'] = $snapshot['settlement_amount'];

            Settlement::updateOrCreate(
                ['vehicle_id' => $v->id, 'salesman_id' => $salesman->id],
                [
                    'settlement_type' => 'ratio',
                    'settlement_ratio' => 30.00,
                    'other_deduction' => 0,
                    'settlement_status' => 'paid',
                    'confirmed_at' => $paidAt->copy()->subDays(2),
                    'paid_at' => $paidAt,
                    'confirmed_snapshot' => $snapshot,
                ]
            );
            $idx++;
        }

        $this->command->info("정산 paid 시드: {$completed->count()}건 (거래완료 차량)");

        // 2) 선적완료지만 DHL 안 보낸 차량 → confirmed (대기 총액 카드 검증용)
        $awaitingPayout = Vehicle::whereNotNull('bl_document')
            ->where('dhl_request', false)
            ->get();
        $confirmedIdx = 0;
        foreach ($awaitingPayout as $v) {
            $salesman = $v->salesman ?? Salesman::inRandomOrder()->first();
            if (! $salesman) {
                continue;
            }
            Settlement::updateOrCreate(
                ['vehicle_id' => $v->id, 'salesman_id' => $salesman->id],
                [
                    'settlement_type' => 'ratio',
                    'settlement_ratio' => 30.00,
                    'other_deduction' => 0,
                    'settlement_status' => 'confirmed',
                    'confirmed_at' => now()->subDays(rand(1, 14)),
                    'paid_at' => null,
                ]
            );
            $confirmedIdx++;
        }
        $this->command->info("정산 confirmed 시드: {$confirmedIdx}건 (선적완료·미지급)");
    }
}
