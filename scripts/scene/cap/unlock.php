<?php
// 로컬 전용 — 차량 편집 잠금 풀기(캡처용). 사용: php unlock.php <vehicleId>
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }
\Illuminate\Support\Facades\Cache::forget('vehicle-edit-lock:'.($argv[1] ?? 0));
echo "ok\n";
