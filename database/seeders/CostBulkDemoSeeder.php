<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 2차 정산 비용 일괄 기입 시연용 데이터 (2026-07-01).
 *
 *   ① 면허비 묶음 n/1 (선적요청 화면 「2차 비용」 탭) — 2차 정산 pending 묶음 2개를 생성.
 *      paid월 2개(2026-06 / 2026-05) → 월 그룹·접기 시연. 회계 잠금(확정 입금) → 자동 잠금해제 시연.
 *   ② 탁송비 명세서 매칭 — 별도 시드 불필요. 로컬 DB 실차량 번호가 이미 위카 6월 명세서와 일치하므로
 *      차량목록 「명세서 기입」에서 `헤이맨-위카(6월).xlsx` 업로드/붙여넣기 하면 기존 차량에 바로 매칭됨.
 *
 * 데모 차량 plate 는 실데이터와 겹치지 않는 90하900X (탁송비 매칭 대상 아님, 면허비 탭 전용).
 * 마커: memo='[COSTDEMO]'.
 *   cleanup:  php artisan tinker --execute="Database\Seeders\CostBulkDemoSeeder::clear();"
 */
class CostBulkDemoSeeder extends Seeder
{
    public const MARKER = '[COSTDEMO]';

    private const BUNDLE_A = ['90하9001', '90하9002', '90하9003', '90하9004'];   // RORO, paid 2026-06-10

    private const BUNDLE_B = ['90하9005', '90하9006', '90하9007', '90하9008'];   // CONTAINER, paid 2026-05-10

    public static function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        $ids = Vehicle::withTrashed()->where('memo', 'like', self::MARKER.'%')->pluck('id');
        if ($ids->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $ids)->forceDelete();
            FinalPayment::whereIn('vehicle_id', $ids)->forceDelete();
            ShippingRequest::whereIn('vehicle_id', $ids)->forceDelete();
            Vehicle::whereIn('id', $ids)->forceDelete();
        }
        Buyer::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        Salesman::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function run(): void
    {
        self::clear();

        $approver = User::whereIn('permission', ['super', 'admin'])->first();
        $salesman = Salesman::create(['name' => self::MARKER.'위카영업', 'is_active' => true]);
        $buyer = Buyer::create(['name' => self::MARKER.'DEMO BUYER', 'is_active' => true]);

        // A = 진행중(할 일 필터 시연) / B = 완료(완료 필터 시연). 둘 다 2차 pending → 면허비 탭엔 함께 노출.
        $this->makeBundle(self::BUNDLE_A, 'COSTDEMO-A', 'RORO', '2026-06-10', 'in_progress', $salesman, $buyer, $approver);
        $this->makeBundle(self::BUNDLE_B, 'COSTDEMO-B', 'CONTAINER', '2026-05-10', 'done', $salesman, $buyer, $approver);

        $this->command?->info('CostBulkDemoSeeder: 면허비 탭용 묶음 2개(2026-06·2026-05) 생성. 탁송비는 위카 파일을 기존 차량에 업로드해 테스트.');
    }

    private function makeBundle(array $numbers, string $batchId, string $method, string $paidAt, string $status, Salesman $s, Buyer $buyer, ?User $approver): void
    {
        foreach ($numbers as $number) {
            $v = Vehicle::create([
                'vehicle_number' => $number,
                'sales_channel' => 'export',
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'salesman_id' => $s->id,
                'purchase_price' => 5000000,
                'cost_license' => 11000,   // 기본값 → 면허비 미기입 뱃지
                'cost_towing' => 30000,
                'dhl_request' => false,
                'memo' => self::MARKER,
            ]);

            // 확정 입금 1건 → 회계 잠금 발동(면허비 일괄 기입 시 자동 잠금해제 시연).
            FinalPayment::create([
                'vehicle_id' => $v->id,
                'amount' => 500000,
                'payment_date' => $paidAt,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $approver?->id,
            ]);

            // 2차 정산 pending (paid 후 한 달 창) — 이벤트 우회(paid 전환 가드/카운트 회피).
            Settlement::withoutEvents(fn () => Settlement::create([
                'vehicle_id' => $v->id,
                'salesman_id' => $s->id,
                'settlement_type' => 'ratio',
                'settlement_ratio' => 50,
                'settlement_status' => 'paid',
                'secondary_status' => 'pending',
                'paid_at' => $paidAt,
            ]));

            ShippingRequest::create([
                'batch_id' => $batchId,
                'vehicle_id' => $v->id,
                'buyer_id' => $buyer->id,
                'shipping_method' => $method,
                'requested_by_email' => 'demo@heyman',
                'status' => $status,
                'requested_at' => $paidAt,
            ]);
        }
    }
}
