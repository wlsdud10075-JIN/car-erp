<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 19-K — 재무 정방향 거부 흐름 (부록 A 회귀 발견 항목, 2026-05-17).
     *
     * 큐 19-F 5상태 머신은 재무 "확정" 경로만 정의 — 재무가 거부할 UI/Service 부재.
     * 재무가 통장 잔액 부족·송금 실패·입금자 불일치 등 사유로 거부할 수 있어야 함.
     *
     * 신규 6번째 상태: finance_rejected (approved_awaiting_finance에서만 진입)
     *   - final_payment 미생성 (ledger 영향 0)
     *   - 영업이 사유 확인 후 새 transfer 요청 가능 (한도 그대로)
     *   - stale 처리: 큐 19-G 가드(미처리 차단)에서 제외 (status 조건 자체로 자동 제외)
     *
     * void 흐름의 재무 거부는 별도 큐 19-L로 분리.
     */
    public function up(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->foreignId('finance_rejected_by_user_id')->nullable()
                ->after('finance_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('finance_rejected_at')->nullable()->after('finance_rejected_by_user_id');
            $table->text('finance_reject_reason')->nullable()->after('finance_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->dropForeign(['finance_rejected_by_user_id']);
            $table->dropColumn(['finance_rejected_by_user_id', 'finance_rejected_at', 'finance_reject_reason']);
        });
    }
};
