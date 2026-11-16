<?php

/**
 * 씬 점검 — 차량 편집 잠금 풀기 (로컬 전용). cap/unlock.php 의 차량번호판.
 * 입력 = stdin JSON {"plates": ["00가1141", ...]} — 차량번호로 찾는다(고정 id 금지).
 * 하는 일 = Cache::forget('vehicle-edit-lock:{id}') 뿐. DB 는 안 건드린다.
 */
$root = getenv('CAR_ERP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('local')) {
    fwrite(STDERR, "local only\n");
    exit(1);
}
// argv(차량번호 여러 개)가 있으면 그걸 쓴다 — stdin 을 먼저 읽으면 터미널에서 멈춘다. 러너는 stdin(JSON, 한글 안전).
if (count($argv) > 1) {
    $plates = array_slice($argv, 1);
} else {
    $in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
    $plates = array_values(array_filter((array) ($in['plates'] ?? []), 'is_string'));
}
$ids = App\Models\Vehicle::whereIn('vehicle_number', $plates)->pluck('id');
foreach ($ids as $id) {
    Illuminate\Support\Facades\Cache::forget('vehicle-edit-lock:'.$id);   // 키 = vehicles/index editLockKey()
}
echo json_encode(['unlocked' => $ids->count()]);
