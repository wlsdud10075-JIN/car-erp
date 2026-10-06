<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💸 **미청산 이월 → 월배치 자동 조정 줄** (jin 2026-10-06 「너 추천으로 하자」).
 *
 * 월배치를 제출할 때 담당자별 미청산 이월(2차 마감 차액 = 환차분 + 비용분)을 조정 한 줄로 자동 생성하고,
 * 같은 금액을 청산 기록(`carryover_clearances`)으로 남겨 `Salesman::unconsumed_carryover` 가 0 이 되게 한다.
 * 어느 배치가 가져갔는지 추적하고, **배치가 반려되면 그 청산을 되돌려** 잔액이 다시 살아나야 하므로 배치 id 를 박는다.
 * NULL = 사람이 영업담당자 자금 화면에서 누른 퇴사자 청산(2026-06-10, 종전 그대로).
 *
 * 구: 담당자에게 **다음 정산이 새로 생길 때** `Settlement::creating` 훅이 흡수 — 새 차가 없으면 영영 미청산.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carryover_clearances', function (Blueprint $table) {
            $table->unsignedBigInteger('payout_batch_id')->nullable()->after('salesman_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('carryover_clearances', function (Blueprint $table) {
            $table->dropIndex(['payout_batch_id']);
            $table->dropColumn('payout_batch_id');
        });
    }
};
