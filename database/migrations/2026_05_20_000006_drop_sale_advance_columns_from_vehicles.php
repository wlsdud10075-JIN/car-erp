<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 22-A Mig C (2026-05-20) — vehicles 입금 4컬럼 DROP.
 *
 * Mig B (22-A-1) 에서 데이터를 final_payments rows 로 이동 + 4컬럼 0 clear 완료.
 * 본 마이그 이후 분자 단일 소스: final_payments.confirmed_at IS NOT NULL.
 *
 * down: 컬럼 schema 복원만 (데이터 복원은 Mig B 의 down 에 의존).
 *       rollback 시 22-A Mig C → Mig B → Mig A 순으로 되돌아가야 함.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_down_payment',
                'interim_payment',
                'advance_payment1',
                'advance_payment2',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('deposit_down_payment', 15, 2)->default(0)->after('sale_other_costs');
            $table->decimal('interim_payment', 15, 2)->default(0)->after('deposit_down_payment');
            $table->decimal('advance_payment1', 15, 2)->default(0)->after('interim_payment');
            $table->decimal('advance_payment2', 15, 2)->default(0)->after('advance_payment1');
        });
    }
};
