<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🚪 **배치 종류 — 월정산(`month`) / 퇴사 정산(`leaver`)** (jin 2026-10-15 「퇴사자 사용 씬」).
 *
 * 프리랜서 퇴사자는 10일을 기다리지 않고 그 사람 몫만 묶어 바로 결재에 올린다(`SettlementPayoutBatch::submitForLeaver`).
 * 같은 테이블·같은 결재선·같은 알림톡 3종을 쓰되 **종류를 갈라야** 하는 이유 —
 *   `isMonthClosed()`(그 달 approved 배치 존재 = 월 마감)와 「월당 pending 1건」 검사가 종류를 안 보면,
 *   10월 20일에 퇴사 배치가 승인되는 순간 10월이 마감돼 월결산 보고가 반쪽으로 나가고 뒤늦게 생긴 정산이 다음 달로 밀린다.
 *   ⇒ 그 둘은 `kind='month'` 만 센다. `salesman_id` = 퇴사 배치의 그 한 사람(월정산은 NULL).
 * 이중 지급 방지는 종전과 같은 축 — 정산 한 건은 `settlements.payout_batch_id` 로 배치 하나에만 묶인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $table) {
            $table->string('kind', 10)->default('month')->after('month')->index();
            $table->foreignId('salesman_id')->nullable()->after('kind')->constrained('salesmen')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salesman_id');
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
