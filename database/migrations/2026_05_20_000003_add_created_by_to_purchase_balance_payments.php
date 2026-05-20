<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 22-C-light (사용자 요청 2026-05-20) — 매입 자동 PBP Draft 생성 actor 추적.
 *
 * Spec-E 해소조건 — Vehicle::saved 훅에서 자동 생성된 PBP의 created_by_user_id 기록.
 * 시드·artisan 등 시스템 생성은 NULL로 표기 (auth()->id() falsy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('finance_note')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
