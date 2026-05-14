<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 큐 14-1 — '전체' role 삭제.
     *
     * 회의록 v5.1 §9-1: '전체' 구분 모호 → 영업/통관/정산/관리 4 role로 명확화.
     * '관리' = 서브관리자 (승인 권한자).
     *
     * 데이터 처리:
     * - permission=user + role=전체 → role=관리로 변환 (서브관리자 의도 추정)
     * - permission=admin/super는 role 무관 → role='관리'로 통일 (잘못된 값 정리)
     *
     * 안전망: storage/backups/db/pre-q14-role-cleanup.sql.
     * users.role 컬럼은 string(20) 그대로 (enum 제약 없음).
     */
    public function up(): void
    {
        // DB::table 사용 → 모델 이벤트 미발동, audit_logs 비스팸.
        DB::table('users')
            ->where('role', '전체')
            ->update(['role' => '관리']);
    }

    public function down(): void
    {
        // 롤백: '관리' user 일부를 다시 '전체'로 복원할 방법은 없음 (구분 불가).
        // 데이터 보존을 위해 down은 no-op.
    }
};
