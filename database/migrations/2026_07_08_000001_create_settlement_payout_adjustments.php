<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 월배치 수동 조정란 (jin 2026-07-08) — 정산 공식 밖의 담당자별 +/− 조정.
     *   용도: 과지급 환수(−)·특별 인센티브(+)·이월 정정 등. 개별 차량 정산은 무손상, 배치 총액에만 반영.
     *   관리 입력 → 대표 배치 승인 시 함께 승인. pending 배치에서만 편집, 승인/지급 후 잠금. 감사로그 동반.
     */
    public function up(): void
    {
        Schema::create('settlement_payout_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('settlement_payout_batches')->cascadeOnDelete();
            $t->foreignId('salesman_id')->constrained('salesmen');
            $t->bigInteger('amount');                 // 서명 정수 — 음수(환수/공제)·양수(특별지급) 모두
            $t->text('reason');                       // 사유 필수 (감사 추적)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_payout_adjustments');
    }
};
