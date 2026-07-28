<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 재고 보관 위치 (jin 2026-07-28) — 재고관리에서 차량이 실제로 어디 있는지.
 *
 * 값은 Vehicle::STOCK_LOCATIONS(홈플·화물·야드) 를 화면 버튼으로 찍지만, 야적장은 늘어나므로
 * enum 이 아니라 string 으로 둔다(SKILLS §8 #4 — 값이 늘어날 항목에 enum 금지).
 * 담당자별 위치 필터에 쓰이므로 인덱스.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('stock_location', 20)->nullable()->after('warehouse_out_date');
            $table->string('stock_location_note', 255)->nullable()->after('stock_location');
            $table->index('stock_location');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['stock_location']);
            $table->dropColumn(['stock_location', 'stock_location_note']);
        });
    }
};
