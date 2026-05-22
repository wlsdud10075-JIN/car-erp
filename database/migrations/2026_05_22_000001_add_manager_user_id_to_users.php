<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #11 (2026-05-22) — [관리]별 담당 영업담당자 배정 (1:N).
 *
 * 사용자 결정 2026-05-21:
 *   "[관리]별로 담당하는 영업담당자를 지정. [관리] 로그인 시 배정된 담당자들의
 *    내용만 볼 수 있게 솔팅. [관리]당 담당자는 보통 10~20명 내외."
 *
 * 모델:
 *   - 1관리 : N영업 (가장 단순). users.manager_user_id 가 [관리] user.id 를 가리킴.
 *   - 영업 user.manager_user_id 가 NULL 이면 미배정 — [관리] 솔팅 결과에 안 잡힘.
 *   - [관리] 본인은 manager_user_id NULL (또는 다른 [관리] 의 부하일 수도 — 사용은 X)
 *
 * 의미 분기:
 *   - admin/super: 본 컬럼 무시. 전체 노출.
 *   - 영업: 8711e7d 본인 한정 가드 그대로. 본 컬럼은 [관리] 솔팅 산정 input.
 *   - [관리]: subordinates() 로 본인 담당 영업 조회 → 차량/바이어/영업 select 필터링.
 *
 * 안전:
 *   - nullable + nullOnDelete: [관리] 사용자 삭제 시 영업의 manager_user_id 자동 NULL.
 *   - 신규 컬럼이라 기존 row 영향 0.
 *
 * 별도 컬럼:
 *   - vehicles.receivable_manager_id 는 채권 담당자 배정 (의미 별개, 충돌 없음).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_user_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn('manager_user_id');
        });
    }
};
