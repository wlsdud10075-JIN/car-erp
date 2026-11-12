<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vehicles.imported_at — 「엑셀 적재(vehicles:import)로 들어온 차」 표식 (jin 2026-11-12).
 *
 * 배경: 관제센터 「끊긴 참조」가 ssancarerp 222대를 세는데 전부 08-28·08-29 적재 차(적재가 만든 쓰레기 컨사이니
 * 「0.5」·「103」을 그날 지움)였다. 적재 차는 알고 있는 것이라 판정에서 빼야 진짜 신호만 남는다 — 그런데 차량엔
 * 적재 표식이 없었다. 앞으로는 vehicles:import 가 신규 생성 때 찍고, 이미 들어온 차는 vehicles:mark-imported 로 찍는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('imported_at')->nullable()->after('progress_status_rule_version')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['imported_at']);
            $table->dropColumn('imported_at');
        });
    }
};
