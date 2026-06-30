<?php

/**
 * 중복 바이어 RSH → R.S,H 머지 (2026-06-12, jin 확정).
 *   php scripts/merge-rsh.php           # dry-run (계획만)
 *   php scripts/merge-rsh.php --apply   # 적용
 *
 * 이름으로 해석(ID 독립) → 로컬·서버 공용. 멱등(RSH 없으면 이미 처리).
 * 단순 삭제 금지: RSH 는 bl_buyer 차량·컨사이니 참조 보유 → R.S,H 로 재지정 후 소프트삭제.
 * 컨사이니는 정규화 이름(대문자·공백/점 제거) 일치 시 keeper 컨사이니로 합치고 dup 삭제.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Buyer;
use App\Models\Consignee;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$APPLY = in_array('--apply', $argv, true);
$norm = fn (string $s) => strtoupper(preg_replace('/[\s.,]/', '', $s));

$keep = Buyer::where('name', 'R.S,H')->first();
$dup = Buyer::where('name', 'RSH')->first();

echo $APPLY ? "════ APPLY ════\n" : "════ DRY-RUN (--apply 로 적용) ════\n";
if (! $keep) {
    exit("❌ keeper 'R.S,H' 없음 — 중단\n");
}
if (! $dup) {
    exit("✅ 'RSH' 없음 — 이미 처리됨(skip)\n");
}
echo "keep=R.S,H(id={$keep->id})  dup=RSH(id={$dup->id})\n\n";

// 1) 컨사이니 매핑 (dup 컨사이니 → keeper 의 동일이름 컨사이니)
$keepCons = Consignee::where('buyer_id', $keep->id)->get();
$consActions = [];   // ['dupId'=>, 'target'=>, 'mode'=>'merge'|'move', 'name'=>]
foreach (Consignee::where('buyer_id', $dup->id)->get() as $dc) {
    $match = $keepCons->first(fn ($kc) => $norm($kc->name) === $norm($dc->name));
    if ($match) {
        $consActions[] = ['dupId' => $dc->id, 'target' => $match->id, 'mode' => 'merge', 'name' => $dc->name, 'targetName' => $match->name];
    } else {
        $consActions[] = ['dupId' => $dc->id, 'target' => null, 'mode' => 'move', 'name' => $dc->name];
    }
}

foreach ($consActions as $a) {
    if ($a['mode'] === 'merge') {
        $cnt = 0;
        foreach (['consignee_id', 'export_consignee_id', 'bl_consignee_id'] as $col) {
            $cnt += DB::table('vehicles')->where($col, $a['dupId'])->count();
        }
        echo "  컨사이니 '{$a['name']}'(#{$a['dupId']}) → '{$a['targetName']}'(#{$a['target']}) 합치고 삭제 (차량참조 {$cnt}건 재지정)\n";
    } else {
        echo "  컨사이니 '{$a['name']}'(#{$a['dupId']}) → R.S,H 로 이동(보존, 일치 keeper 없음)\n";
    }
}

// 2) 차량 buyer 참조
$buyerRefs = [];
foreach (['buyer_id', 'export_buyer_id', 'bl_buyer_id'] as $col) {
    $buyerRefs[$col] = DB::table('vehicles')->where($col, $dup->id)->pluck('vehicle_number')->all();
}
foreach ($buyerRefs as $col => $plates) {
    if ($plates) {
        echo "  차량 {$col}: ".implode(', ', $plates)." → R.S,H 재지정\n";
    }
}
echo "  → 이후 RSH(id={$dup->id}) 소프트삭제\n";

if (! $APPLY) {
    exit("\ndry-run. --apply 로 적용.\n");
}

DB::transaction(function () use ($keep, $dup, $consActions) {
    foreach ($consActions as $a) {
        if ($a['mode'] === 'merge') {
            foreach (['consignee_id', 'export_consignee_id', 'bl_consignee_id'] as $col) {
                DB::table('vehicles')->where($col, $a['dupId'])->update([$col => $a['target']]);
            }
            Consignee::whereKey($a['dupId'])->first()?->delete();   // soft
        } else {
            DB::table('consignees')->where('id', $a['dupId'])->update(['buyer_id' => $keep->id]);
        }
    }
    foreach (['buyer_id', 'export_buyer_id', 'bl_buyer_id'] as $col) {
        DB::table('vehicles')->where($col, $dup->id)->update([$col => $keep->id]);
    }
    Buyer::whereKey($dup->id)->first()?->delete();   // soft
});

echo "\n✅ 적용됨. RSH → R.S,H 머지 완료.\n";
