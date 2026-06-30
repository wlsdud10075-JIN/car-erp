<?php

/**
 * 현금 정정 A+B (2026-06-11). 로컬 복제본 검증용 (운영 적용은 별도 승인).
 *   A 외화슬롯 원화: FP.amount = amount / 환율 (외화 환산) → amount×환율 = 실제 원화
 *   B 이중입금: import + 기존 수동 둘 다인 차량의 'import 입금' FP 삭제
 *
 *   php scripts/fix-cash-ab.php            # dry-run (변경 없음)
 *   php scripts/fix-cash-ab.php --apply    # 실제 적용
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\FinalPayment;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

$apply = in_array('--apply', $argv, true);
echo $apply ? "[APPLY] 실제 적용\n\n" : "[DRY-RUN] 변경 없음 (--apply 로 적용)\n\n";

$vs = Vehicle::with(['finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at')])
    ->where('sale_price', '>', 0)->get();

$cashBefore = 0;
foreach ($vs as $v) {
    $cashBefore += (int) $v->sale_received_krw_accumulated;
}

$aFix = [];   // [fp, newAmount]
$bDel = [];   // fp to delete
foreach ($vs as $v) {
    $foreign = $v->currency !== 'KRW';
    $saleTotal = (float) $v->sale_total_amount;
    $tol = max($saleTotal * 0.01, 2);

    $recv = 0;
    $hasImp = false;
    $hasMan = false;
    $imps = [];
    foreach ($v->finalPayments as $p) {
        $recv += (float) $p->amount;
        $isImport = str_contains((string) $p->note, 'import');
        if ($isImport) {
            $hasImp = true;
            $imps[] = $p;
        } else {
            $hasMan = true;
        }
    }
    // A
    $isA = false;
    foreach ($v->finalPayments as $p) {
        if ($foreign && (float) $p->amount >= 1_000_000) {
            $rate = $p->exchange_rate !== null ? (float) $p->exchange_rate : (float) $v->exchange_rate;
            $aFix[] = [$p, round((float) $p->amount / $rate, 2), $v];
            $isA = true;
        }
    }
    if ($isA) {
        continue;
    }
    // B — DUP: import+수동 둘 다 & 합계 초과 → import FP 삭제
    if ($hasImp && $hasMan && $recv > $saleTotal + $tol) {
        foreach ($imps as $p) {
            $bDel[] = [$p, $v];
        }
    }
}

echo '■ A 외화슬롯 정정 대상 FP '.count($aFix)."건\n";
foreach ($aFix as [$p, $new, $v]) {
    echo "   {$v->vehicle_number} FP#{$p->id} amount ".number_format($p->amount, 2).' → '.number_format($new, 2)." (×{$p->exchange_rate})\n";
}
echo "\n■ B 이중입금 삭제 대상 FP ".count($bDel)."건\n";
foreach ($bDel as [$p, $v]) {
    echo "   {$v->vehicle_number} FP#{$p->id} 삭제 (amount ".number_format($p->amount, 2).", note='{$p->note}')\n";
}

if ($apply) {
    FinalPayment::$allowConfirmedMutation = true;
    foreach ($aFix as [$p, $new, $v]) {
        $p->amount = $new;   // saving 훅이 amount_krw 재계산
        $p->save();
    }
    foreach ($bDel as [$p, $v]) {
        $p->delete();
    }
    FinalPayment::$allowConfirmedMutation = false;
    Vehicle::where('sale_price', '>', 0)->get()->each->refreshCaches();
}

$cashAfter = 0;
foreach (Vehicle::with(['finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at')])->where('sale_price', '>', 0)->get() as $v) {
    $cashAfter += (int) $v->sale_received_krw_accumulated;
}

echo "\n================ 현금 합계 ================\n";
echo '정정 전 = '.number_format($cashBefore).' ('.round($cashBefore / 1e8)."억)\n";
echo $apply
    ? '정정 후 = '.number_format($cashAfter).' ('.round($cashAfter / 1e8, 1)."억)\n"
    : "정정 후(예상) = 위 대상 반영 시 ~27억\n";
