<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 선적대기 허용 항로 플래그 (jin 2026-07-18, item 2).
 * 도착항(discharge) 마스터에 이 플래그가 켜지면, RORO 차량은 통관·선적 진입 C5(50%) 게이트를
 * 우회 없이 통과(선적대기 서류작업 허용). 하드코딩('알바니아 두레스') 대신 데이터로 지정.
 * 관리자 이상만 편집(항구 마스터 화면). 순수 additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->boolean('allow_shipping_wait')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropColumn('allow_shipping_wait');
        });
    }
};
