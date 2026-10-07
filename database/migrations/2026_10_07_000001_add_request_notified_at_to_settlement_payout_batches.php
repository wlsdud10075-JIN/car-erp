<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📨 **월배치 승인요청 마지막 발송 시각** (jin 2026-10-07 「재전송 버튼을 만들긴 해야겠다. 매번 내가 수동으로 해줄 순 없겠네」).
 *
 * 제출·단계 승인·재전송 때 `notifyPayoutRequest()` 가 찍는다. 화면이 ①재전송 연타 방지(10분)
 * ②「마지막 발송 N분 전 · 전달됨/실패」 표시에 쓴다. 알림톡 로그엔 배치 id 가 없어서, 이 시각 이후의
 * `erp_payout_request` 로그를 그 배치의 발송 결과로 본다. NULL = 이 컬럼 이전 배치(표시만 생략).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $table) {
            $table->timestamp('request_notified_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_batches', function (Blueprint $table) {
            $table->dropColumn('request_notified_at');
        });
    }
};
