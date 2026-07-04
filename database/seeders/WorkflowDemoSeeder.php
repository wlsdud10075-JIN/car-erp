<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 전체 워크플로우 단계별 시연 데이터 (2026-07-04) — 탭·흐름도 재구성 + 게이트 배너 검증용.
 *
 * 6대를 각기 다른 단계로 생성해 매입→판매→입금→선적→통관→B/L→거래완료 전 구간과
 * 게이트 배너(C5 진입 50% / G1 B/L 100%)를 한눈에 확인:
 *   WF1 매입중        — 매입가 O·미지급(잔금 0) → 매입 warn
 *   WF2 판매·미입금   — 판매 O·입금 30%(<50%) → 선적/통관 진입 게이트 red(잠김)
 *   WF3 선적중        — 완납·반입지 O·통관 X·B/L X → 선적 done·통관 pending
 *   WF4 통관중        — 완납·수출신고서 O·is_cleared·B/L X → 통관 done·B/L 발행가능(ok)
 *   WF5 B/L 잠김      — 입금 60%(<100%)·통관 완료·B/L X → B/L locked 배너(미수율 40%)
 *   WF6 거래완료      — 완납·B/L O·정산 paid → 전부 done
 *
 * 마커 [WF] → cleanup: php artisan tinker --execute="Database\Seeders\WorkflowDemoSeeder::clear();"
 */
class WorkflowDemoSeeder extends Seeder
{
    public const MARKER = '[WF]';

    public static function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $vehicleIds = Vehicle::withTrashed()
            ->where('vehicle_number', 'like', self::MARKER.'%')
            ->pluck('id');

        if ($vehicleIds->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            ReceivableHistory::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            FinalPayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            PurchaseBalancePayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            Vehicle::whereIn('id', $vehicleIds)->forceDelete();
        }

        Consignee::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        Buyer::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        Salesman::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        User::where('name', 'like', '%'.self::MARKER.'%')->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function run(): void
    {
        self::clear();

        $usa = Country::where('code', 'USA')->first()?->id;

        $manager = User::create([
            'name' => self::MARKER.' 관리자', 'email' => 'wf-manager@car-erp.test',
            'password' => Hash::make('password'), 'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
        $salesUser = User::create([
            'name' => self::MARKER.' 영업', 'email' => 'wf-sales@car-erp.test',
            'password' => Hash::make('password'), 'permission' => 'user', 'role' => '영업',
            'type' => 'freelance', 'manager_user_id' => $manager->id, 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create([
            'user_id' => $salesUser->id, 'name' => self::MARKER.' 영업', 'type' => 'freelance', 'is_active' => true,
        ]);

        // no => [단계, 매입완납, 판매, 판매입금%, export레벨(''|loading|cleared|bl)]
        $specs = [
            ['no' => 'WF1-매입중',   'dereg' => false, 'purchasePaid' => false, 'sale' => false, 'salePct' => 0,   'export' => ''],
            ['no' => 'WF2-판매미입금', 'dereg' => true,  'purchasePaid' => true,  'sale' => true,  'salePct' => 30,  'export' => ''],
            ['no' => 'WF3-선적중',   'dereg' => true,  'purchasePaid' => true,  'sale' => true,  'salePct' => 100, 'export' => 'loading'],
            ['no' => 'WF4-통관중',   'dereg' => true,  'purchasePaid' => true,  'sale' => true,  'salePct' => 100, 'export' => 'cleared'],
            ['no' => 'WF5-BL잠김',   'dereg' => true,  'purchasePaid' => true,  'sale' => true,  'salePct' => 60,  'export' => 'cleared'],
            ['no' => 'WF6-거래완료', 'dereg' => true,  'purchasePaid' => true,  'sale' => true,  'salePct' => 100, 'export' => 'bl'],
        ];

        $rate = 1300;
        $salePrice = 12_000;

        foreach ($specs as $i => $s) {
            $buyer = Buyer::create([
                'name' => self::MARKER.' '.$s['no'].'_BUYER', 'country_id' => $usa,
                'salesman_id' => $sm->id, 'is_active' => true,
            ]);
            $consignee = Consignee::create([
                'name' => self::MARKER.' '.$s['no'].'_CONS', 'buyer_id' => $buyer->id,
                'country_id' => $usa, 'id_type' => 'business', 'id_value' => '1234567890', 'is_active' => true,
            ]);

            $data = [
                'vehicle_number' => self::MARKER.'-'.$s['no'],
                'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => $rate,
                'salesman_id' => $sm->id, 'progress_status_rule_version' => 4,
                'purchase_date' => now()->subDays(30)->toDateString(),
                'purchase_price' => 10_000_000, 'selling_fee' => 0,
                'cost_deregistration' => 50_000, 'cost_license' => 0, 'cost_towing' => 0,
                'cost_carry' => 0, 'cost_shoring' => 0, 'cost_insurance' => 0,
                'cost_transfer' => 0, 'cost_extra1' => 0, 'cost_extra2' => 0,
            ];

            if ($s['dereg']) {
                $data += [
                    'is_deregistered' => true,
                    'deregistration_date' => now()->subDays(28)->toDateString(),
                    'deregistration_document' => 'wf-demo/dereg.pdf',
                ];
            }

            if ($s['sale']) {
                $data += [
                    'sale_date' => now()->subDays(20)->toDateString(),
                    'sale_price' => $salePrice, 'tax_dc' => 0, 'commission' => 0,
                    'transport_fee' => 0, 'auto_loading' => 0, 'sale_other_costs' => 0,
                    'buyer_id' => $buyer->id, 'consignee_id' => $consignee->id,
                ];
            }

            $lvl = $s['export'];
            if (in_array($lvl, ['loading', 'cleared', 'bl'], true)) {
                $data += [
                    'bl_buyer_id' => $buyer->id, 'bl_consignee_id' => $consignee->id,
                    'bl_loading_location' => 'HJIT', 'vessel_name' => 'VSL-WF-'.($i + 1),
                    'container_number' => 'CONTWF'.($i + 1),
                    'shipping_date' => now()->subDays(15)->toDateString(),
                ];
            }
            if (in_array($lvl, ['cleared', 'bl'], true)) {
                $data += [
                    'export_buyer_id' => $buyer->id, 'export_consignee_id' => $consignee->id,
                    'export_declaration_amount' => $salePrice,
                    'export_declaration_document' => 'wf-demo/export.pdf',
                    'is_export_cleared' => true,
                ];
            }
            if ($lvl === 'bl') {
                $data += [
                    'bl_document' => 'wf-demo/bl.pdf', 'bl_type' => 'surrender',
                    'bl_number' => 'BL-WF-'.($i + 1), 'bl_issue_date' => now()->subDays(10)->toDateString(),
                ];
            }

            $v = Vehicle::create($data);

            // 매입 잔금
            if ($s['purchasePaid']) {
                PurchaseBalancePayment::$skipCreatingGuard = true;
                try {
                    (new PurchaseBalancePayment([
                        'vehicle_id' => $v->id, 'amount' => 10_000_000, 'type' => 'balance',
                        'payment_date' => now()->subDays(25)->toDateString(), 'confirmed_at' => now()->subDays(25),
                    ]))->save();
                } finally {
                    PurchaseBalancePayment::$skipCreatingGuard = false;
                }
            }

            // 판매 입금 (salePct)
            if ($s['sale'] && $s['salePct'] > 0) {
                FinalPayment::create([
                    'vehicle_id' => $v->id, 'amount' => (int) round($salePrice * $s['salePct'] / 100),
                    'type' => 'balance', 'payment_date' => now()->subDays(8)->toDateString(),
                    'exchange_rate' => $rate, 'confirmed_at' => now()->subDays(8),
                ]);
            }

            // 거래완료(WF6) → 정산 paid
            $v->refresh();
            if ($lvl === 'bl') {
                $settlement = $v->settlements()->first() ?? Settlement::create([
                    'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
                    'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending',
                ]);
                $settlement->update(['settlement_status' => 'confirmed', 'confirmed_at' => now()->subDays(3)]);
                $settlement->update(['settlement_status' => 'paid', 'paid_at' => now()->subDays(1)]);
            }

            $v->refreshProgressCache();
        }
    }
}
