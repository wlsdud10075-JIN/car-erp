<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 B — board 매입 때 적은 USD 환율 메모 (jin 2026-10-22).
 *
 * 판매통화가 EUR 이어도 「그때 USD 환율이 얼마였나」를 판매 탭 환율 칸 위에 작게 보여 준다.
 * 🚫 어떤 계산에도 안 들어간다 — 판매통화 환율은 종전대로 `exchange_rate` 하나다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('usd_exchange_rate', 12, 4)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('usd_exchange_rate');
        });
    }
};
