<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매입취소 미수마감 손실이 월배치 조정으로 이미 반영됐음을 표시 (jin 2026-07-18).
 * 수기 반영(Option A) 후 관리자가 '처리 표시'하면 손실 요약에서 제외 → 이중 청구 방지.
 * 순수 additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('cancel_loss_settled_at')->nullable()->after('cancel_shortfall_krw');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('cancel_loss_settled_at');
        });
    }
};
