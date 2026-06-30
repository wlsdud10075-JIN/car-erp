<?php

/**
 * C/D 그룹 13대 현황 덤프 (읽기전용, 2026-06-12).
 *   php scripts/dump-cd.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

$plates = [
    // C 과수금
    '124루1884', '23머9360', '135우3731', '54노0532',
    // D 운임미수
    '06노0655', '145보2909', '13러5014', '13루0364', '272주8289',
    '55부0807', '33보8596', '66나3274', '46주4259',
];

$n = fn ($x) => number_format((float) $x, 0);

foreach ($plates as $plate) {
    // 공백 무시 매칭
    $bare = str_replace(' ', '', $plate);
    $v = Vehicle::with([
        'finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at'),
        'receivableHistories',
        'settlements',
        'buyer',
    ])->get()->first(fn ($x) => str_replace(' ', '', (string) $x->vehicle_number) === $bare);

    if (! $v) {
        echo "❌ NOT FOUND: {$plate}\n\n";

        continue;
    }

    $foreign = $v->currency !== 'KRW';
    $rate = (float) ($v->exchange_rate ?: 0);
    $buyerName = $v->buyer?->name ?? '(없음)';

    echo "── {$v->vehicle_number} (id={$v->id}) [{$v->currency}] buyer={$buyerName} rate={$rate}\n";
    echo "   sale_price={$n($v->sale_price)} transport_fee={$n($v->transport_fee)} other={$n($v->sale_other_costs)} comm={$n($v->commission)} auto={$n($v->auto_loading)} taxdc={$n($v->tax_dc)}\n";
    echo "   sale_total_amount={$n($v->sale_total_amount)}  sale_unpaid_amount={$n($v->sale_unpaid_amount)}  미수KRW캐시={$n($v->sale_unpaid_amount_krw_cache)}\n";
    echo "   progress={$v->progress_status_cache}\n";

    echo "   [finalPayments confirmed]\n";
    foreach ($v->finalPayments as $p) {
        echo "      type={$p->type} amount={$n($p->amount)} date={$p->payment_date} note=".substr((string) $p->note, 0, 40)."\n";
    }
    if ($v->finalPayments->isEmpty()) {
        echo "      (없음)\n";
    }

    if ($v->receivableHistories->isNotEmpty()) {
        echo "   [receivableHistories]\n";
        foreach ($v->receivableHistories as $h) {
            echo "      method={$h->method} amount={$n($h->amount)} date={$h->received_date} note=".substr((string) $h->note, 0, 40)."\n";
        }
    }

    if ($v->settlements->isNotEmpty()) {
        echo "   [settlements]\n";
        foreach ($v->settlements as $s) {
            echo "      status={$s->settlement_status} secondary={$s->secondary_status} salesman={$s->salesman_id} payout={$n($s->actual_payout)}\n";
        }
    }
    echo "\n";
}
