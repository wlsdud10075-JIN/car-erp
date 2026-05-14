<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 14-3 — 승인 요청 큐 (회의록 §4 합의안).
     *
     * 4 액션 통합 (회의록 v5.1 §9-2):
     *   1. inter_buyer_overlap        — G2 같은 바이어 미수 + 신규 거래
     *   2. settlement_pay             — 정산 confirmed → paid 전환
     *   3. sensitive_action           — 차량 폐기 / RRN 수정 / B/L 수동 발행
     *   4. unpaid_export_override     — 50% 룰 예외 (선수금 미달 통관 진입)
     *
     * 보존: 무기한 (audit_logs와 동일 정책).
     * 큐 11-3 db:backup으로 자동 백업 포함.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            // Polymorphic 대상 (Vehicle / Settlement / 기타)
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            $table->string('action_type', 40);   // inter_buyer_overlap / settlement_pay / sensitive_action / unpaid_export_override
            $table->json('payload')->nullable(); // 액션별 컨텍스트 (전후 값, 사유 등)
            $table->string('status', 20)->default('pending');  // pending / approved / rejected / cancelled
            $table->text('reason')->nullable();  // 요청 사유 (요청자)
            $table->text('decision_note')->nullable();  // 승인/거부 사유 (승인자)
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['requester_id', 'status']);
            $table->index(['action_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
