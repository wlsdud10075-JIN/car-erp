<?php
// 로컬 전용 — board 가 ERP 로 보내는 서명 요청을 그대로 흉내 낸다(씬 촬영용). 비밀키는 설정에서 읽기만, 출력하지 않는다.
// 사용: php board_send.php METHOD PATH JSON_BODY [query-string]
//   예) php board_send.php POST /api/internal/board/requests '{"type":"purchase_balance",...}'
chdir('C:/xampp/htdocs/car-erp');
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { fwrite(STDERR, "local only\n"); exit(1); }
[$_, $method, $path, $body] = $argv + [null, 'GET', '/', ''];
$queryStr = $argv[4] ?? '';
parse_str($queryStr, $q); ksort($q);
$secret = (string) config('services.board_read.hmac_secret');
$ts = time(); $nonce = bin2hex(random_bytes(8));
$canonical = $method."\n".$path.'?'.http_build_query($q)."\n".$ts."\n".$body;
$sig = 'sha256='.hash_hmac('sha256', $canonical, $secret);
if ($path === '/api/internal/purchase-sync') {   // 매입 동기화는 별도 시크릿 · 본문만 서명
    $sig = 'sha256='.hash_hmac('sha256', $body, (string) config('services.purchase_sync.hmac_secret'));
}
$url = 'http://localhost:8001'.$path.($q ? '?'.http_build_query($q) : '');
$res = Illuminate\Support\Facades\Http::withHeaders(['X-Timestamp' => $ts, 'X-Nonce' => $nonce, 'X-Board-Signature' => $sig, 'Accept' => 'application/json'])
    ->withBody($body, 'application/json')->timeout(60)->send($method, $url);
echo $res->status(), ' ', $res->body(), "\n";
