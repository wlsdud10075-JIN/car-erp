<?php
// 로컬 전용 — 매입취소 씬 예시 차 00가1151 · 00가1152: USD 판매 10,000(입금 0) · 프리랜서 이프리.
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }

use App\Models\{Vehicle, Salesman, User};

auth()->login(User::where('email', 'admin@car-erp.test')->first());
$buyerId = Vehicle::where('vehicle_number', '00가1113')->value('buyer_id');
$sm = Salesman::firstOrCreate(['name' => '이프리'], ['type' => 'freelance', 'is_active' => true]);
foreach (['00가1151' => '모닝', '00가1152' => '레이'] as $plate => $model) {
    $v = Vehicle::where('vehicle_number', $plate)->first();
    if (! $v) {
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'buyer_id' => $buyerId, 'brand' => '기아', 'model_type' => $model,
            'purchase_price' => 8_000_000, 'purchase_date' => '2026-10-20',
            'sale_price' => 10_000, 'sale_date' => '2026-10-25',
        ]);
    }
    \Illuminate\Support\Facades\Cache::forget("vehicle-edit-lock:{$v->id}");
    $v->refresh()->refreshCaches();
    echo "$plate id={$v->id} unpaid={$v->fresh()->sale_unpaid_amount} cancel={$v->cancel_status}\n";
}
