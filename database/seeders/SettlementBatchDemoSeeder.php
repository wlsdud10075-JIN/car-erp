<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\SettlementCkBatch;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * [BATCHDEMO] 월배치 정산지급 승인 사다리 테스트용 더미 (2026-07-07, jin 요청).
 *
 * 확정(confirmed) 정산 10건 = 사내직원(per_unit) 5 + 프리랜서(ratio) 5.
 * 각 차량을 매입→말소→판매완납→통관→선적→B/L→거래완료로 태워 정산 자동생성(pending)
 * → settlement_status='confirmed' 로 확정(paid·2차 X). payout_batch_id=NULL 유지.
 *
 * created_at=now() (오늘 기준) → 귀속월은 SettlementCkBatch::payrollMonthOf 규칙:
 *   1~9일 = 전달 귀속(이달 10일 지급) / 10일 이후 = 당월 귀속. 정산화면 월 드롭다운에서 해당 월 선택 후
 *   [관리]/업무관리자 계정으로 「월배치 제출」 → 승인큐(/erp/payout-batches)에서 사다리 승인.
 *
 * 마커: vehicle_number 'BATCHDEMO-*' / salesman '[BATCHDEMO] *' / settlement note 'BATCHDEMO'.
 * 재실행 시 자동 정리 후 재생성:
 *   php artisan db:seed --class=Database\\Seeders\\SettlementBatchDemoSeeder
 */
class SettlementBatchDemoSeeder extends Seeder
{
    private array $log = [];

    public function run(): void
    {
        self::clear();

        // 정산 자동생성 훅(Vehicle::saved) + 확정 가드는 auth 필요 → 관리자 로그인.
        $admin = User::where('permission', 'super')->first()
            ?? User::where('permission', 'admin')->first();
        if (! $admin) {
            $this->command?->error('[BATCHDEMO] super/admin 사용자 없음 — 시드 중단');

            return;
        }
        Auth::login($admin);

        $buyer = Buyer::firstOrCreate(['name' => '[BATCHDEMO] BUYER'], ['is_active' => true]);

        // 한 사람이 한 달에 여러 건 — 인원별 접기/펼치기가 드릴다운으로 보이게 4명에 10건 분배(3/2/3/2).
        // 사내직원(per_unit) 2명=5건, 프리랜서(ratio 50%) 2명=5건. [구입가, 판매가] (KRW·완납).
        $plan = [
            ['name' => '김민수 사내', 'type' => 'employee', 'cars' => [
                [8_000_000, 11_000_000], [12_000_000, 15_500_000], [9_500_000, 12_200_000],
            ]],
            ['name' => '이철수 사내', 'type' => 'employee', 'cars' => [
                [6_000_000, 7_800_000], [20_000_000, 26_000_000],
            ]],
            ['name' => '박영희 프리', 'type' => 'freelance', 'cars' => [
                [10_000_000, 14_000_000], [15_000_000, 21_000_000], [7_000_000, 9_500_000],
            ]],
            ['name' => '최지훈 프리', 'type' => 'freelance', 'cars' => [
                [25_000_000, 33_000_000], [5_500_000, 7_200_000],
            ]],
        ];

        foreach ($plan as $sIdx => $p) {
            $s = $this->salesman($p['name'], $p['type']);
            foreach ($p['cars'] as $cIdx => [$purchase, $sale]) {
                $tag = ($sIdx + 1).'-'.($cIdx + 1);
                $v = $this->drive($s, $buyer, $tag, $purchase, $sale);
                $this->confirm($v);
            }
        }

        $this->report();
    }

    /** 마커 기준 [BATCHDEMO] 데이터 제거 (settlements/FP는 vehicle 명시 삭제). */
    public static function clear(): void
    {
        $vehicleIds = Vehicle::where('vehicle_number', 'like', 'BATCHDEMO-%')->pluck('id');
        Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
        FinalPayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
        Vehicle::whereIn('id', $vehicleIds)->forceDelete();
        Salesman::where('name', 'like', '[BATCHDEMO]%')->forceDelete();
        Buyer::where('name', 'like', '[BATCHDEMO]%')->delete();
    }

    private function salesman(string $name, string $type): Salesman
    {
        return Salesman::create(['name' => '[BATCHDEMO] '.$name, 'type' => $type, 'is_active' => true]);
    }

    /** 매입→말소→판매완납→통관→선적→B/L→거래완료 (KRW·완납이라 C5/G1 게이트 통과). */
    private function drive(Salesman $s, Buyer $buyer, string $tag, int $purchase, int $sale): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'BATCHDEMO-'.$tag,
            'sales_channel' => 'export',
            'salesman_id' => $s->id,
            'buyer_id' => $buyer->id,
            'purchase_date' => '2026-06-01',
            'purchase_price' => $purchase,
            'selling_fee' => 0,
            'currency' => 'KRW',
            'exchange_rate' => 1.0,
        ]);

        $v->update(['is_deregistered' => true, 'deregistration_document' => 'bd/'.$v->id.'.pdf']);

        $v->update([
            'sale_date' => '2026-06-10', 'sale_price' => $sale,
            'commission' => 0, 'auto_loading' => 0, 'tax_dc' => 0, 'transport_fee' => 0, 'sale_other_costs' => 0,
        ]);
        $v->finalPayments()->create([
            'amount' => $sale, 'type' => 'balance',
            'payment_date' => '2026-06-10', 'confirmed_at' => now(),
        ]);
        $v->refresh();

        $v->update(['export_buyer_id' => $buyer->id, 'shipping_date' => '2026-06-15',
            'export_declaration_document' => 'bd/exp'.$v->id.'.pdf', 'is_export_cleared' => true]);
        $v->update(['bl_loading_location' => '부산항']);
        $v->update(['bl_document' => 'bd/bl'.$v->id.'.pdf']);

        return $v->fresh();
    }

    /** 자동생성된 pending 정산을 confirmed 로 확정 (paid·2차 X). */
    private function confirm(Vehicle $v): void
    {
        $st = Settlement::where('vehicle_id', $v->id)->first();
        if (! $st) {
            $this->log[] = "  ✗ {$v->vehicle_number}: 정산 자동생성 안 됨";

            return;
        }
        $st->update([
            'note' => 'BATCHDEMO',
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $st->refresh();

        $this->log[] = sprintf('  ✓ %s — %s (%s) 실지급=%s',
            $v->vehicle_number,
            $st->salesman?->name ?? '-',
            $st->settlement_type === 'ratio' ? '프리랜서' : '사내직원',
            number_format((int) $st->actual_payout));
    }

    private function report(): void
    {
        $out = $this->command;
        $out?->info('[BATCHDEMO] 확정 정산 10건 생성 (담당자별):');

        $bySalesman = Settlement::where('note', 'BATCHDEMO')->with('salesman')->get()
            ->groupBy(fn ($s) => $s->salesman?->name ?? '-');
        foreach ($bySalesman as $name => $group) {
            $out?->line(sprintf('  · %s : %d건 · 실지급합 ₩%s',
                $name, $group->count(), number_format((int) $group->sum(fn ($s) => (int) $s->actual_payout))));
        }
        $out?->line('');
        foreach ($this->log as $line) {
            $out?->line($line);
        }

        $month = SettlementCkBatch::payrollMonthOf(now());
        $pay = Carbon::parse($month.'-01')->addMonthNoOverflow()->format('Y-m').'-10';
        $total = Settlement::where('note', 'BATCHDEMO')->get()->sum(fn ($s) => (int) $s->actual_payout);
        $out?->info(sprintf('[BATCHDEMO] 귀속월 = %s (지급 %s) · 실지급 합계 = ₩%s',
            $month, $pay, number_format($total)));
        $out?->info('[BATCHDEMO] 테스트: 정산화면에서 위 귀속월 선택 → [관리]/업무관리자로 「월배치 제출」 → /erp/payout-batches 승인');
    }
}
