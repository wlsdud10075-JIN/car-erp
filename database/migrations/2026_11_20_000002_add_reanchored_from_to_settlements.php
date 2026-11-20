<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📅 보류가 풀린 정산의 귀속월 자동 이동 (jin 2026-11-20 (가)) — 옮기기 전 귀속월.
 * 미수로 지급보류된 정산이 그 달 월정산 마감 뒤에 완납되면 열린 달로 옮기고, 정산관리에 「○월에서 이동」 뱃지를 띄운다.
 * NULL = 옮긴 적 없음(기존 행 전부). 표시 전용 — 계산에 쓰지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->date('reanchored_from')->nullable()->after('attributed_month');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('reanchored_from');
        });
    }
};
