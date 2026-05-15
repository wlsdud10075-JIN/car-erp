<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 19-A — 차량 간 자금 이체 (회의록 v5 §13).
     *
     * 시나리오: 1번 차 50% 받음 → 관리 승인 → 받은 금액 × 0.5 한도 내에서 2번 차로 이체.
     * 양 차량 buyer_id 동일 필수. 실행은 source에 음수 final_payment + target에 양수 final_payment
     * 트랜잭션 (final_payments.transfer_id로 짝짓기 — 별도 마이그레이션).
     *
     * status 생명주기:
     *   pending  — 영업이 요청, ApprovalRequest 대기
     *   approved — 관리 승인 (실행 직전 짧은 상태, 보통 곧 executed로 전환)
     *   executed — 트랜잭션 완료. append-only — 이후 수정 불가
     *   voided   — 별도 voided 거래로 취소 (반대 부호 final_payment 추가)
     */
    public function up(): void
    {
        Schema::create('inter_vehicle_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('target_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();   // 정합 검증용 — 양 차량 동일

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);  // KRW / USD / JPY / EUR / GBP / CNY

            $table->foreignId('approval_request_id')->nullable()
                ->constrained('approval_requests')->nullOnDelete();

            $table->string('status', 20)->default('pending');  // pending / approved / executed / voided

            $table->timestamp('executed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['source_vehicle_id', 'status']);
            $table->index(['target_vehicle_id', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_vehicle_transfers');
    }
};
