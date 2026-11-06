<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⏸ 지급보류 장부 (jin 2026-11-06) — 사내직원 월정산이 대표까지 승인된 뒤 사람 단위로 그 달 지급합계(+/−)를 보류한다.
 *
 * 승인 박제(breakdown_snapshot)·총액은 손대지 않는다 — 보류는 이 표에만 남고 화면·엑셀이 겹쳐 보여 준다.
 * 상태(enum 아님 — §8 #36): held 보류 중 / queued 다음 월정산에 추가 대기 / released_batch 월정산 줄로 나감 /
 *   released_paid 월정산 밖에서 지급 / cancelled 보류 취소.
 * 새 표 하나만 만든다 — 기존 데이터 무변경.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_holds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('salesman_id')->constrained('salesmen');
            $t->foreignId('payout_batch_id')->constrained('settlement_payout_batches');   // 보류한 그 달 월정산
            $t->string('month', 7);                                                         // 그 월정산 귀속월(YYYY-MM)
            $t->bigInteger('amount_krw');                                                   // 보류 시점 지급합계(+/−, 박제)
            $t->string('status', 16)->default('held');
            $t->foreignId('released_batch_id')->nullable()->constrained('settlement_payout_batches');   // 「월정산 추가」로 들어간 배치
            $t->foreignId('held_by')->nullable()->constrained('users');
            $t->foreignId('released_by')->nullable()->constrained('users');
            $t->timestamp('released_at')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestamps();
            $t->index(['salesman_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_holds');
    }
};
