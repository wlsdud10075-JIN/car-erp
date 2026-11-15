<?php
// 로컬 전용 — 씬 캡처 동안만 매입대금 입금완료(v2) 버튼이 보이게 켜고, 끝나면 원래대로 되돌린다.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$on = ($argv[1] ?? '') === 'on';
DB::table('settings')->where('key', 'alimtalk_enabled_heyman')->update(['value' => $on ? '1' : '0']);
DB::table('settings')->where('key', 'alimtalk_tmpl_erp_purchase_paid_v2_heyman')->update(['value' => $on ? 'SCENE-CAPTURE' : '']);
DB::table('settings')->where('key', 'alimtalk_toggle_erp_purchase_paid_v2_heyman')->update(['value' => $on ? '1' : '0']);   // 원래 값 0
DB::table('settings')->where('key', 'alimtalk_tmpl_erp_purchase_paid_v3_heyman')->update(['value' => $on ? 'SCENE-CAPTURE' : '']);   // 원래 값 ''
DB::table('settings')->where('key', 'alimtalk_toggle_erp_purchase_paid_v3_heyman')->update(['value' => $on ? '1' : '0']);   // 원래 값 0
echo 'v3=', var_export(App\Support\AlimtalkConfig::active()->canSend('erp_purchase_paid_v3'), true), ' ';
$c = App\Support\AlimtalkConfig::active();
echo 'enabled=', DB::table('settings')->where('key', 'alimtalk_enabled_heyman')->value('value'),
    ' v2tmpl=', DB::table('settings')->where('key', 'alimtalk_tmpl_erp_purchase_paid_v2_heyman')->value('value'),
    ' canSend(v2)=', var_export($c->canSend('erp_purchase_paid_v2'), true), "\n";
