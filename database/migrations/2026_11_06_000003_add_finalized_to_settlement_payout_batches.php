<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✅ 월정산 최종 확정 (jin 2026-11-06) — 대표 승인 뒤 보류·추가를 다 적고 「최종 확정」하면 그 달 장부가 잠기고
 * 엑셀이 확정본(사람별 실제 송금)으로 나간다. 상태(status)는 approved 그대로 — 도장만 찍는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $t) {
            $t->timestamp('finalized_at')->nullable()->after('decided_at');
            $t->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('finalized_by');
            $t->dropColumn('finalized_at');
        });
    }
};
