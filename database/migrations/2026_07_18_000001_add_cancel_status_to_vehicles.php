<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매입취소(차량취소) 상태 컬럼 (jin 2026-07-18).
 * 위약금은 기존 판매(sale_price)·채권 배관 재사용 → progress_status 오염(판매완료)만 이 마커로 분리.
 * - none            : 정상
 * - cancelled       : 매입취소(위약금 미수 추적 중)
 * - cancelled_done  : 취소완료(위약금 완납)
 * - cancelled_closed: 미수 마감(못 받고 종료 — 프리랜서면 손실 절반 담당자 부담)
 * 순수 additive(FK·CHECK 없음) — SQLite/MariaDB/MySQL8 공통 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('cancel_status', 20)->default('none')->index()->after('progress_status_cache');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_status');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['cancel_status', 'cancelled_at']);
        });
    }
};
