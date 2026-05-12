<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 2.5번 C6 — vehicle_number unique + soft-delete 충돌 fix.
 *
 * 기존 unique(vehicle_number)는 soft-delete row까지 검사해서
 * 동일 차량번호 신규 등록 시 1062 IntegrityError(500) 발생.
 *
 * 해결: DB-level unique 제거 → application-level
 * `Rule::unique(...)->whereNull('deleted_at')` 검증으로 대체.
 * (MySQL/MariaDB는 NULL을 distinct로 취급해 (col, deleted_at) 복합 unique가
 * 활성 row 간 중복을 차단하지 못함. partial index는 MySQL 미지원. 1인 운영
 * 컨텍스트라 application-level race window는 허용 범위.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['vehicle_number']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unique('vehicle_number');
        });
    }
};
