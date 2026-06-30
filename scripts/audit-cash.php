<?php

/**
 * 현금/입금 정합성 전수 감사 (2026-06-11, 로컬 dev = 운영 복제본).
 * 유형: A 외화 won-in-slot / B 과수금·운임미기록 의심 / C 운임만큼 미수 / D 환율0 외화.
 *   php scripts/audit-cash.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

$A = [];
$B = [];
$C = [];
$D = [];
$curCash = 0;
$fixCash = 0;

$vs = Vehicle::with(['finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at'), 'receivableHistories'])
    ->where('sale_price', '>', 0)->get();

foreach ($vs as $v) {
    $cur = $v->currency;
    $rate = (float) ($v->exchange_rate ?: 0);
    $saleTotal = (float) $v->sale_total_amount;   // 통화 단위
    $freight = (float) $v->transport_fee;
    $unpaid = (float) $v->sale_unpaid_amount;     // 통화 단위
    $foreign = $cur !== 'KRW';

    // 확정 FP (통화 단위 amount) + 현금 환산
    $recvCur = 0;
    $vehCurCash = 0;
    $vehFixCash = 0;
    $wonSlotFps = [];
    foreach ($v->finalPayments as $p) {
        $amt = (float) $p->amount;
        $r = $p->exchange_rate !== null ? (float) $p->exchange_rate : $rate;
        $recvCur += $amt;
        $vehCurCash += $amt * $r;
        // 외화차인데 amount가 100만 이상 = 사실상 원화 (중고차 외화결제는 보통 <20만)
        if ($foreign && $amt >= 1_000_000) {
            $wonSlotFps[] = $p;
            $vehFixCash += $amt;          // 이미 원화 → 그대로
        } else {
            $vehFixCash += $amt * $r;
        }
    }
    $curCash += $vehCurCash;
    $fixCash += $vehFixCash;

    if (! empty($wonSlotFps)) {
        $A[] = [$v, $wonSlotFps, $vehCurCash, $vehFixCash];

        continue; // A로 분류되면 B/C 중복 제외
    }
    // B 과수금/운임미기록 의심 — received(통화) > sale_total
    if ($recvCur > $saleTotal * 1.01 && $saleTotal > 0) {
        $B[] = [$v, $recvCur, $saleTotal, $freight, $recvCur - $saleTotal];

        continue;
    }
    // C 운임만큼 미수 (미수 > 0 이고 운임 범위 내)
    if ($unpaid > 0.5 && $freight > 0 && $unpaid <= $freight * 1.05) {
        $C[] = [$v, $unpaid, $freight];
    }
    // D 환율0 외화
    if ($foreign && $rate <= 0) {
        $D[] = $v;
    }
}

$f = fn ($n) => number_format((int) $n);
echo '================= 현금/입금 전수 감사 (판매차 '.count($vs)."대) =================\n\n";

echo '■ A. 외화 won-in-slot — 현금 부풀림 주범 ('.count($A)."대)\n";
echo "   (외화차인데 입금 amount가 100만↑ = 원화가 외화슬롯에 들어가 ×환율 폭발)\n";
foreach ($A as [$v, $fps, $cc, $fc]) {
    $amts = implode(' + ', array_map(fn ($p) => number_format($p->amount), $fps));
    echo "   {$v->vehicle_number} ({$v->currency} r={$v->exchange_rate}) 판매가={$f($v->sale_price)}  amount=[{$amts}]\n";
    echo '       현재현금 '.$f($cc).' → 보정 '.$f($fc)."\n";
}

echo "\n■ B. 과수금/운임 미기록 의심 — 입금(통화) > 판매합계 (".count($B)."대)\n";
echo "   (운임을 transport_fee에 안 넣었는데 입금엔 포함됐을 가능성)\n";
foreach (array_slice($B, 0, 40) as [$v, $rc, $st, $fr, $over]) {
    echo "   {$v->vehicle_number} ({$v->currency}) 판매합계=".number_format($st, 2).' 입금='.number_format($rc, 2).' 초과='.number_format($over, 2)." (운임칸={$f($fr)})\n";
}
if (count($B) > 40) {
    echo '   … 외 '.(count($B) - 40)."대\n";
}

echo "\n■ C. 운임만큼 미수 — 운임 미회수 추정 (".count($C)."대)\n";
foreach (array_slice($C, 0, 40) as [$v, $up, $fr]) {
    echo "   {$v->vehicle_number} ({$v->currency}) 미수=".number_format($up, 2).' ≈ 운임='.number_format($fr, 2)."\n";
}
if (count($C) > 40) {
    echo '   … 외 '.(count($C) - 40)."대\n";
}

echo "\n■ D. 환율 0 외화차 (".count($D)."대)\n";
foreach ($D as $v) {
    echo "   {$v->vehicle_number} ({$v->currency}) 환율=0\n";
}

echo "\n================= 현금 합계 =================\n";
echo '현재(버그 포함) = '.$f($curCash).' ('.round($curCash / 1e8)."억)\n";
echo '보정(won-in-slot만 수정) = '.$f($fixCash).' ('.round($fixCash / 1e8, 1)."억)\n";
