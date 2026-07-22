<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * karaba 매입탭 비용 확장 + 부품 기록 (Phase 2, 2026-07-22).
 *   - 비용 신규 4개(점검/성능/정비/광고) — 공통 스키마, cost_total 에 합산(karaba 12개 비용의 신규분).
 *     heyman/ssancar 는 값 0 이라 cost_total 무변화(회귀 없음). UI 노출은 karaba 만.
 *   - parts_amount(부품) — karaba 판매탭 "기록만·추적 안 함" 필드. 어떤 계산(미수·정산·매출)에도 미포함.
 * 3-DB(SQLite/MariaDB/MySQL8) 안전 — bigInteger default 0 / nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->bigInteger('cost_inspection')->default(0)->after('cost_extra2');    // 점검비
            $table->bigInteger('cost_performance')->default(0)->after('cost_inspection'); // 성능비
            $table->bigInteger('cost_repair')->default(0)->after('cost_performance');     // 정비비용
            $table->bigInteger('cost_advertising')->default(0)->after('cost_repair');     // 광고비용
            $table->bigInteger('parts_amount')->nullable()->after('sale_other_costs');    // 부품(karaba 기록용, 미추적)
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['cost_inspection', 'cost_performance', 'cost_repair', 'cost_advertising', 'parts_amount']);
        });
    }
};
