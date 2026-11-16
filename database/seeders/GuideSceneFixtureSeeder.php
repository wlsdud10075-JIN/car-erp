<?php

namespace Database\Seeders;

use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\Buyer;
use App\Models\CashAccount;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Vehicle;
use App\Models\VehicleShipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 씬 점검(화면 회귀) — 예시 데이터 「씬 시작 전 상태」로 되돌리기. 로컬 전용 · dev 전용(master 제외).
 * 정본 = docs/design/scene-walkthrough.md §8 1단계 / scripts/scene/check/README-check.md
 *
 * 1단계 범위 = 씬 5개(bank-balance · documents · bulk-shipping-dates · shipment-fee · buyer-account)가 바꿔 놓는 것만.
 *
 * 원칙
 *  - 🚫 migrate:fresh · truncate · 표 전체 삭제 금지. **씬이 쓰는 고정 표식**(차량번호 · 바이어명 · 계좌번호 ·
 *    발송번호 · 비고 문구)으로 찾은 행만 지운다. 표식이 없는 행은 건드리지 않는다.
 *  - 고정 id 금지 — 차량번호·이름으로 찾는다(PC 마다 id 가 다르다).
 *  - 예시 차·바이어를 새로 만들지 않는다 — 없으면 분명한 메시지로 멈춘다(예시 데이터는 촬영 때 화면으로 만들었다).
 *  - 몇 번 돌려도 같은 결과(idempotent). 지울 것이 없으면 0건.
 *  - 시더는 로그인 없이 돈다 → `auth()->check()` 로 막힌 자동 생성(정산·알림톡)은 안 울린다.
 *
 * 실행: php artisan db:seed --class=Database\\Seeders\\GuideSceneFixtureSeeder
 */
class GuideSceneFixtureSeeder extends Seeder
{
    private const PLATES = ['00가1113', '00가1131', '00가1141'];

    private const BUYER = 'GUIDE SAMPLE TRADING';

    /** bank-balance 씬이 등록하는 가짜 계좌 */
    private const CASH_BANK = '예시';

    private const CASH_ACCOUNT_NOS = ['000-000-000001', '000-000-000002'];

    /** shipment-fee 씬의 발송 번호 */
    private const SHIPMENT_NOS = ['EE123456789KR', '1234567890'];

    /** buyer-account 씬이 남기는 비고 */
    private const RECEIPT_NOTE = '11/14 송금 (가이드 예시)';

    private const HISTORY_NOTE = '11/14 송금분 일부';

    private const FEE_NOTE = '중계은행 수수료';

    /** bulk-shipping-dates 씬이 채우는 칸 — 비워야 「적용 2대」·「기입 2대」가 나온다 */
    private const BULK_COLUMNS = ['shipping_date', 'eta_date', 'vessel_name', 'export_declaration_number', 'container_number'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('GuideSceneFixtureSeeder 는 APP_ENV=local 에서만 돈다.');
        }

        $vehicles = Vehicle::whereIn('vehicle_number', self::PLATES)->get()->keyBy('vehicle_number');
        $missing = array_diff(self::PLATES, $vehicles->keys()->all());
        $buyer = Buyer::where('name', self::BUYER)->first();
        if ($missing !== [] || ! $buyer) {
            throw new \RuntimeException('예시 데이터가 없다: '.implode(', ', array_filter([
                $missing !== [] ? '차량 '.implode('·', $missing) : null,
                $buyer ? null : '바이어 '.self::BUYER,
            ])).' — 이 시더는 되돌리기만 한다(만들지 않는다).');
        }
        $v1131 = $vehicles['00가1131'];
        $v1141 = $vehicles['00가1141'];

        $out = [];

        // ── bank-balance: 예시 계좌 2개 (잔액 행은 FK cascade 로 같이 지워진다). cash_snapshots 는 안 건드린다. ──
        $accounts = CashAccount::where('bank', self::CASH_BANK)->whereIn('account_no', self::CASH_ACCOUNT_NOS)->get();
        foreach ($accounts as $acc) {
            $acc->balances()->delete();
            $acc->delete();
        }
        $out[] = 'bank-balance: 예시 계좌 '.$accounts->count().'개 삭제';

        // ── shipment-fee: 두 발송 번호 줄만. 모델 delete → refreshShippingCaches 훅. ──
        $ships = VehicleShipment::whereIn('vehicle_id', [$v1131->id, $v1141->id])->whereIn('tracking_no', self::SHIPMENT_NOS)->get();
        $shipFailed = 0;
        foreach ($ships as $s) {
            try {
                $s->delete();
            } catch (\Throwable $e) {
                $shipFailed++;   // 2차 정산 마감 차 = guardClosed — 예시 차가 마감되면 씬 전제가 깨진 것
                $this->command?->warn('발송 줄 삭제 실패('.$s->tracking_no.'): '.$e->getMessage());
            }
        }
        $out[] = 'shipment-fee: 발송 줄 '.($ships->count() - $shipFailed).'건 삭제'.($shipFailed ? " / 실패 {$shipFailed}" : '');

        // ── buyer-account: 채권 입금 → (잔금 → 배분 cascade) → 수수료(배분 cascade) → 입금 기재 ──
        $histories = ReceivableHistory::where('vehicle_id', $v1131->id)->where('method', 'deposit')->where('note', self::HISTORY_NOTE)->get();
        $fees = BuyerCashFee::where('buyer_id', $buyer->id)->where('note', self::FEE_NOTE)->get();
        $receipts = BuyerCashReceipt::where('buyer_id', $buyer->id)->where('note', self::RECEIPT_NOTE)->get();
        DB::transaction(function () use ($histories, $fees, $receipts) {
            $prev = FinalPayment::$allowConfirmedMutation;
            FinalPayment::$allowConfirmedMutation = true;   // 확정 잔금 삭제 우회 — try/finally 로만
            try {
                foreach ($histories as $h) {
                    $h->delete();   // deleted 훅 → 연결 FinalPayment 삭제 → buyer_cash_allocations cascade
                }
            } finally {
                FinalPayment::$allowConfirmedMutation = $prev;
            }
            foreach ($fees as $f) {
                $f->delete();   // buyer_cash_allocations.fee_id cascade
            }
            foreach ($receipts as $r) {
                $left = BuyerCashAllocation::where('receipt_id', $r->id)->count();
                if ($left > 0) {
                    // 씬 밖 잔금이 이 입금에 물려 있다 — 지우면 그 잔금이 현금 없이 남는다. 멈춘다.
                    throw new \RuntimeException("입금 기재 #{$r->id} 에 다른 배분 {$left}건이 남아 있다 — 손으로 확인할 것.");
                }
                $r->delete();
            }
        });
        $out[] = 'buyer-account: 채권 입금 '.$histories->count().' · 수수료 '.$fees->count().' · 입금 기재 '.$receipts->count().'건 삭제';

        // ── bulk-shipping-dates: 두 차의 선적 칸 비우기. raw update 뒤 캐시 재계산(§8 #43). ──
        //    ⚠️ 이 칸들은 다른 씬(export-tab 등, 1단계 밖)도 채운다 — 그 씬을 점검에 넣을 때 이 블록을 다시 볼 것.
        DB::table('vehicles')->whereIn('id', [$v1131->id, $v1141->id])
            ->update(array_fill_keys(self::BULK_COLUMNS, null) + ['updated_at' => now()]);
        foreach ([$v1131, $v1141] as $v) {
            $v->refresh();
            $v->refreshProgressCache();
            $v->refreshCaches();
        }
        $out[] = 'bulk-shipping-dates: 00가1131·00가1141 선적 칸 '.count(self::BULK_COLUMNS).'개 비움';

        // ── 편집 잠금 — 촬영·점검이 계정을 바꿔 같은 차를 연다 ──
        foreach ($vehicles as $v) {
            Cache::forget('vehicle-edit-lock:'.$v->id);   // 키 = vehicles/index editLockKey()
        }
        $out[] = '편집 잠금 '.$vehicles->count().'대 해제';

        foreach ($out as $line) {
            $this->command?->info($line);
        }
    }
}
