<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #2-2+2-4 (2026-05-20) — salesmen.type enum.
 *
 * 회의록 docs/meetings/2026-05-19-group-revenue-progress-redesign.md 새회의 5번:
 *   '영업 1/2 분리 (헤이맨/프리랜서) — Salesman 모델 확장'.
 *
 * 2 종 type:
 *   - employee:  사내직원 (default). 정산 시 settlement_type='per_unit' (건당)
 *   - freelance: 프리랜서. 정산 시 settlement_type='ratio' (비율)
 *
 * 자동 분기 — Vehicle::saved 훅에서 거래완료 진입 시 vehicle.salesman.type 보고 settlement_type 결정.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance'])
                ->default('employee')
                ->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
