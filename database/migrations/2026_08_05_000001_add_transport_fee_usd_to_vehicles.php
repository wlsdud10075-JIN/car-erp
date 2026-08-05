<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운임비(USD) 기록칸 (jin 2026-08-05) — 판매탭 총판매가 옆.
 *   운임비를 달러로 적어두기 위한 **순수 메모**. 통화 무관 항상 USD.
 *   ⚠️ 어떤 계산에도 미포함 — 총판매가(sale_total_amount)·미수·정산·매출 전부 무관.
 *   판매통화 기준 운임비는 기존 transport_fee(미수율 분모에만 반영, SKILLS §13) 로 별개다.
 * 3-DB(SQLite/MariaDB/MySQL8) 안전 — nullable, MySQL 8 INSTANT DDL 무중단.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->bigInteger('transport_fee_usd')->nullable()->after('parts_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('transport_fee_usd');
        });
    }
};
