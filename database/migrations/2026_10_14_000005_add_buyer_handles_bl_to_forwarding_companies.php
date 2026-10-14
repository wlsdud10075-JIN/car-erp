<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 「FOB포워더」 — 바이어가 직접 고른 포워딩사라 B/L 을 바이어가 발급한다 (jin 2026-10-14).
 *
 * 인코텀즈 FOB + 이 플래그가 켜진 포워딩사 + 판매대금 완납 ⇒ 진행상태 「거래완료」(Vehicle::progress_status).
 * ERP 에 B/L 파일이 올 일이 없어서 종전 규칙(거래완료 = B/L 발급)으로는 영영 판매완료에 머물렀다.
 *
 * 🔑 **이름이 아니라 플래그**로 판정한다(§8 #103·#104 — 이름으로 거르면 개명 때 조용히 깨진다). 다만 운영 3사에
 *    jin 이 이미 「FOB포워더」 라는 포워딩사를 만들어 두었으므로(실측 10-14: ssancarerp #12 · heymanerp #5, 차량 0대)
 *    그 이름의 행만 이 마이그에서 한 번 켠다. 이후는 포워딩사 관리 화면의 체크박스.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forwarding_companies', function (Blueprint $table) {
            $table->boolean('buyer_handles_bl')->default(false)->after('tracking_url_template');
        });
        DB::table('forwarding_companies')->where('name', 'FOB포워더')->update(['buyer_handles_bl' => true]);
    }

    public function down(): void
    {
        Schema::table('forwarding_companies', function (Blueprint $table) {
            $table->dropColumn('buyer_handles_bl');
        });
    }
};
