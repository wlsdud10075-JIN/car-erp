<?php

/**
 * C 과수금 → 바이어 적립금 전환 / D 완납 → 미수 0 정리 (2026-06-12, jin 엑셀대조 확정).
 *   php scripts/fix-cd.php           # dry-run (변경 미적용, 계획만 출력)
 *   php scripts/fix-cd.php --apply   # 실제 적용
 *
 * 멱등: note 마커 [CD-FIX] 존재 시 해당 차량 skip.
 * C: 과수입금 FP 를 sale_total 까지 축소(미수 0) + 초과분을 buyer×currency 적립금(EARNED).
 * D 완납: ReceivableHistory(method=other, amount=미수) 추가 → 미수 0 (현금회수 accessor=FP-only 라 영향 없음).
 * D 미납(272주8289·13루0364): 변동 없음.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\SavingsStatus;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$APPLY = in_array('--apply', $argv, true);
$MARKER = '[CD-FIX]';
$COLLECTOR = 1;            // 로컬관리자(super)
$TODAY = '2026-06-12';

$find = function (string $plate): ?Vehicle {
    $bare = str_replace(' ', '', $plate);

    return Vehicle::with(['finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at'), 'receivableHistories'])
        ->get()->first(fn ($x) => str_replace(' ', '', (string) $x->vehicle_number) === $bare);
};
$n = fn ($x) => number_format((float) $x, 0);

// ── C: 과수금 → 적립금 ────────────────────────────────────────────
$C = ['124루1884', '23머9360', '135우3731', '54노0532'];

// ── D 완납 → 미수 0 (jin 사유) ────────────────────────────────────
$D_PAID = [
    '06노0655' => '혜진 사원 6월 정산 완료 — 운임 미수 정리',
    '145보2909' => '혜진 사원 6월 정산 완료 — 운임 미수 정리',
    '33보8596' => '혜진 사원 6월 정산 완료 — 잔여 미수 정리',
    '66나3274' => '가영 실장 5월 정산 완료 — 선수금 20 사용분 정리',
    '55부0807' => '가영 실장 5월 정산 완료 — 선수금 155 사용분 정리',
    '46주4259' => '가영 실장 5월 정산 완료 — 잔여 미수 정리',
    '13러5014' => '운임까지 전액 수령 확인 — 미수 정리',
];

echo $APPLY ? "════ APPLY ════\n\n" : "════ DRY-RUN (--apply 로 실제 적용) ════\n\n";

// ===== C =====
echo "■ C 과수금 → 적립금\n";
foreach ($C as $plate) {
    $v = $find($plate);
    if (! $v) {
        echo "  ❌ NOT FOUND {$plate}\n";

        continue;
    }

    if (SavingsStatus::where('vehicle_id', $v->id)->where('note', 'like', "%{$MARKER}%")->exists()) {
        echo "  ⏭  {$v->vehicle_number} 이미 처리됨(skip)\n";

        continue;
    }

    $received = (float) $v->finalPayments->sum('amount');
    $saleTotal = (float) $v->sale_total_amount;
    $excess = round($received - $saleTotal, 2);
    if ($excess <= 0) {
        echo "  ⚠  {$v->vehicle_number} 초과액 없음({$n($excess)}) skip\n";

        continue;
    }

    $lastFp = $v->finalPayments->sortBy('id')->last();
    echo "  • {$v->vehicle_number} 초과 {$n($excess)} {$v->currency} → 적립금 / FP#{$lastFp->id} {$n($lastFp->amount)}→{$n($lastFp->amount - $excess)}\n";

    if ($APPLY) {
        DB::transaction(function () use ($v, $excess, $lastFp, $MARKER) {
            // 1) 과수 FP 축소 → 미수 0
            FinalPayment::$allowConfirmedMutation = true;
            try {
                $lastFp->amount = round((float) $lastFp->amount - $excess, 2);
                $lastFp->finance_note = trim(((string) $lastFp->finance_note)." {$MARKER} 과수금 {$excess} 적립금 전환");
                $lastFp->save();
            } finally {
                FinalPayment::$allowConfirmedMutation = false;
            }
            // 2) 적립금 EARNED (buyer×currency 잔액 스냅샷)
            $latest = SavingsStatus::where('buyer_id', $v->buyer_id)
                ->where('currency', $v->currency)
                ->lockForUpdate()->orderByDesc('id')->first();
            $newBal = (float) ($latest?->balance ?? 0) + $excess;
            SavingsStatus::create([
                'buyer_id' => $v->buyer_id,
                'vehicle_id' => $v->id,
                'currency' => $v->currency,
                'transaction_type' => 'EARNED',
                'savings' => $excess,
                'balance' => $newBal,
                'note' => "{$MARKER} 차량 {$v->vehicle_number} 과수금 적립금 전환",
            ]);
            $v->refreshCaches();
        });
    }
}

// ===== D 완납 =====
echo "\n■ D 완납 → 미수 0\n";
foreach ($D_PAID as $plate => $reason) {
    $v = $find($plate);
    if (! $v) {
        echo "  ❌ NOT FOUND {$plate}\n";

        continue;
    }

    if (ReceivableHistory::where('vehicle_id', $v->id)->where('note', 'like', "%{$MARKER}%")->exists()) {
        echo "  ⏭  {$v->vehicle_number} 이미 처리됨(skip)\n";

        continue;
    }

    $unpaid = round((float) $v->sale_unpaid_amount, 2);
    if ($unpaid <= 0) {
        echo "  ⚠  {$v->vehicle_number} 미수 이미 0({$n($unpaid)}) skip\n";

        continue;
    }

    echo "  • {$v->vehicle_number} 미수 {$n($unpaid)} {$v->currency} → 0 (기타정리: {$reason})\n";

    if ($APPLY) {
        ReceivableHistory::create([
            'vehicle_id' => $v->id,
            'collected_at' => $TODAY,
            'collector_id' => $COLLECTOR,
            'method' => 'other',
            'amount' => $unpaid,
            'note' => "{$MARKER} {$reason}",
        ]);
    }
}

echo "\n■ D 미납(변동 없음): 272주8289 (6/25 도착)·13루0364 (7/22 도착) — 운임 입금 예정, 미수 유지\n";
echo "\n완료. ".($APPLY ? "적용됨. dump-cd.php 로 검증.\n" : "dry-run. --apply 로 실제 적용.\n");
