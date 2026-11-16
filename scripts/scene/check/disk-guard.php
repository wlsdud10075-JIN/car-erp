<?php

// 씬 점검 — 차량 서류 디스크 확인(읽기만). 제안 위치 = scripts/scene/check/disk-guard.php (dev 전용).
// bl-issue · deregistration-notice 는 파일을 올린다 — 드라이버가 s3 면 매주 실제 버킷에 파일이 쌓이므로 업로드 단계를 건너뛴다.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    echo json_encode(['error' => 'local only']);
    exit(1);
}
$disk = (string) config('filesystems.vehicle_docs_disk');
echo json_encode([
    'disk' => $disk,
    'driver' => (string) config("filesystems.disks.{$disk}.driver"),
]);
