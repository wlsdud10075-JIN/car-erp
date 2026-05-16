<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 19-L — void 흐름 재무 거부 (큐 19-K 정방향 거부의 void 대응).
     *
     * void 거부 시 transfer.status는 executed로 복귀 (이체 자체는 살아있음).
     * 영업이 만든 void 요청만 무산. 영업이 다시 void 요청 가능.
     *
     * 신규 컬럼은 거부 메타 추적용 — 새 transfer.status는 만들지 않음 (executed 재사용).
     * void 시도 이력은 ApprovalRequest(void).status='approved' + 본 컬럼들로 추적.
     *
     * 사용자 결정 (2026-05-17): 옵션 1 — executed 복귀.
     *   대안 (voided_finance_rejected 새 상태) 대비 UI 분기 단순, 시스템 의미 동일.
     */
    public function up(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->foreignId('void_finance_rejected_by_user_id')->nullable()
                ->after('finance_reject_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('void_finance_rejected_at')->nullable()->after('void_finance_rejected_by_user_id');
            $table->text('void_finance_reject_reason')->nullable()->after('void_finance_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->dropForeign(['void_finance_rejected_by_user_id']);
            $table->dropColumn(['void_finance_rejected_by_user_id', 'void_finance_rejected_at', 'void_finance_reject_reason']);
        });
    }
};
