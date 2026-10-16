<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💳 **적립금 거래에 처리자(`created_by`)** (jin 2026-10-16 «적립금 추가하고 취소하고 한 기록이 있는데 누가 수정했는지 안 나오네»).
 *
 * `savings_statuses` 는 생긴 뒤(2026-05) 한 번도 행위자를 안 남겼다 — 바이어탭 적립·취소, 판매탭 사용/환불(H6 훅),
 * 채권관리·현금 원장 전환까지 다섯 경로 전부. 감사로그도 0건(heymanerp 실측). 현금 원장(`buyer_cash_*`)은 09-05 부터 `created_by` 가 있다.
 * 기존 행은 NULL 로 남는다(복원 근거 없음). 기록은 `SavingsStatus::booted()` 가 생성 시점에 한 번에 한다(경로마다 손으로 넣지 않는다, §8 #38).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
