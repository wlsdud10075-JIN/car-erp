<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ➕ 지급추가 (jin 2026-11-06) — 지급보류 장부(payout_holds)에 종류 열을 더해 한 장부로 쓴다.
 *   kind = hold(보류, 기존 줄 전부) / extra(추가 — 대표가 따로 정해 기안자가 기입, 지급되는 달의 회사이익에서 차감).
 * 문자열(enum 아님 — §8 #36). 기존 줄은 기본값 hold 로 그대로.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_holds', function (Blueprint $t) {
            $t->string('kind', 8)->default('hold')->after('payout_batch_id');
            $t->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payout_holds', function (Blueprint $t) {
            $t->dropIndex(['kind', 'status']);
            $t->dropColumn('kind');
        });
    }
};
