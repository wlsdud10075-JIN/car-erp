<?php

/**
 * 현금/입금 전수 분류 v2 (2026-06-11). 146 판매차를 상호배타 버킷으로 분류.
 * 우선순위: WON_SLOT > DUP(import+수동 이중) > OVERPAY > FREIGHT_UNPAID > UNDERPAID > NORMAL.
 *   php scripts/audit-cash2.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

$buckets = ['WON_SLOT' => [], 'DUP' => [], 'OVERPAY' => [], 'FREIGHT_UNPAID' => [], 'UNDERPAID' => [], 'NORMAL' => []];

$vs = Vehicle::with(['finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at'), 'receivableHistories'])
    ->where('sale_price', '>', 0)->get();

foreach ($vs as $v) {
    $foreign = $v->currency !== 'KRW';
    $rate = (float) ($v->exchange_rate ?: 0);
    $saleTotal = (float) $v->sale_total_amount;
    $freight = (float) $v->transport_fee;
    $unpaid = (float) $v->sale_unpaid_amount;
    $tol = max($saleTotal * 0.01, 2);

    $recv = 0;
    $impSum = 0;
    $manSum = 0;
    $hasImp = false;
    $hasMan = false;
    $wonSlot = [];
    foreach ($v->finalPayments as $p) {
        $amt = (float) $p->amount;
        $recv += $amt;
        $isImport = str_contains((string) $p->note, 'import');
        if ($isImport) {
            $hasImp = true;
            $impSum += $amt;
        } else {
            $hasMan = true;
            $manSum += $amt;
        }
        if ($foreign && $amt >= 1_000_000) {
            $wonSlot[] = $amt;
        }
    }
    // 비-deposit 회수이력도 received 에 포함(§13)
    foreach ($v->receivableHistories as $h) {
        if ($h->method !== 'deposit') {
            $recv += (float) $h->amount;
        }
    }

    $row = compact('v', 'saleTotal', 'freight', 'unpaid', 'recv', 'impSum', 'manSum', 'wonSlot');

    if ($wonSlot) {
        $buckets['WON_SLOT'][] = $row;
    } elseif ($hasImp && $hasMan && $recv > $saleTotal + $tol) {
        $buckets['DUP'][] = $row;
    } elseif ($recv > $saleTotal + $tol) {
        $buckets['OVERPAY'][] = $row;
    } elseif ($unpaid > 0.5 && $freight > 0 && $unpaid <= $freight * 1.05) {
        $buckets['FREIGHT_UNPAID'][] = $row;
    } elseif ($unpaid > 0.5) {
        $buckets['UNDERPAID'][] = $row;
    } else {
        $buckets['NORMAL'][] = $row;
    }
}

$n2 = fn ($x) => number_format($x, 2);
echo '================ 전수 분류 v2 (판매차 '.count($vs)."대) ================\n";
foreach ($buckets as $k => $list) {
    printf("  %-15s %d대\n", $k, count($list));
}
echo "\n";

$desc = [
    'WON_SLOT' => 'A. 외화슬롯에 원화 — 현금 폭발',
    'DUP' => 'B. 이중입금 (import + 기존 수동 둘 다)',
    'OVERPAY' => 'C. 과수금 (입금 > 판매합계, 이중 아님)',
    'FREIGHT_UNPAID' => 'D. 운임만큼 미수',
    'UNDERPAID' => 'E. 기타 미수 (운임 외)',
];
foreach (['WON_SLOT', 'DUP', 'OVERPAY', 'FREIGHT_UNPAID', 'UNDERPAID'] as $k) {
    $list = $buckets[$k];
    echo "■ {$desc[$k]} — ".count($list)."대\n";
    foreach (array_slice($list, 0, 50) as $r) {
        $v = $r['v'];
        $extra = '';
        if ($k === 'DUP') {
            $extra = " import={$n2($r['impSum'])} 수동={$n2($r['manSum'])}";
        } elseif ($k === 'WON_SLOT') {
            $extra = ' amount=['.implode('+', array_map(fn ($a) => number_format($a), $r['wonSlot'])).']';
        } elseif ($k === 'OVERPAY') {
            $extra = ' 초과='.$n2($r['recv'] - $r['saleTotal']);
        } elseif ($k === 'FREIGHT_UNPAID') {
            $extra = ' 미수='.$n2($r['unpaid']).' 운임='.$n2($r['freight']);
        } elseif ($k === 'UNDERPAID') {
            $extra = ' 미수='.$n2($r['unpaid']).' 운임='.$n2($r['freight']);
        }
        echo "   {$v->vehicle_number} ({$v->currency}) 판매합계=".$n2($r['saleTotal']).' 입금='.$n2($r['recv']).$extra."\n";
    }
    if (count($list) > 50) {
        echo '   … 외 '.(count($list) - 50)."대\n";
    }
    echo "\n";
}
echo 'NORMAL '.count($buckets['NORMAL'])."대는 입금≈판매합계 (이상 없음) — 생략\n";
