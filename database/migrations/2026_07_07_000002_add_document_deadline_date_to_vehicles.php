<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * item 6 (jin 2026-07-07) — 선적 서류마감 날짜.
     *
     * 선적(반입) 탭 컨테이너번호 옆에 입력. 이 날짜 5일 전부터 '관리' 대상 알람(document_deadline).
     * 기존 date 컬럼(shipping_date·eta_date·bl_issue_date 등)과 성격 다름 — 서류 제출 마감일.
     * 단순 nullable date (CHECK/FK 없음) → MySQL8/MariaDB/SQLite 공통 안전.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('document_deadline_date')->nullable()->after('bl_issue_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('document_deadline_date');
        });
    }
};
