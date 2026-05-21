<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21 — users.type enum (정산 분류).
 *
 * 사용자 결정 2026-05-21: Salesman.type 단일 관리 → User.type 단일 관리로 이동.
 * 영업담당자 신규 등록을 /admin/users 한 페이지에서 끝내기 위함.
 * 운영 원칙: 영업담당자는 반드시 로그인 계정 보유 → User-Salesman 1:1 강제.
 *
 * 2 종 type:
 *   - employee:  사내직원 → 정산 settlement_type='per_unit' (건당)
 *   - freelance: 프리랜서 → 정산 settlement_type='ratio' (비율)
 *
 * nullable + default null:
 *   - 영업 role 이 아닌 user 는 type 없음 (관리/재무/수출통관/admin/super)
 *   - role=영업 일 때만 /admin/users 폼에서 required_if 강제 (Volt 단)
 *
 * Salesman.type 컬럼은 유지(미러링) — Vehicle::saved 훅 변경 최소화 위해.
 * /admin/users 저장 시 연결된 Salesman 의 type 도 동기화.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance'])
                ->nullable()
                ->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
