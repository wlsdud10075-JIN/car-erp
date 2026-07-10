<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 국내 딜러 말소등록증 알림톡 전달용 번호. 사용자가 직접 입력·저장(바이어 번호 아님 — jin 2026-07-10).
            $table->string('deregistration_notice_phone', 20)->nullable()->after('deregistration_document');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('deregistration_notice_phone');
        });
    }
};
