<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 시연용 데이터 (2026-05-28) — [관리] 1명 + 영업담당자 5명 + 차량 6대 + 정산 5건.
 *
 * 모든 row 이름에 [LOOP] 마커 → 한 줄로 cleanup 가능:
 *   php artisan tinker --execute="Database\Seeders\LoopDemoSeeder::clear();"
 *
 * 시나리오:
 *   V1 김영업(프리·KRW heyman)  → 정산 paid (완료)
 *   V2 박영업(사내·USD export) → 정산 paid (완료)
 *   V3 이영업(프리·USD export) → 정산 paid (완료) — 1차
 *   V4 이영업(프리·USD export) → 정산 pending  — 사용자가 진행 (2차)
 *   V5 최영업(사내·USD export) → 정산 pending  — 사용자가 1차 진행
 *   V6 정영업(프리·EUR export) → 정산 paid (완료)
 *
 * 모든 차량: 매입/판매 잔금 100% 완납 + 환율 snapshot.
 * USD/EUR: 반입지·수출신고서·B/L 모두 set → 진행상태 "거래완료".
 * KRW heyman: 통관/선적 없음 → 진행상태 "판매완료" 에서 멈춤.
 */
class LoopDemoSeeder extends Seeder
{
    public const MARKER = '[LOOP]';

    public static function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $vehicleIds = Vehicle::withTrashed()
            ->where('vehicle_number', 'like', self::MARKER.'%')
            ->pluck('id');

        if ($vehicleIds->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
            \App\Models\ReceivableHistory::whereIn('vehicle_id', $vehicleIds)->forceDelete();
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

        // ===== Users (manager 1 + sales 5) =====
        $manager = User::create([
            'name' => self::MARKER.' 김관리',
            'email' => 'loop-manager@car-erp.test',
            'password' => Hash::make('password'),
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);

        $salesData = [
            ['name' => '김영업', 'type' => 'freelance', 'currency' => 'KRW', 'channel' => 'export'],
            ['name' => '박영업', 'type' => 'employee', 'currency' => 'USD', 'channel' => 'export'],
            ['name' => '이영업', 'type' => 'freelance', 'currency' => 'USD', 'channel' => 'export'],
            ['name' => '최영업', 'type' => 'employee', 'currency' => 'USD', 'channel' => 'export'],
            ['name' => '정영업', 'type' => 'freelance', 'currency' => 'EUR', 'channel' => 'export'],
        ];

        $salesmen = [];
        foreach ($salesData as $i => $d) {
            $u = User::create([
                'name' => self::MARKER.' '.$d['name'],
                'email' => 'loop-sales-'.($i + 1).'@car-erp.test',
                'password' => Hash::make('password'),
                'permission' => 'user',
                'role' => '영업',
                'type' => $d['type'],
                'manager_user_id' => $manager->id,
                'email_verified_at' => now(),
            ]);
            $salesmen[$d['name']] = Salesman::create([
                'user_id' => $u->id,
                'name' => self::MARKER.' '.$d['name'],
                'type' => $d['type'],
                'is_active' => true,
            ]);
        }

        // ===== Buyers + Consignees =====
        $countries = [
            'KRW' => Country::where('code', 'KOR')->first()?->id,
            'USD' => Country::where('code', 'USA')->first()?->id,
            'EUR' => Country::where('code', 'DEU')->first()?->id,
        ];

        $buyers = [];
        $consignees = [];
        foreach ($salesData as $d) {
            $cur = $d['currency'];
            $buyers[$d['name']] = Buyer::create([
                'name' => self::MARKER.' '.$d['name'].'_BUYER',
                'country_id' => $countries[$cur],
                'salesman_id' => $salesmen[$d['name']]->id,
                'is_active' => true,
            ]);

            if ($cur !== 'KRW') {
                $consignees[$d['name']] = Consignee::create([
                    'name' => self::MARKER.' '.$d['name'].'_CONS',
                    'buyer_id' => $buyers[$d['name']]->id,
                    'country_id' => $countries[$cur],
                    'id_type' => 'business',
                    'id_value' => '1234567890',
                    'is_active' => true,
                ]);
            }
        }

        // ===== Vehicles =====
        // V1~V6 정의
        $vehicleSpecs = [
            ['salesman' => '김영업', 'no' => '12LOOP1', 'cur' => 'KRW', 'channel' => 'export', 'rate' => 1, 'salePrice' => 15_000_000, 'paid' => true,  'desc' => 'KRW (수출 채널 통일·heyman 폐기)·paid', 'skipExport' => true],
            ['salesman' => '박영업', 'no' => '12LOOP2', 'cur' => 'USD', 'channel' => 'export', 'rate' => 1300, 'salePrice' => 12_000, 'paid' => true,  'desc' => 'USD 수출·paid (사내직원)'],
            ['salesman' => '이영업', 'no' => '12LOOP3', 'cur' => 'USD', 'channel' => 'export', 'rate' => 1300, 'salePrice' => 12_000, 'paid' => true,  'desc' => 'USD 수출·1차 paid (프리)'],
            ['salesman' => '이영업', 'no' => '12LOOP4', 'cur' => 'USD', 'channel' => 'export', 'rate' => 1320, 'salePrice' => 12_500, 'paid' => false, 'desc' => 'USD 수출·2차 pending — 사용자 진행'],
            ['salesman' => '최영업', 'no' => '12LOOP5', 'cur' => 'USD', 'channel' => 'export', 'rate' => 1310, 'salePrice' => 13_000, 'paid' => false, 'desc' => 'USD 수출·1차 pending — 사용자 진행'],
            ['salesman' => '정영업', 'no' => '12LOOP6', 'cur' => 'EUR', 'channel' => 'export', 'rate' => 1400, 'salePrice' => 11_000, 'paid' => true,  'desc' => 'EUR 수출·paid'],
        ];

        foreach ($vehicleSpecs as $i => $vs) {
            $sm = $salesmen[$vs['salesman']];
            $buyer = $buyers[$vs['salesman']];
            $consignee = $consignees[$vs['salesman']] ?? null;
            // skipExport=true (KRW 차량): 통관/선적 단계 생략 → "판매완료"에서 멈춤
            $isExport = $vs['channel'] === 'export' && empty($vs['skipExport']);

            $data = [
                'vehicle_number' => self::MARKER.'-'.$vs['no'],
                'sales_channel' => $vs['channel'],
                'currency' => $vs['cur'],
                'exchange_rate' => $vs['rate'],
                'salesman_id' => $sm->id,
                'progress_status_rule_version' => 4,

                // 매입
                'purchase_date' => now()->subDays(30)->toDateString(),
                'purchase_price' => 10_000_000,
                'selling_fee' => 0,
                'cost_deregistration' => 50_000,
                'cost_license' => 0,
                'cost_towing' => 0,
                'cost_carry' => 0,
                'cost_shoring' => 0,
                'cost_insurance' => 0,
                'cost_transfer' => 0,
                'cost_extra1' => 0,
                'cost_extra2' => 0,

                // 말소
                'is_deregistered' => true,
                'deregistration_date' => now()->subDays(28)->toDateString(),
                'deregistration_document' => 'loop-demo/dereg.pdf',

                // 판매
                'sale_date' => now()->subDays(20)->toDateString(),
                'sale_price' => $vs['salePrice'],
                'tax_dc' => 0,
                'commission' => 0,
                'transport_fee' => 0,
                'auto_loading' => 0,
                'sale_other_costs' => 0,
                'buyer_id' => $buyer->id,
                'consignee_id' => $consignee?->id,
            ];

            if ($isExport) {
                $data['export_buyer_id'] = $buyer->id;
                $data['export_consignee_id'] = $consignee?->id;
                $data['bl_buyer_id'] = $buyer->id;
                $data['bl_consignee_id'] = $consignee?->id;
                $data['bl_loading_location'] = 'HJIT';
                $data['export_declaration_amount'] = (int) $vs['salePrice'];
                $data['export_declaration_document'] = 'loop-demo/export.pdf';
                $data['is_export_cleared'] = true;
                $data['bl_document'] = 'loop-demo/bl.pdf';
                $data['shipping_date'] = now()->subDays(15)->toDateString();
                $data['bl_issue_date'] = now()->subDays(10)->toDateString();
                $data['bl_number'] = 'BL-LOOP-'.($i + 1).'-2026';
                $data['container_number'] = 'CONTLOOP'.($i + 1);
                $data['forwarding_company_id'] = null;
            }

            $v = Vehicle::create($data);

            // 매입 잔금 100% (1건)
            $pbp = new PurchaseBalancePayment([
                'vehicle_id' => $v->id,
                'amount' => 10_000_000,
                'type' => 'balance',
                'payment_date' => now()->subDays(25)->toDateString(),
                'confirmed_at' => now()->subDays(25),
            ]);
            // PBP creating 가드 우회 (시드)
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                $pbp->save();
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }

            // 판매 잔금 100% (1건) + exchange_rate snapshot (이번 fix)
            FinalPayment::create([
                'vehicle_id' => $v->id,
                'amount' => $vs['salePrice'],
                'type' => 'balance',
                'payment_date' => now()->subDays(8)->toDateString(),
                'exchange_rate' => $vs['rate'],
                'confirmed_at' => now()->subDays(8),
            ]);

            // Settlement — Vehicle::saved 훅이 거래완료(export) 시 자동 생성
            // KRW heyman 은 거래완료 안 가서 자동 생성 X → 수동 생성
            $v->refresh();
            $settlement = $v->settlements()->first();
            if (! $settlement) {
                $settlement = Settlement::create([
                    'vehicle_id' => $v->id,
                    'salesman_id' => $sm->id,
                    'settlement_type' => $sm->defaultSettlementType(),
                    'settlement_ratio' => $sm->type === 'freelance' ? 50 : null,
                    'per_unit_amount' => $sm->type === 'employee' ? 100_000 : null,
                    'settlement_status' => 'pending',
                ]);
            }

            if ($vs['paid']) {
                // pending → confirmed → paid 단계별로 (auth 없으므로 saving 훅의 canApprove 가드 우회)
                $settlement->update([
                    'settlement_status' => 'confirmed',
                    'confirmed_at' => now()->subDays(3),
                ]);
                $settlement->update([
                    'settlement_status' => 'paid',
                    'paid_at' => now()->subDays(1),
                ]);
            }
        }
    }
}
