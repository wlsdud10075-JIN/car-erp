<?php
// 로컬 전용 — 바이어 현금 원장 켜기/끄기(캡처용). 사용: php cashtoggle.php 0|1
require 'C:/xampp/htdocs/car-erp/vendor/autoload.php';
$app = require 'C:/xampp/htdocs/car-erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local')) { exit("local only\n"); }
$key = 'buyer_cash_enabled_'.\App\Models\Setting::companyTemplateSet();
$row = \App\Models\Setting::where('key', $key)->first();
$row->value = $argv[1] ?? '1';
$row->save();
\Illuminate\Support\Facades\Cache::flush();
echo $key.'='.\App\Models\Setting::where('key', $key)->value('value')."\n";
