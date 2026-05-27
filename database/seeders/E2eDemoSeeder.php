<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * E2E 데모 시더 (사용자 요청 2026-05-27) — 브라우저 확인용 시나리오 데이터.
 *
 * [관리] 1 + 영업 5(KRW1·EUR1·USD3) + 각 차량을 거래완료까지 태워 정산 자동 생성.
 * feature test(E2eSettlementWorkflowTest)와 동일 재무수치 → 화면에서 동일 금액 확인 가능.
 *
 * ⚠️ 클린 제거: 모든 데이터에 마커(아래 MARK_* prefix)를 붙인다.
 *   제거 = `php artisan e2e:demo-clear` (E2eDemoClear 커맨드, self::clear()).
 *   재실행 시 run() 이 먼저 clear() → 중복 없이 깨끗하게 재생성.
 *
 * ⚠️ local 전용. 운영 시더(ProductionSeeder)와 분리 — DatabaseSeeder 에 자동 포함하지 않음.
 *   실행: php artisan db:seed --class=E2eDemoSeeder
 */
class E2eDemoSeeder extends Seeder
{
    public const MARK_VEHICLE = 'E2ED-';            // vehicle_number prefix

    public const MARK_NAME = '[E2E] ';              // salesman/buyer name prefix

    public const MARK_EMAIL = 'e2e-demo-';          // user email prefix (…@example.test)

    public function run(): void
    {
        self::clear();

        $manager = User::create([
            'name' => self::MARK_NAME.'관리자',
            'email' => self::MARK_EMAIL.'mgr@example.test',
            'password' => Hash::make('password'),
            'permission' => 'admin',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);

        // 정산 자동생성 훅(Vehicle::saved)은 auth 없으면 skip → 시더도 인증 컨텍스트 필요.
        // 관리(admin)로 로그인하면 production 과 동일하게 훅·가드 동작(완납·말소선행이라 통과).
        Auth::login($manager);

        $buyer = Buyer::create(['name' => self::MARK_NAME.'DEMO BUYER', 'is_active' => true]);

        // [통화, 환율, salesman type, 재무수치] — feature test 와 동일 금액.
        $cases = [
            ['한화 사내', 'KRW', 1.0, 'employee', ['purchase_price' => 10_000_000, 'selling_fee' => 1_000_000, 'cost_deregistration' => 100_000, 'sale_price' => 13_000_000]],
            ['유로 프리', 'EUR', 1400.0, 'freelance', ['purchase_price' => 8_000_000, 'sale_price' => 10_000]],
            ['USD 이익', 'USD', 1300.0, 'freelance', ['purchase_price' => 15_000_000, 'sale_price' => 20_000]],
            ['USD 손실', 'USD', 1300.0, 'freelance', ['purchase_price' => 15_000_000, 'sale_price' => 20_000]],
            ['USD 동일', 'USD', 1300.0, 'freelance', ['purchase_price' => 15_000_000, 'sale_price' => 20_000]],
        ];

        $n = 0;
        foreach ($cases as [$label, $currency, $rate, $type, $fin]) {
            $n++;
            $salesman = Salesman::create([
                'name' => self::MARK_NAME.$label,
                'type' => $type,
                'is_active' => true,
            ]);
            $this->driveToTradeComplete($salesman, $buyer, $n, $currency, $rate, $fin);
        }

        Auth::logout();

        $this->command?->info('E2eDemoSeeder: 관리1 + 영업'.$n.' + 차량'.$n.'(거래완료·정산 자동생성) 생성 완료.');
        $this->command?->info('로그인: '.self::MARK_EMAIL.'mgr@example.test / password');
    }

    /** 마커가 붙은 모든 E2E 데모 데이터를 의존성 역순으로 제거 (cron·운영 데이터 무영향). */
    public static function clear(): void
    {
        $vehicleIds = Vehicle::withTrashed()
            ->where('vehicle_number', 'like', self::MARK_VEHICLE.'%')
            ->pluck('id');

        if ($vehicleIds->isNotEmpty()) {
            $settlementIds = Settlement::whereIn('vehicle_id', $vehicleIds)->pluck('id');
            // 정산 대상 승인요청 제거
            ApprovalRequest::where('target_type', Settlement::class)
                ->whereIn('target_id', $settlementIds)->delete();
            Settlement::whereIn('vehicle_id', $vehicleIds)->delete();
            FinalPayment::whereIn('vehicle_id', $vehicleIds)->delete();
            PurchaseBalancePayment::whereIn('vehicle_id', $vehicleIds)->delete();
            AuditLog::where('auditable_type', Vehicle::class)
                ->whereIn('auditable_id', $vehicleIds)->delete();
            Vehicle::withTrashed()->whereIn('id', $vehicleIds)->forceDelete();
        }

        Salesman::where('name', 'like', self::MARK_NAME.'%')->forceDelete();
        Buyer::where('name', 'like', self::MARK_NAME.'%')->forceDelete();
        User::where('email', 'like', self::MARK_EMAIL.'%')->forceDelete();
    }

    /** 차량 1대를 매입→말소→판매(완납)→통관→선적→B/L→거래완료 까지 구동 (시더=무인증, 가드 우회). */
    private function driveToTradeComplete(Salesman $s, Buyer $buyer, int $n, string $currency, float $rate, array $fin): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => self::MARK_VEHICLE.$n,
            'sales_channel' => 'export',
            'salesman_id' => $s->id,
            'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-01',
            'purchase_price' => $fin['purchase_price'],
            'selling_fee' => $fin['selling_fee'] ?? 0,
            'cost_deregistration' => $fin['cost_deregistration'] ?? 0,
            'currency' => $currency,
            'exchange_rate' => $rate,
        ]);

        $v->update(['is_deregistered' => true, 'deregistration_document' => 'dereg/'.$v->id.'.pdf']);

        $v->update([
            'sale_date' => '2026-05-10',
            'sale_price' => $fin['sale_price'],
            'commission' => 0, 'auto_loading' => 0, 'tax_dc' => 0,
            'transport_fee' => 0, 'sale_other_costs' => 0,
        ]);
        $v->finalPayments()->create([
            'amount' => $fin['sale_price'],
            'type' => 'balance',
            'payment_date' => '2026-05-10',
            'confirmed_at' => now(),
        ]);
        $v->refresh();   // FP 반영 — 완납 인식(G1 100% 게이트 통과 위해 필수)

        $v->update([
            'export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-15',
            'export_declaration_document' => 'exp/'.$v->id.'.pdf', 'is_export_cleared' => true,
            'bl_loading_location' => '부산항',
        ]);

        $v->update(['bl_document' => 'bl/'.$v->id.'.pdf']);   // 거래완료 → 정산 자동 생성

        return $v->fresh();
    }
}
