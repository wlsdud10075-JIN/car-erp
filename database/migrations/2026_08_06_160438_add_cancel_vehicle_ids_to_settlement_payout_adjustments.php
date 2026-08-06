<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 매입취소 손실 조정이 **어느 차량을 덮는지** 기록 (jin 2026-08-06).
     *
     * 조정을 월배치 제출 시점(정산관리 모달)에 만들도록 바꾸면서, 그 배치가 **최종 승인될 때**
     * 해당 차량의 cancel_loss_settled_at 을 자동으로 찍는다. 어느 차량이 그 조정에 포함됐는지
     * 알아야 하므로 조정 행에 차량 id 목록을 남긴다.
     *
     * ⚠️ 승인 시점에 "그 담당자의 미반영 손실 전부"로 다시 계산하면 안 된다 —
     *    제출과 승인 사이에 새 취소건이 생기면 차감하지도 않은 손실이 반영됨으로 찍힌다.
     *
     * NULL = 일반 수동 조정(매입취소 손실과 무관).
     */
    public function up(): void
    {
        Schema::table('settlement_payout_adjustments', function (Blueprint $t) {
            $t->json('cancel_vehicle_ids')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_adjustments', function (Blueprint $t) {
            $t->dropColumn('cancel_vehicle_ids');
        });
    }
};
