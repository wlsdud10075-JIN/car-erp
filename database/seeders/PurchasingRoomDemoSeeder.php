<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * [ROOMDEMO] 매입 가능 금액(구 보증금 여력) 눈으로 확인용 데모 (jin 2026-07-29).
 *
 *   한도 = 선적 전 진행중 차량의 **입금액** × 50%
 *   사용 = 그 차량들의 **매입 지급**(확정 PBP)
 *   여력 = 한도 − 사용
 *
 * 바이어 5명을 상황별로 만든다. 전부 KRW·환율 1 이라 화면 숫자와 암산이 그대로 맞는다.
 *
 *   [ROOMDEMO] 1 여유            판매 1억 / 입금 1억(완납) / 매입지급 0
 *                                → 한도 5,000만 · 사용 0 · 여력 5,000만
 *   [ROOMDEMO] 2 절반씀          판매 1억 / 입금 1억          / 매입지급 3,000만
 *                                → 한도 5,000만 · 사용 3,000만 · 여력 2,000만
 *   [ROOMDEMO] 3 소진            판매 1억 / 입금 1억          / 매입지급 5,000만
 *                                → 한도 5,000만 · 사용 5,000만 · 여력 0  (빨강)
 *   [ROOMDEMO] 4 입금적음        판매 1억 / 입금 2,000만       / 매입지급 0
 *                                → 한도 1,000만 · 여력 1,000만
 *                                ⭐ 구 공식이면 여력이 5,000만으로 잡혔다. 이 차이가 이번 변경의 핵심.
 *   [ROOMDEMO] 5 미확정지급      판매 1억 / 입금 1억          / 매입지급 4,000만(재무 미확정)
 *                                → 사용 0 · 여력 5,000만 (아직 나간 돈이 아니다)
 *
 * 실행:  php artisan db:seed --class=PurchasingRoomDemoSeeder
 * 확인:  /erp/buyers 에서 이름이 [ROOMDEMO] 로 시작하는 바이어 5명
 * 정리:  php artisan db:seed --class=PurchasingRoomDemoSeeder   (매 실행마다 기존 ROOMDEMO 를 지우고 다시 만든다)
 */
class PurchasingRoomDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 모델 훅(감사·캐시)이 auth 를 요구한다.
        $actor = User::where('permission', 'super')->first() ?? User::first();
        if (! $actor) {
            $this->command->error('사용자가 없습니다. 먼저 php artisan db:seed 를 실행하세요.');

            return;
        }
        Auth::login($actor);

        $this->purge();

        $salesman = Salesman::firstOrCreate(
            ['name' => '[ROOMDEMO] 담당'],
            ['type' => 'employee', 'is_active' => true],
        );

        $cases = [
            ['1 여유',       100_000_000, 100_000_000, 0,          true],
            ['2 절반씀',     100_000_000, 100_000_000, 30_000_000, true],
            ['3 소진',       100_000_000, 100_000_000, 50_000_000, true],
            ['4 입금적음',   100_000_000,  20_000_000, 0,          true],
            ['5 미확정지급', 100_000_000, 100_000_000, 40_000_000, false],
        ];

        foreach ($cases as [$label, $salePrice, $paid, $purchasePaid, $purchaseConfirmed]) {
            $buyer = Buyer::create([
                'name' => "[ROOMDEMO] {$label}",
                'is_active' => true,
                'salesman_id' => $salesman->id,
            ]);

            $vehicle = Vehicle::create([
                'vehicle_number' => 'RD'.str_pad((string) $buyer->id, 4, '0', STR_PAD_LEFT),
                'sales_channel' => 'export',
                'buyer_id' => $buyer->id,
                'salesman_id' => $salesman->id,
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'sale_date' => now()->subDays(20)->format('Y-m-d'),
                'sale_price' => $salePrice,
                'purchase_date' => now()->subDays(40)->format('Y-m-d'),
                'purchase_price' => 60_000_000,
                'dhl_request' => false,
            ]);

            if ($paid > 0) {
                $vehicle->finalPayments()->create([
                    'amount' => $paid,
                    'type' => 'balance',
                    'payment_date' => now()->subDays(10)->format('Y-m-d'),
                    'exchange_rate' => 1,
                    'confirmed_at' => now(),
                    'confirmed_by_user_id' => $actor->id,
                ]);
            }

            if ($purchasePaid > 0) {
                $vehicle->purchaseBalancePayments()->create([
                    'amount' => $purchasePaid,
                    'type' => 'balance',
                    'payment_date' => now()->subDays(30)->format('Y-m-d'),
                    'confirmed_at' => $purchaseConfirmed ? now() : null,
                    'confirmed_by_user_id' => $purchaseConfirmed ? $actor->id : null,
                ]);
            }

            $vehicle->refresh();
            $vehicle->refreshProgressCache();

            $g = $buyer->fresh()->receivableGauge();
            $this->command->info(sprintf(
                '  %-16s 입금 %11s → 한도 %11s − 사용 %11s = 여력 %11s',
                $label,
                number_format($g['paid_krw']),
                number_format($g['limit_krw']),
                number_format($g['used_krw']),
                number_format($g['available_krw']),
            ));
        }

        Auth::logout();
        $this->command->info('[ROOMDEMO] 완료 — /erp/buyers 에서 "[ROOMDEMO]" 검색.');
    }

    /** 재실행 가능하도록 기존 데모를 먼저 지운다. */
    private function purge(): void
    {
        $buyers = Buyer::where('name', 'like', '[ROOMDEMO]%')->get();
        foreach ($buyers as $b) {
            foreach ($b->vehicles()->withTrashed()->get() as $v) {
                $v->finalPayments()->delete();
                $v->purchaseBalancePayments()->delete();
                $v->forceDelete();
            }
            $b->delete();
        }
        Salesman::where('name', '[ROOMDEMO] 담당')->delete();
    }
}
