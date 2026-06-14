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
 * karaba 서버 시연용 데이터 (2026-06-14).
 *   php artisan db:seed --class=Database\\Seeders\\KarabaDemoSeeder --force
 *
 * 구성: [관리]1 + 영업4(프리2·사내2) + 차량 10대 (전부 최근 1달 이내, export 거래완료)
 *   - 8대: 정산 paid (마무리)
 *   - 2대: 정산 pending (1차 정산부터 진행) — 1대 사내직원(per_unit), 1대 프리랜서(ratio)
 *   - 바이어 4 + 컨사이니 4 (영업별)
 *
 * 모든 row 이름에 [KARABA] 마커 → 한 줄 cleanup:
 *   php artisan tinker --execute="Database\Seeders\KarabaDemoSeeder::clear();"
 */
class KarabaDemoSeeder extends Seeder
{
    public const MARKER = '[KARABA]';

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

        // ===== Users (manager 1 + sales 4) =====
        $manager = User::create([
            'name' => self::MARKER.' 김관리',
            'email' => 'karaba-manager@karaba.test',
            'password' => Hash::make('password'),
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);

        // type: freelance(=ratio 정산) / employee(=per_unit 정산)
        $salesData = [
            ['name' => '김프리', 'type' => 'freelance', 'currency' => 'USD'],
            ['name' => '이프리', 'type' => 'freelance', 'currency' => 'EUR'],
            ['name' => '박사내', 'type' => 'employee',  'currency' => 'USD'],
            ['name' => '최사내', 'type' => 'employee',  'currency' => 'EUR'],
        ];

        $salesmen = [];
        foreach ($salesData as $i => $d) {
            $u = User::create([
                'name' => self::MARKER.' '.$d['name'],
                'email' => 'karaba-sales-'.($i + 1).'@karaba.test',
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

        // ===== Buyers + Consignees (영업별 1) =====
        $countries = [
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
            $consignees[$d['name']] = Consignee::create([
                'name' => self::MARKER.' '.$d['name'].'_CONS',
                'buyer_id' => $buyers[$d['name']]->id,
                'country_id' => $countries[$cur],
                'id_type' => 'business',
                'id_value' => '1234567890',
                'is_active' => true,
            ]);
        }

        // ===== Vehicles (10대) =====
        // paid=true → 정산 마무리(paid) / paid=false → pending(1차 정산 대기)
        $rate = ['USD' => 1380, 'EUR' => 1480];
        $vehicleSpecs = [
            // 8대 정산 마무리
            ['salesman' => '김프리', 'cur' => 'USD', 'salePrice' => 12_000, 'paid' => true],
            ['salesman' => '김프리', 'cur' => 'USD', 'salePrice' => 9_500,  'paid' => true],
            ['salesman' => '이프리', 'cur' => 'EUR', 'salePrice' => 11_000, 'paid' => true],
            ['salesman' => '이프리', 'cur' => 'EUR', 'salePrice' => 8_800,  'paid' => true],
            ['salesman' => '박사내', 'cur' => 'USD', 'salePrice' => 13_500, 'paid' => true],
            ['salesman' => '박사내', 'cur' => 'USD', 'salePrice' => 10_200, 'paid' => true],
            ['salesman' => '최사내', 'cur' => 'EUR', 'salePrice' => 12_300, 'paid' => true],
            ['salesman' => '최사내', 'cur' => 'EUR', 'salePrice' => 9_900,  'paid' => true],
            // 2대 1차 정산 대기
            ['salesman' => '박사내', 'cur' => 'USD', 'salePrice' => 14_000, 'paid' => false], // 사내직원(per_unit)
            ['salesman' => '김프리', 'cur' => 'USD', 'salePrice' => 12_800, 'paid' => false], // 프리랜서(ratio)
        ];

        foreach ($vehicleSpecs as $i => $vs) {
            $sm = $salesmen[$vs['salesman']];
            $buyer = $buyers[$vs['salesman']];
            $consignee = $consignees[$vs['salesman']];
            $no = self::MARKER.'-K'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

            $data = [
                'vehicle_number' => $no,
                'sales_channel' => 'export',
                'currency' => $vs['cur'],
                'exchange_rate' => $rate[$vs['cur']],
                'salesman_id' => $sm->id,
                'progress_status_rule_version' => 4,

                // 매입 (최근 1달 이내)
                'purchase_date' => now()->subDays(26)->toDateString(),
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
                'deregistration_date' => now()->subDays(24)->toDateString(),
                'deregistration_document' => 'karaba-demo/dereg.pdf',

                // 판매
                'sale_date' => now()->subDays(18)->toDateString(),
                'sale_price' => $vs['salePrice'],
                'tax_dc' => 0,
                'commission' => 0,
                'transport_fee' => 0,
                'auto_loading' => 0,
                'sale_other_costs' => 0,
                'buyer_id' => $buyer->id,
                'consignee_id' => $consignee->id,

                // 통관 + 선적 (거래완료)
                'export_buyer_id' => $buyer->id,
                'export_consignee_id' => $consignee->id,
                'bl_buyer_id' => $buyer->id,
                'bl_consignee_id' => $consignee->id,
                'bl_loading_location' => 'HJIT',
                'export_declaration_amount' => (int) $vs['salePrice'],
                'export_declaration_document' => 'karaba-demo/export.pdf',
                'is_export_cleared' => true,
                'bl_document' => 'karaba-demo/bl.pdf',
                'shipping_date' => now()->subDays(12)->toDateString(),
                'bl_issue_date' => now()->subDays(8)->toDateString(),
                'bl_number' => 'BL-KRB-'.($i + 1).'-2026',
                'container_number' => 'CONTKRB'.($i + 1),
            ];

            $v = Vehicle::create($data);

            // 매입 잔금 100% (1건)
            $pbp = new PurchaseBalancePayment([
                'vehicle_id' => $v->id,
                'amount' => 10_000_000,
                'type' => 'balance',
                'payment_date' => now()->subDays(22)->toDateString(),
                'confirmed_at' => now()->subDays(22),
            ]);
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                $pbp->save();
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }

            // 판매 잔금 100% (1건) + 환율 snapshot
            FinalPayment::create([
                'vehicle_id' => $v->id,
                'amount' => $vs['salePrice'],
                'type' => 'balance',
                'payment_date' => now()->subDays(6)->toDateString(),
                'exchange_rate' => $rate[$vs['cur']],
                'confirmed_at' => now()->subDays(6),
            ]);

            // Settlement — Vehicle::saved 훅이 거래완료(export) 시 자동 생성(pending)
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

            // paid=true → 정산 마무리 (pending → confirmed → paid)
            if ($vs['paid']) {
                $settlement->update([
                    'settlement_status' => 'confirmed',
                    'confirmed_at' => now()->subDays(3),
                ]);
                $settlement->update([
                    'settlement_status' => 'paid',
                    'paid_at' => now()->subDays(1),
                ]);
            }
            // paid=false → pending 유지 (사용자가 1차 정산부터 진행)
        }
    }
}
