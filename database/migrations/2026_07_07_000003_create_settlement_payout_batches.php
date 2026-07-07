<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 (jin 2026-07-07) — 월배치 정산지급 승인 사다리.
     *
     * [관리]/업무관리자 제출 → (제출자보다 위 계단 순서대로 서명) → 대표(admin) 최종 → 배치 전 정산 일괄 paid.
     * 정산 지급(1차)만 대상. 2차+환차는 carryover 이월(별개, 무변경).
     */
    public function up(): void
    {
        Schema::create('settlement_payout_batches', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7);                                  // 귀속월 'YYYY-MM'
            $t->foreignId('submitter_id')->constrained('users');
            $t->unsignedTinyInteger('submitter_rank');               // 제출자 rank snapshot
            $t->unsignedTinyInteger('current_level');                // 다음 필요 승인 rank (제출자+1 ~ 3)
            $t->string('status', 20)->default('pending');            // pending/approved/rejected/cancelled
            $t->unsignedBigInteger('total_payout')->default(0);      // 실지급 합 snapshot(표시용)
            $t->unsignedInteger('settlement_count')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->timestamps();
            $t->index(['status', 'month']);
        });

        Schema::create('settlement_payout_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('settlement_payout_batches')->cascadeOnDelete();
            $t->foreignId('approver_id')->constrained('users');
            $t->unsignedTinyInteger('approver_rank');
            $t->string('action', 12);                                // approved / rejected
            $t->text('note')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::table('settlements', function (Blueprint $t) {
            $t->foreignId('payout_batch_id')->nullable()->after('settlement_status')
                ->constrained('settlement_payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payout_batch_id');
        });
        Schema::dropIfExists('settlement_payout_approvals');
        Schema::dropIfExists('settlement_payout_batches');
    }
};
