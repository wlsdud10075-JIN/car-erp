<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 14-4-4 — inter_buyer_overlap 승인 사용 추적.
     *
     * "1 승인 = 1 차량 등록" 원칙. approved 상태 ApprovalRequest 중
     * used_at NULL인 것만 활성 (영업이 신규 차량 등록 가능). 등록 완료 시 now() 마킹.
     *
     * 다른 action_type(settlement_pay, sensitive_action)은 used_at 미사용 — execute() 1회로 완결.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('decided_at');
            $table->index(['action_type', 'target_id', 'status', 'used_at'], 'approval_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropIndex('approval_active_idx');
            $table->dropColumn('used_at');
        });
    }
};
