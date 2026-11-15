<?php
// 로컬 전용 — 2차 정산 씬(명세서 기입)용 예시 차 2대: 지급완료·비용 대기, 프리랜서 50%.
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }

use App\Models\{Vehicle, Settlement, Salesman, User, FinalPayment};

$admin = User::where('email', 'admin@car-erp.test')->first();
auth()->login($admin);
$buyerId = Vehicle::where('vehicle_number', '00가1113')->value('buyer_id');
$sm = Salesman::firstOrCreate(['name' => '이프리'], ['type' => 'freelance', 'is_active' => true]);
FinalPayment::$skipCashGate = true;
foreach (['00가1121', '00가1122'] as $plate) {
    if (Vehicle::where('vehicle_number', $plate)->exists()) { echo "$plate exists\n"; continue; }
    $v = Vehicle::create([
        'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
        'salesman_id' => $sm->id, 'buyer_id' => $buyerId,
        'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-20',
        'sale_price' => 13_000_000, 'sale_date' => '2026-10-25',
    ]);
    $v->finalPayments()->create(['type' => 'balance', 'amount' => 13_000_000, 'exchange_rate' => 1, 'payment_date' => '2026-10-30', 'confirmed_at' => now()]);
    $v->refresh()->refreshCaches();
    Settlement::$allowBatchPayout = true;
    $s = Settlement::where('vehicle_id', $v->id)->first() ?? Settlement::create([
        'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
        'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-11-01',
    ]);
    if ($s->settlement_status !== 'paid') {
        $s->forceFill(['settlement_status' => 'confirmed', 'confirmed_at' => now(), 'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'attributed_month' => '2026-11-01'])->save();
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
    }
    Settlement::$allowBatchPayout = false;
    $s->refresh();
    echo "$plate v={$v->id} s={$s->id} {$s->settlement_status}/{$s->secondary_status} payout={$s->actual_payout}\n";
}
FinalPayment::$skipCashGate = false;
