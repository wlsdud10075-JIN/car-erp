<?php
// 로컬 전용 — 채권관리 씬 예시 차 00가1141: USD 판매 10,000 @1,400, 계약금 3,000 확정 → 미수 7,000.
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }

use App\Models\{Vehicle, Salesman, User, FinalPayment};

auth()->login(User::where('email', 'admin@car-erp.test')->first());
$buyerId = Vehicle::where('vehicle_number', '00가1113')->value('buyer_id');
$sm = Salesman::firstOrCreate(['name' => '김영업'], ['type' => 'employee', 'is_active' => true]);
FinalPayment::$skipCashGate = true;
$plate = '00가1141';
$v = Vehicle::where('vehicle_number', $plate)->first();
if (! $v) {
    $v = Vehicle::create([
        'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
        'salesman_id' => $sm->id, 'buyer_id' => $buyerId, 'brand' => '현대', 'model_type' => '쏘나타',
        'purchase_price' => 9_000_000, 'purchase_date' => '2026-10-15',
        'sale_price' => 10_000, 'sale_date' => '2026-10-20',
    ]);
    $v->finalPayments()->create(['type' => 'deposit_down', 'amount' => 2_000, 'exchange_rate' => 1400, 'payment_date' => '2026-10-21', 'confirmed_at' => now()]);
}
FinalPayment::$skipCashGate = false;
$v->refresh()->refreshCaches();
$v->refresh();
echo "$plate id={$v->id} unpaid={$v->sale_unpaid_amount} {$v->currency} risk={$v->receivable_risk} progress={$v->progress_status_cache} salesman={$sm->name}\n";
