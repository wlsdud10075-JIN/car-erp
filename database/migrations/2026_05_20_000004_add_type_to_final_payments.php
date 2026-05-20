<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 22-A Mig A (2026-05-20) — final_payments.type enum 추가.
 *
 * 회의록 docs/meetings/2026-05-19-workflow-revision-2week-deployment.md Day 6~10.
 *
 * 5종 type:
 *   - deposit_down: 계약금 (기존 vehicles.deposit_down_payment)
 *   - interim:      중도금 (기존 vehicles.interim_payment)
 *   - advance_1:    선수금 1 (기존 vehicles.advance_payment1)
 *   - advance_2:    선수금 2 (기존 vehicles.advance_payment2)
 *   - balance:      잔금 (기존 final_payments 본래 의미, default)
 *
 * 기존 FP 행은 default 'balance'로 자동 설정 → 의미 보존.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->enum('type', ['deposit_down', 'interim', 'advance_1', 'advance_2', 'balance'])
                ->default('balance')
                ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
