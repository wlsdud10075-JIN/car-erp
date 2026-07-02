<?php

namespace Database\Seeders;

use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 면허비 엑셀 임포트 시연/검증용 더미 (2026-07-02).
 *
 * 바탕화면 「주식회사 헤이맨 6월분.xlsx」 record 1 (수출신고번호 44475-26-060019X, 수량 4, 합계 9,900) 과
 * 매칭되도록 같은 신고번호를 가진 차량 4대를 생성 → 차량목록 「명세서 기입」에서 대상비용=면허비 선택 후
 * 6월분 파일 업로드 시 9,900 ÷ 4 = 2,475/대 로 n/1 분배되는지 확인.
 *
 * 각 차량은 확정 입금 1건 + paid/2차 pending 정산 → 회계 잠금 발동(면허비 일괄 기입 시 자동 잠금해제 시연).
 * cost_license 초기값 11,000 → 임포트 후 2,475 로 바뀌는지 대조.
 *
 * cleanup: php artisan tinker --execute="Database\Seeders\LicenseFeeImportDemoSeeder::clear();"
 */
class LicenseFeeImportDemoSeeder extends Seeder
{
    public const MARKER = '[LICDEMO]';

    /** 6월분.xlsx record 1 의 수출신고번호 (수량 4 · 합계 9,900). */
    public const DECL_NUMBER = '44475-26-060019X';

    private const NUMBERS = ['91하9001', '91하9002', '91하9003', '91하9004'];

    public static function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        $ids = Vehicle::withTrashed()->where('memo', 'like', self::MARKER.'%')->pluck('id');
        if ($ids->isNotEmpty()) {
            Settlement::whereIn('vehicle_id', $ids)->forceDelete();
            FinalPayment::whereIn('vehicle_id', $ids)->forceDelete();
            Vehicle::whereIn('id', $ids)->forceDelete();
        }
        Salesman::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function run(): void
    {
        self::clear();

        $approver = User::whereIn('permission', ['super', 'admin'])->first();
        $salesman = Salesman::create(['name' => self::MARKER.'면허비영업', 'is_active' => true]);

        foreach (self::NUMBERS as $number) {
            $v = Vehicle::create([
                'vehicle_number' => $number,
                'sales_channel' => 'export',
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'salesman_id' => $salesman->id,
                'purchase_price' => 5000000,
                'export_declaration_number' => self::DECL_NUMBER,
                'cost_license' => 11000,   // 임포트 후 2,475 로 바뀌는지 대조용 초기값
                'memo' => self::MARKER,
            ]);

            // 확정 입금 → 회계 잠금 발동(면허비 일괄 기입 시 자동 잠금해제 시연).
            FinalPayment::create([
                'vehicle_id' => $v->id,
                'amount' => 500000,
                'payment_date' => '2026-06-10',
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $approver?->id,
            ]);

            // 2차 정산 pending(마감 아님) — 임포트가 skip 하지 않음. 이벤트 우회.
            $settlement = Settlement::withoutEvents(fn () => Settlement::create([
                'vehicle_id' => $v->id,
                'salesman_id' => $salesman->id,
                'settlement_type' => 'ratio',
                'settlement_ratio' => 50,
                'settlement_status' => 'paid',
                'secondary_status' => 'pending',
                'paid_at' => '2026-06-10',
            ]));
            Settlement::where('id', $settlement->id)->update(['created_at' => '2026-05-15 10:00:00']);
        }

        $this->command?->info('LicenseFeeImportDemoSeeder: 수출신고번호 '.self::DECL_NUMBER.' 4대 생성. 「6월분.xlsx」 면허비 업로드 시 9,900 n/1 → 2,475/대.');
    }
}
