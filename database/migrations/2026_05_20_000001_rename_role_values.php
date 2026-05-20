<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-05-19 풀회의 안건 I — role 명칭 변경 (권한 확장은 NO-GO).
 *  - '정산' → '재무'   (SoD: 관리 ≠ 재무 — 의사결정 ≠ 실물 자금 처리)
 *  - '통관' → '수출통관' (수출 도메인 전용 명시)
 *
 * 컬럼 타입은 varchar(10) 그대로 (수출통관 4자 안전). 데이터 값만 갱신.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', '정산')->update(['role' => '재무']);
        DB::table('users')->where('role', '통관')->update(['role' => '수출통관']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', '재무')->update(['role' => '정산']);
        DB::table('users')->where('role', '수출통관')->update(['role' => '통관']);
    }
};
