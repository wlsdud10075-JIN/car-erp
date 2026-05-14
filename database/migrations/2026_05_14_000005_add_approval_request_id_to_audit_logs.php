<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 14-4-1 — audit_logs 2-actor 링크 (회의록 QA 권고).
     *
     * 단일 user_id로는 "영업이 요청 → 관리가 승인" 흐름의 책임소재 분리 불가.
     * approval_request_id로 ApprovalRequest 참조 → requester / approver 자동 조회.
     *
     * 정책:
     * - 일반 변경: user_id = 변경자, approval_request_id = NULL
     * - 승인 흐름 변경: user_id = 승인자 (실제 commit), approval_request_id = ApprovalRequest.id
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('approval_request_id')->nullable()
                ->after('user_id')
                ->constrained('approval_requests')->nullOnDelete();

            $table->index(['approval_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['approval_request_id', 'created_at']);
            $table->dropForeign(['approval_request_id']);
            $table->dropColumn('approval_request_id');
        });
    }
};
