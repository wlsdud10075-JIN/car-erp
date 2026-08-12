<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * [CARRYDEMO] 미청산 이월 표시 검증용 데모 (2026-06-10, /loop E2E).
 *
 * 5대 차량 / 4 영업담당자를 전 워크플로우(매입→말소→판매완납→통관→선적→B/L→거래완료
 * →1차 paid→2차 closed)로 태우고, 미청산 이월(stranded carryover)을 4가지로 구성:
 *   - S_A (프리랜서, USD) : closed +320,000  → stranded (+이월 = 지급 대기)
 *   - S_B (프리랜서, USD) : closed −150,000  → stranded (−이월 = 청구 대상)
 *   - S_C (사내직원, KRW) : closed 0          → 0 (employee 환차 미반영 → 표시 안 됨, 정상)
 *   - S_D (프리랜서)      : V4 closed +200,000 → V5 다음 정산이 흡수 → net 0 (흡수 데모)
 *
 * 마커: vehicle_number 'CARRYDEMO-*' / salesman name '[CARRYDEMO] *' / settlement note 'CARRYDEMO'.
 * 제거:  php artisan db:seed --class=Database\\Seeders\\CarryDemoSeeder  (재실행 시 자동 정리 후 재생성)
 *        또는  CarryDemoSeeder::clear()  로직과 동일한 마커 기준 삭제.
 *
 * close 계산(환차→carryover_out)은 Livewire closeSecondarySettlement 와 동일 공식을 컬럼에 직접 기입.
 * 실제 close 로직 자체는 E2eSettlementWorkflowTest 가 검증함. 이 시더는 '표시' 검증용.
 */
class CarryDemoSeeder extends Seeder
{
    private array $log = [];

    public function run(): void
    {
        self::clear();

        // 정산 자동생성 훅(Vehicle::saved)은 auth 필요 → 관리자 로그인.
        $admin = User::where('permission', 'super')->first()
            ?? User::where('permission', 'admin')->first();
        if (! $admin) {
            $this->command?->error('[CARRYDEMO] super/admin 사용자 없음 — 시드 중단');

            return;
        }
        Auth::login($admin);

        $buyer = Buyer::firstOrCreate(['name' => '[CARRYDEMO] BUYER'], ['is_active' => true]);

        $sA = $this->salesman('S_A 프리USD');
        $sB = $this->salesman('S_B 프리USD');
        $sC = $this->salesman('S_C 사내KRW', 'employee');
        $sD = $this->salesman('S_D 프리흡수');

        // V1 — S_A: USD 환차익 +320,000 → stranded (+)
        $v1 = $this->drive($sA, $buyer, 'USD', 1300.0, ['purchase_price' => 15_000_000, 'sale_price' => 20_000], 'A');
        $this->settle($v1, fxDiff: 320_000, closeRate: 1316.0);

        // V2 — S_B: USD 환차손 −150,000 → stranded (−)
        $v2 = $this->drive($sB, $buyer, 'USD', 1300.0, ['purchase_price' => 12_000_000, 'sale_price' => 20_000], 'B');
        $this->settle($v2, fxDiff: -150_000, closeRate: 1292.5);

        // V3 — S_C: KRW 사내직원 → carryover 0 (표시 안 됨)
        $v3 = $this->drive($sC, $buyer, 'KRW', 1.0, ['purchase_price' => 10_000_000, 'selling_fee' => 1_000_000, 'sale_price' => 13_000_000], 'C');
        $this->settle($v3, fxDiff: 0, closeRate: 1.0);

        // V4 — S_D: 환차익 +200,000 (closed)
        $v4 = $this->drive($sD, $buyer, 'USD', 1300.0, ['purchase_price' => 9_000_000, 'sale_price' => 10_000], 'D1');
        $this->settle($v4, fxDiff: 200_000, closeRate: 1320.0);
        // V5 — S_D 다음 정산 → creating 훅이 +200,000 흡수 → S_D net 0
        $v5 = $this->drive($sD, $buyer, 'KRW', 1.0, ['purchase_price' => 8_000_000, 'sale_price' => 11_000_000], 'D2');
        // V5는 1차만(흡수 확인용). 거래완료 시 자동 정산 created → carryover_in 흡수됨.

        $this->report([$sA, $sB, $sC, $sD]);
    }

    /** 마커 기준 [CARRYDEMO] 데이터 제거 (FK: settlements/FP는 vehicle cascade 가정 — 명시 삭제). */
    public static function clear(): void
    {
        $vehicleIds = Vehicle::where('vehicle_number', 'like', 'CARRYDEMO-%')->pluck('id');
        Settlement::whereIn('vehicle_id', $vehicleIds)->forceDelete();
        FinalPayment::whereIn('vehicle_id', $vehicleIds)->forceDelete();
        Vehicle::whereIn('id', $vehicleIds)->forceDelete();
        Salesman::where('name', 'like', '[CARRYDEMO]%')->forceDelete();
        Buyer::where('name', 'like', '[CARRYDEMO]%')->delete();
    }

    private function salesman(string $name, string $type = 'freelance'): Salesman
    {
        return Salesman::create(['name' => '[CARRYDEMO] '.$name, 'type' => $type, 'is_active' => true]);
    }

    /** 매입→말소→판매완납→통관→선적→B/L→거래완료. 각 단계 progress_status cascade 검증. */
    private function drive(Salesman $s, Buyer $buyer, string $currency, float $rate, array $fin, string $tag): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'CARRYDEMO-'.$tag,
            'sales_channel' => 'export',
            'salesman_id' => $s->id,
            'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-01',
            'purchase_price' => $fin['purchase_price'],
            'selling_fee' => $fin['selling_fee'] ?? 0,
            'currency' => $currency,
            'exchange_rate' => $rate,
        ]);
        $this->expect($v, ['매입중', '매입완료'], "$tag 매입");

        $v->update(['is_deregistered' => true, 'deregistration_document' => 'cd/'.$v->id.'.pdf']);
        $this->expect($v, ['말소완료'], "$tag 말소");

        $v->update([
            'sale_date' => '2026-05-10', 'sale_price' => $fin['sale_price'],
            'commission' => 0, 'auto_loading' => 0, 'tax_dc' => 0, 'transport_fee' => 0, 'sale_other_costs' => 0,
        ]);
        $v->finalPayments()->create([
            'amount' => $fin['sale_price'], 'type' => 'balance',
            'payment_date' => '2026-05-10', 'confirmed_at' => now(),
        ]);
        $v->refresh();
        $this->expect($v, ['판매완료'], "$tag 판매완납", (int) $v->sale_unpaid_amount === 0);

        $v->update(['export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-15',
            'export_declaration_document' => 'cd/exp'.$v->id.'.pdf', 'is_export_cleared' => true]);
        $v->update(['bl_loading_location' => '부산항']);
        $this->expect($v, ['통관중'], "$tag 통관/선적");

        $v->update(['bl_document' => 'cd/bl'.$v->id.'.pdf']);
        $this->expect($v, ['거래완료'], "$tag 거래완료");

        return $v->fresh();
    }

    /** 1차 confirmed→paid(스냅샷) → 2차 closed (환차·carryover_out 컬럼 직접 기입). */
    private function settle(Vehicle $v, int $fxDiff, float $closeRate): void
    {
        $st = Settlement::where('vehicle_id', $v->id)->first();
        if (! $st) {
            $this->log[] = "  ✗ {$v->vehicle_number}: 정산 자동생성 안 됨";

            return;
        }
        $st->update(['note' => 'CARRYDEMO']);

        // 1차 → paid (Settlement::saving 훅이 confirmed_snapshot 캡처 + secondary='pending')
        $st->update(['settlement_status' => 'confirmed']);
        $st->update(['settlement_status' => 'paid']);
        $st->refresh();
        $snapshotPayout = (int) ($st->confirmed_snapshot['actual_payout'] ?? 0);

        // 2차 closed — closeSecondarySettlement 와 동일 컬럼 기입.
        $st->update([
            'secondary_status' => 'closed',
            'secondary_closed_at' => now(),
            'exchange_rate_at_close' => $closeRate,
            'exchange_difference_krw' => $fxDiff,
        ]);
        // carryover_out = closed actual_payout(환차 반영) − paid 스냅샷. ratio면 accessor가 fx 가산.
        $closedPayout = $st->fresh()->actual_payout;
        $carryoverOut = $closedPayout - $snapshotPayout;
        if ($carryoverOut !== 0) {
            $st->update(['carryover_out_krw' => $carryoverOut]);
        }
        $this->log[] = sprintf('  ✓ %s: paid스냅샷=%s closed payout=%s → carryover_out=%s',
            $v->vehicle_number, number_format($snapshotPayout), number_format($closedPayout), number_format($carryoverOut));
    }

    private function expect(Vehicle $v, array $allowed, string $label, bool $extra = true): void
    {
        $status = $v->fresh()->progress_status;
        $ok = in_array($status, $allowed, true) && $extra;
        $this->log[] = ($ok ? '  ✓ ' : '  ✗ ').$label.' → '.$status.($ok ? '' : ' (기대: '.implode('/', $allowed).')');
    }

    private function report(array $salesmen): void
    {
        $out = $this->command;
        $out?->info('[CARRYDEMO] 워크플로우 단계 점검:');
        foreach ($this->log as $line) {
            $out?->line($line);
        }
        $out?->info('[CARRYDEMO] 담당자별 미청산 이월 (표시 검증):');
        foreach ($salesmen as $s) {
            $s->refresh();
            $co = $s->unconsumed_carryover;
            $tag = $co > 0 ? '(+ 지급대기)' : ($co < 0 ? '(− 청구대상)' : '(0 표시안됨)');
            $out?->line(sprintf('  %s : unconsumed_carryover = %s %s', $s->name, number_format($co), $tag));
        }
    }
}
