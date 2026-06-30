<?php

/**
 * 로컬 dev DB 차량 데이터 전체 비우고 운영 8대(현금 부풀림 의심)만 적재 (2026-06-11).
 * 목적: 9건 의심 입금을 깔끔히 검토. scripts/dump9.json (운영 raw 덤프) 필요.
 *
 *   php scripts/load9-dev.php
 *
 * ⚠️ 로컬 전용 — production 환경이면 거부. 원복: php artisan migrate:fresh --seed
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (app()->environment('production')) {
    exit("거부: production 환경에서 실행 불가\n");
}

$dbName = DB::connection()->getDatabaseName();
echo "대상 DB: {$dbName} (env=".app()->environment().")\n";

$path = __DIR__.'/dump9.json';
$data = json_decode(file_get_contents($path), true);
if (! $data) {
    exit("dump9.json 파싱 실패\n");
}

$wipe = ['receivable_histories', 'final_payments', 'purchase_balance_payments', 'settlements',
    'inter_vehicle_transfers', 'unpaid_export_overrides', 'vehicle_photos', 'approval_requests',
    'savings_statuses', 'vehicles', 'consignees', 'buyers', 'salesmen'];

DB::statement('SET FOREIGN_KEY_CHECKS=0');
echo "\n[비우기]\n";
foreach ($wipe as $t) {
    try {
        DB::table($t)->truncate();
        echo "  ✓ {$t}\n";
    } catch (Throwable $e) {
        echo "  - skip {$t} (".substr($e->getMessage(), 0, 50).")\n";
    }
}

$order = ['salesmen', 'buyers', 'consignees', 'vehicles', 'final_payments', 'settlements', 'receivable_histories', 'purchase_balance_payments'];
echo "\n[적재 — 로컬 스키마 컬럼만]\n";
foreach ($order as $t) {
    $rows = $data[$t] ?? [];
    if (! $rows) {
        continue;
    }
    $cols = Schema::getColumnListing($t);
    foreach ($rows as $row) {
        $r = array_intersect_key((array) $row, array_flip($cols));
        DB::table($t)->insert($r);
    }
    echo "  ✓ {$t}: ".count($rows)."건\n";
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$cash = 0;
foreach (Vehicle::where('sale_price', '>', 0)->get() as $v) {
    $cash += (int) $v->sale_received_krw_accumulated;
}
echo "\n[결과] 차량 ".DB::table('vehicles')->count().'대 / 확정FP '.DB::table('final_payments')->whereNotNull('confirmed_at')->count()."건\n";
echo '현금(누적) 합 = '.number_format($cash).' ('.round($cash / 1e8)."억)  ← 운영과 동일 부풀림 재현되면 정상\n";
