<?php

namespace Database\Seeders;

use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * karaba 매매상 잔금 10일 알림 시연 데이터 (2026-07-12).
 *   php artisan db:seed --class=Database\\Seeders\\KarabaAlarmDemoSeeder --force
 *
 * 거래처구분 '매매상' + 계약금(down PBP) 입력 + 잔금 미납 차량 3대 (계약금일 기준 D-8 / D-3 / 지남).
 * company_template_set=karaba + alarm_enabled=1 세팅 후 alarms:scan 실행
 *   → 사이드바 벨/알림함에 '매매상 잔금' 알림 + 차량목록 빨강 'D-N 잔금' 배지.
 *
 * cleanup: php artisan tinker --execute="Database\Seeders\KarabaAlarmDemoSeeder::clear();"
 *   (데모 차량·알림 제거 + company_template_set 원복)
 */
class KarabaAlarmDemoSeeder extends Seeder
{
    public const MARKER = '[KARABA-ALARM]';

    public function run(): void
    {
        // 시연: karaba 모드 + 알림 ON (배포 서버에선 기능설정에서 켬)
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alarm_enabled'], ['value' => '1', 'type' => 'boolean']);

        // 계약금일이 며칠 전인지에 따라 D-day 가 달라짐 (마감 = 계약금일 + 10)
        $cases = [
            ['num' => self::MARKER.' 12가0008', 'ago' => 2],   // 2일전 → D-8
            ['num' => self::MARKER.' 34나0003', 'ago' => 7],   // 7일전 → D-3 (임박·빨강)
            ['num' => self::MARKER.' 56다0015', 'ago' => 15],  // 15일전 → 기한 지남
        ];

        foreach ($cases as $c) {
            $downDate = now()->subDays($c['ago'])->startOfDay();

            $v = Vehicle::create([
                'vehicle_number' => $c['num'],
                'sales_channel' => 'export',
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'purchase_date' => $downDate->toDateString(),
                'purchase_price' => 10_000_000,
                'is_dealer_purchase' => true,   // 매매상 체크 (알림 트리거, 2026-07-22 이관)
                'purchase_registration_type' => '일반매입',
                'purchase_evidence_subtype' => '세금계산서',
                'dhl_request' => false,
            ]);

            // 계약금(down) 100만 확정 입금 → 잔금 900만 미납 (알림 대상)
            $v->purchaseBalancePayments()->create([
                'type' => 'down',
                'amount' => 1_000_000,
                'payment_date' => $downDate->toDateString(),
                'confirmed_at' => now(),
            ]);
        }

        Artisan::call('alarms:scan');

        $count = TaskAlarm::where('type', 'purchase_balance_due')->open()->count();
        $this->command?->info("KarabaAlarmDemoSeeder — 매매상 잔금 알림 시연 3대 생성. 현재 미해소 잔금 알림 {$count}건. 사이드바 벨/알림함 + 차량목록 배지 확인.");
    }

    public static function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $ids = Vehicle::withTrashed()->where('vehicle_number', 'like', self::MARKER.'%')->pluck('id');
        TaskAlarm::whereIn('vehicle_id', $ids)->delete();
        // 확정 PBP 삭제는 회계 무결성 deleting 가드에 막힘 → 시연 정리 한정 우회.
        PurchaseBalancePayment::$allowConfirmedMutation = true;
        try {
            PurchaseBalancePayment::whereIn('vehicle_id', $ids)->get()->each->forceDelete();
        } finally {
            PurchaseBalancePayment::$allowConfirmedMutation = false;
        }
        Vehicle::withTrashed()->whereIn('id', $ids)->forceDelete();

        // 시연 세팅 원복 (company_template_set 삭제 → .env COMPANY_TEMPLATE_SET fallback)
        Setting::where('key', 'company_template_set')->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
