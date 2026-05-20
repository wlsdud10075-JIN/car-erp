<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 22-C-D Mig A (2026-05-20) — purchase_balance_payments.type enum 추가.
 *
 * 회의록 docs/meetings/2026-05-19-purchase-flow-redesign.md 큐 22-C type enum.
 *
 * 3종 type:
 *   - down:        매입 계약금 (기존 vehicles.down_payment)
 *   - selling_fee: 매도비 지급 (기존 vehicles.selling_fee_payment)
 *   - balance:     매입 잔금 (기존 PBP 본래 의미, default)
 *
 * 기존 PBP 행은 default 'balance'로 자동 설정 → 의미 보존.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->enum('type', ['down', 'selling_fee', 'balance'])
                ->default('balance')
                ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
