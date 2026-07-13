<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 채권관리 입금(deposit) 수정 시 환율도 편집 가능하게 (Phase 3, 2026-07-13).
 *   syncFinalPayment 이 링크된 final_payment 에 exchange_rate + amount_krw 를 미러링.
 * 3-DB 안전: nullable decimal + FK 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->decimal('exchange_rate', 15, 4)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
