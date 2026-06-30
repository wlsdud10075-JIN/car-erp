<?php

/** C/D + RSH 머지 검증 (읽기전용). php scripts/verify-cd.php */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\SavingsStatus;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

$n = fn ($x) => number_format((float) $x, 0);
$plates = ['124루1884', '23머9360', '135우3731', '54노0532', '06노0655', '145보2909',
    '13러5014', '13루0364', '272주8289', '55부0807', '33보8596', '66나3274', '46주4259'];

echo "── C/D 미수 검증 ──\n";
foreach ($plates as $p) {
    $bare = str_replace(' ', '', $p);
    $v = Vehicle::get()->first(fn ($x) => str_replace(' ', '', (string) $x->vehicle_number) === $bare);
    echo $v ? sprintf("  %-10s 미수=%s\n", $v->vehicle_number, $n($v->sale_unpaid_amount)) : "  ❌ {$p}\n";
}

echo "── RSH 머지 검증 ──\n";
foreach (['36가2483', '56주5094'] as $p) {
    $v = Vehicle::get()->first(fn ($x) => str_replace(' ', '', (string) $x->vehicle_number) === str_replace(' ', '', $p));
    echo sprintf("  %s bl_buyer=%s bl_consignee=%s\n", $v->vehicle_number,
        optional(Buyer::find($v->bl_buyer_id))->name, optional(Consignee::find($v->bl_consignee_id))->name);
}
echo '  RSH 활성: '.(Buyer::where('name', 'RSH')->exists() ? 'Y' : 'N')."\n";
echo '  R.Sh L.L.C(dup) 활성: '.(Consignee::get()->first(fn ($c) => strtoupper(preg_replace('/[\s.,]/', '', $c->name)) === 'RSHLLC' && $c->buyer_id !== Buyer::where('name', 'R.S,H')->first()?->id) ? 'Y' : 'N')."\n";

echo "── 적립금 잔액 ──\n";
$rsh = Buyer::where('name', 'R.S,H')->first();
$mantas = Buyer::where('name', 'MANTAS')->first();
foreach ([[$mantas, 'USD'], [$rsh, 'USD'], [$rsh, 'EUR']] as [$b, $c]) {
    $l = SavingsStatus::where('buyer_id', $b->id)->where('currency', $c)->orderByDesc('id')->first();
    echo "  {$b->name} {$c} = ".$n($l->balance ?? 0)."\n";
}
