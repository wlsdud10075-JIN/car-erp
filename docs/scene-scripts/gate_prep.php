<?php
// 로컬 전용 — 게이트 씬 준비/잠금 해제. 사용: php gate_prep.php prep|unlock
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }
$v = \App\Models\Vehicle::where('vehicle_number', '00가1141')->first();
if (($argv[1] ?? '') === 'unlock') {
    \Illuminate\Support\Facades\Cache::forget("vehicle-edit-lock:{$v->id}");
    echo "unlocked {$v->id}\n";
    exit;
}
$c = \App\Models\Consignee::firstOrCreate(['name' => 'GUIDE SAMPLE CONSIGNEE', 'buyer_id' => $v->buyer_id], ['is_active' => true]);
\App\Models\Vehicle::where('id', $v->id)->update(['bl_consignee_id' => $c->id, 'bl_buyer_id' => $v->buyer_id, 'export_consignee_id' => $c->id, 'export_buyer_id' => $v->buyer_id]);
$v->nice_reg_owner_rrn = '900101-1000000';   // 가이드용 가짜 번호
$v->saveQuietly();
\App\Models\Vehicle::where('id', $v->id)->update(['bl_loading_location' => null]);
\App\Models\UnpaidExportOverride::where('vehicle_id', $v->id)->delete();
\Illuminate\Support\Facades\Cache::forget("vehicle-edit-lock:{$v->id}");
echo "prep ok consignee={$c->id}\n";
