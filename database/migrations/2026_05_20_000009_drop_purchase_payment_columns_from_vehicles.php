<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 22-C-E Mig C (2026-05-20) — vehicles 매입 2컬럼 DROP.
 *
 * Mig B (22-C-D) 에서 데이터를 purchase_balance_payments rows 로 이동 + 2컬럼 0 clear 완료.
 * 본 마이그 이후 매입 분자 단일 소스: purchase_balance_payments.confirmed_at IS NOT NULL.
 *
 * down: 컬럼 schema 복원만 (데이터 복원은 Mig B 의 down 에 의존).
 *       rollback 시 22-C-E Mig C → 22-C-D Mig B → Mig A 순으로 되돌아가야 함.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'down_payment',
                'selling_fee_payment',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('down_payment')->default(0)->after('cost_extra2');
            $table->unsignedBigInteger('selling_fee_payment')->default(0)->after('down_payment');
        });
    }
};
