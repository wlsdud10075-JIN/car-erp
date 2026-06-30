<?php

/**
 * 서버 RSH 삭제 후 복구 — 36가2483·56주5094 의 bl_buyer/bl_consignee 를 R.S,H 로 재연결.
 *   php scripts/fix-rsh-server.php           # dry-run + 진단
 *   php scripts/fix-rsh-server.php --apply
 *
 * 번호판 기반(ID 독립). RSH 가 이미 삭제(soft/hard)된 서버에서도 동작.
 * 멱등: 이미 R.S,H + keeper 컨사이니면 변동 없음.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$APPLY = in_array('--apply', $argv, true);
$norm = fn (string $s) => strtoupper(preg_replace('/[\s.,]/', '', $s));
$PLATES = ['36가2483', '56주5094'];

echo $APPLY ? "════ APPLY ════\n" : "════ DRY-RUN + 진단 ════\n";

// 진단: RSH / R.S,H 바이어 (trashed 포함)
echo "── 바이어 상태 (trashed 포함)\n";
foreach (Buyer::withTrashed()->whereIn('name', ['RSH', 'R.S,H'])->get() as $b) {
    echo sprintf("  id=%-3d name=%-6s deleted_at=%s\n", $b->id, $b->name, $b->deleted_at ?? '(활성)');
}
$keep = Buyer::where('name', 'R.S,H')->first();
if (! $keep) {
    exit("❌ 활성 keeper 'R.S,H' 없음 — 중단\n");
}

// keeper 컨사이니 (R.SH L.L.C 정규화 일치, keep 소속, 활성)
$keepCons = Consignee::where('buyer_id', $keep->id)->get()
    ->first(fn ($c) => $norm($c->name) === 'RSHLLC');
echo "── keeper: 바이어 R.S,H(id={$keep->id}) / 컨사이니 ".($keepCons ? "'{$keepCons->name}'(id={$keepCons->id})" : '❌없음')."\n\n";

echo "── 컨사이니 R.SH L.L.C 류 (trashed 포함)\n";
foreach (Consignee::withTrashed()->get()->filter(fn ($c) => $norm($c->name) === 'RSHLLC') as $c) {
    echo sprintf("  id=%-3d name=%-12s buyer_id=%s deleted_at=%s\n", $c->id, $c->name, $c->buyer_id, $c->deleted_at ?? '(활성)');
}
echo "\n";

$plan = [];
foreach ($PLATES as $plate) {
    $bare = str_replace(' ', '', $plate);
    $v = Vehicle::get()->first(fn ($x) => str_replace(' ', '', (string) $x->vehicle_number) === $bare);
    if (! $v) {
        echo "  ❌ NOT FOUND {$plate}\n";

        continue;
    }

    $curBuyer = Buyer::withTrashed()->find($v->bl_buyer_id);
    $curCons = Consignee::withTrashed()->find($v->bl_consignee_id);
    $buyerOk = $v->bl_buyer_id === $keep->id;
    $consOk = $keepCons && $v->bl_consignee_id === $keepCons->id;

    echo "  {$v->vehicle_number}: bl_buyer=".($curBuyer ? "{$curBuyer->name}".($curBuyer->trashed() ? '(삭제됨)' : '') : 'NULL')
        .' → '.($buyerOk ? 'OK' : 'R.S,H 로 수정')
        .' | bl_consignee='.($curCons ? "{$curCons->name}".($curCons->trashed() ? '(삭제됨)' : '') : 'NULL')
        .' → '.($consOk ? 'OK' : ($keepCons ? "{$keepCons->name} 로 수정" : '❌keeper컨사이니없음'))."\n";

    $plan[$v->id] = ['buyer' => ! $buyerOk, 'cons' => ! $consOk && $keepCons];
}

if (! $APPLY) {
    exit("\ndry-run. --apply 로 적용.\n");
}

DB::transaction(function () use ($plan, $keep, $keepCons, $norm) {
    foreach ($plan as $vid => $p) {
        $upd = [];
        if ($p['buyer']) {
            $upd['bl_buyer_id'] = $keep->id;
        }
        if ($p['cons']) {
            $upd['bl_consignee_id'] = $keepCons->id;
        }
        if ($upd) {
            DB::table('vehicles')->where('id', $vid)->update($upd);
        }
    }

    // 고아 dup 컨사이니 정리 (R.SH L.L.C 류, keeper 밖, 참조 0) → 소프트삭제 (로컬과 동일)
    foreach (Consignee::where('buyer_id', '!=', $keep->id)->get()
        ->filter(fn ($c) => $norm($c->name) === 'RSHLLC') as $c) {
        $ref = 0;
        foreach (['consignee_id', 'export_consignee_id', 'bl_consignee_id'] as $col) {
            $ref += DB::table('vehicles')->where($col, $c->id)->count();
        }
        if ($ref === 0) {
            $c->delete();
            echo "  컨사이니 '{$c->name}'(#{$c->id}) 고아 → 소프트삭제\n";
        }
    }
});
echo "\n✅ 적용됨. 36가2483·56주5094 → R.S,H 재연결 완료.\n";
