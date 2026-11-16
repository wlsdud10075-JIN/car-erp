<?php

// 씬 점검 시작 전 안전장치 — 지금 쓰는 회사 설정(Setting::companyTemplateSet)의 알림톡 마스터 스위치를 JSON 으로. 읽기만.
// 로컬 BizM 은 실발송 계정이라 켜져 있으면 러너가 멈춘다. ALIMTALK_TEST_PHONE 이 있으면 수신자가 그 번호로 고정된다(BizmAlimtalkService).
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    echo json_encode(['error' => 'local only']);
    exit(1);
}
echo json_encode([
    'set' => App\Models\Setting::companyTemplateSet(),
    'enabled' => App\Support\AlimtalkConfig::active()->enabled,
    'test_phone' => (string) config('services.alimtalk.test_phone') !== '',
]);
