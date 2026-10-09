<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 v3 (jin 2026-10-08) — 결재선 · 변경 이력 · 박제.
 *
 * - settlement_payout_batch_steps : 상신할 때 고른 결재선(부장 → 전무 → 대표). **행이 있으면 steps 모드**, 없으면 종전 사다리
 *   (current_level). 그 직급에 사람이 없으면 행을 안 만든다(= 건너뜀). 3사 동시 배포라 직급을 아무도 안 넣은 회사는
 *   행이 0개 → 종전과 똑같이 돈다.
 * - settlement_payout_batch_changes : 상신 뒤 바뀐 것(인센티브·조정·급여) — 카드의 노란 표시와 결재 내역의 출처.
 * - settlement_payout_batches.breakdown_snapshot : 최종 승인·반려 시점의 사람별 카드 박제. **반려돼도 내용이 그대로 보인다**
 *   (jin — 반려하면 정산이 풀려 카드가 비던 혼동). current_step = 지금 결재 차례인 step seq(steps 모드).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_payout_batch_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('settlement_payout_batches')->cascadeOnDelete();
            $t->unsignedTinyInteger('seq');                          // 1..n
            $t->string('title', 10);                                 // 부장 / 전무 / 대표 (User::APPROVAL_TITLES)
            $t->foreignId('approver_user_id')->constrained('users');
            $t->string('status', 12)->default('pending');            // pending / approved / rejected
            $t->timestamp('acted_at')->nullable();
            $t->text('note')->nullable();                            // 한 줄 의견(그룹웨어 전자결재식)
            $t->timestamps();
            $t->unique(['batch_id', 'seq']);
        });

        Schema::create('settlement_payout_batch_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('settlement_payout_batches')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->foreignId('salesman_id')->nullable()->constrained('salesmen');
            $t->string('field', 32);                                 // incentive / adjustment / payroll
            $t->bigInteger('before')->nullable();
            $t->bigInteger('after')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['batch_id', 'salesman_id']);
        });

        Schema::table('settlement_payout_batches', function (Blueprint $t) {
            $t->unsignedTinyInteger('current_step')->nullable()->after('current_level');
            $t->json('breakdown_snapshot')->nullable()->after('reject_reason');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $t) {
            $t->dropColumn(['current_step', 'breakdown_snapshot']);
        });
        Schema::dropIfExists('settlement_payout_batch_changes');
        Schema::dropIfExists('settlement_payout_batch_steps');
    }
};
