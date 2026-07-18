<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매입취소 미수 마감 시 부족분(위약금 미수)을 KRW 로 동결 (jin 2026-07-18).
 * 마감 = 수금 포기 확정 → 이후 늦게 입금돼도 손실 금액이 흔들리지 않게 판매환율 KRW 로 스냅샷.
 * 프리랜서 부담 몫 = 이 값의 절반(computed). 순수 additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('cancel_shortfall_krw')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('cancel_shortfall_krw');
        });
    }
};
