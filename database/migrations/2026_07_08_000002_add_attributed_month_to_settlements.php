<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A-3 (jin 2026-07-08) — 정산 귀속월 고정 컬럼.
     *   귀속월 = 완납(판매완료)된 날의 그 달 1일. 한 번 정해지면 이후 거래완료돼도 불변(재귀속 X).
     *   기존 앵커(confirmed_at 10일 cutoff)를 대체. NULL 이면 submitForMonth 가 COALESCE(confirmed_at,created_at) fallback.
     *   기존 정산 백필 = settlements:backfill-attributed-month (현재 귀속월 보존).
     */
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $t) {
            $t->date('attributed_month')->nullable()->after('settlement_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $t) {
            $t->dropIndex(['attributed_month']);
            $t->dropColumn('attributed_month');
        });
    }
};
