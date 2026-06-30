<?php

/**
 * 로컬 dev DB를 운영 전체 데이터로 교체 (2026-06-11). scripts/full.json (운영 raw 덤프) 필요.
 * 목적: 현금 부풀림 등 이슈 전수 확인. RRN/계좌 등 암호화 컬럼은 덤프 시 비워짐(APP_KEY 상이).
 *
 *   php scripts/load-full-dev.php
 *
 * ⚠️ 로컬 전용 — production 거부. 원복: php artisan migrate:fresh --seed
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

if (app()->environment('production')) {
    exit("거부: production 환경에서 실행 불가\n");
}

$dbName = DB::connection()->getDatabaseName();
echo "대상 DB: {$dbName} (env=".app()->environment().")\n";

$data = json_decode(file_get_contents(__DIR__.'/full.json'), true);
if (! $data) {
    exit("full.json 파싱 실패\n");
}

// FK 안전 적재 순서 (부모 → 자식). 그 외는 뒤에 자동 추가.
$order = ['countries', 'settings', 'users', 'salesmen', 'forwarding_companies', 'ports',
    'buyers', 'consignees', 'savings_statuses', 'vehicles',
    'final_payments', 'purchase_balance_payments', 'settlements', 'receivable_histories',
    'vehicle_photos', 'inter_vehicle_transfers', 'unpaid_export_overrides', 'carryover_clearances',
    'approval_requests', 'audit_logs', 'document_access_logs'];
foreach (array_keys($data) as $t) {
    if (! in_array($t, $order, true)) {
        $order[] = $t;
    }
}

DB::statement('SET FOREIGN_KEY_CHECKS=0');
echo "\n[비우기]\n";
foreach (array_reverse($order) as $t) {
    if (! isset($data[$t])) {
        continue;
    }
    try {
        DB::table($t)->truncate();
    } catch (Throwable $e) {
        echo "  - skip {$t}\n";
    }
}

echo "[적재]\n";
$total = 0;
foreach ($order as $t) {
    $rows = $data[$t] ?? [];
    if (! $rows) {
        continue;
    }
    if (! Schema::hasTable($t)) {
        echo "  - 로컬에 테이블 없음: {$t} (건너뜀)\n";

        continue;
    }
    $cols = Schema::getColumnListing($t);
    foreach (array_chunk($rows, 200) as $chunk) {
        $clean = array_map(fn ($r) => array_intersect_key((array) $r, array_flip($cols)), $chunk);
        DB::table($t)->insert($clean);
    }
    $total += count($rows);
    echo "  ✓ {$t}: ".count($rows)."건\n";
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// 알려진 로컬 admin 로그인 보장 (운영 비번 몰라도 접속 가능)
User::updateOrCreate(
    ['email' => 'admin@car-erp.test'],
    ['name' => '로컬관리자', 'password' => Hash::make('password'), 'permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]
);

$cash = 0;
foreach (Vehicle::where('sale_price', '>', 0)->get() as $v) {
    $cash += (int) $v->sale_received_krw_accumulated;
}
echo "\n[결과] 총 {$total}행 적재 / 차량 ".DB::table('vehicles')->count()."대\n";
echo '현금(누적) 합 = '.number_format($cash).' ('.round($cash / 1e8)."억)  ← 운영 1761억과 일치하면 복제 성공\n";
echo "로그인: admin@car-erp.test / password\n";
