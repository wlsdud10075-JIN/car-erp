<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어별 정산 비율 (jin 2026-10-21) — 바이어 드로어 「정산 방식」: 기본 / 5:5 / 7:3.
 *
 * buyers.settlement_ratio_pct  NULL = 기본(담당자 유형을 따름) · 50 = 5:5 · 30 = 7:3(우리 영업 30%).
 *   고르면 그 바이어 차는 담당자가 사내직원이든 프리랜서든 **총마진 × 비율만** 받는다(차등 10만·20만 없음, 음수면 음수).
 *   서류비 5만은 프리랜서만 뺀다(사내직원은 종전대로 없음). 바이어 담당자가 바뀌면(승계 등) 기본으로 돌아간다.
 * settlements.ratio_source     'buyer' = 위 칸에서 온 비율. **정산이 만들어질 때 박제**한다 —
 *   바이어 칸을 나중에 바꿔도 이미 생긴 정산은 그대로(jin «정한 뒤부터»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->unsignedTinyInteger('settlement_ratio_pct')->nullable()->after('is_inherited');
        });
        Schema::table('settlements', function (Blueprint $table) {
            $table->string('ratio_source', 10)->nullable()->after('settlement_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('ratio_source');
        });
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('settlement_ratio_pct');
        });
    }
};
