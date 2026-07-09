<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-09 (jin) — 재고관리 출고일. 재고 제외 트리거를 진행상태 → 출고일로 전환.
 *   출고일 찍히면 재고 제외 / 안 찍히면 진행상태 무관 재고 잔존(선적됐는데 재고면 사람이 발견·처리).
 *   입고일 = 매입 완납일(computed, Vehicle::warehouse_in_date). 미완납 = 입고 전 = 재고 미표시.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('warehouse_out_date')->nullable()->after('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('warehouse_out_date');
        });
    }
};
