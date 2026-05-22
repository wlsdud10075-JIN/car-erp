<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #7 Step C-4 (2026-05-22) — settlements.exchange_difference_krw 컬럼 추가.
 *
 * 사용자 명세 (2026-05-21):
 *   "추후 정산할 때 정산 날짜의 실시간 환율로 재 계산되어
 *    플러스, 마이너스를 계산하여 프리랜서의 정산금액에서 플러스, 마이너스 하여 정산금액이 책정됨"
 *
 * 흐름:
 *   - 1차 정산 (settlement_status='paid') — 입금 시점 환율 기준 actual_payout 산출
 *   - 2차 정산 (closeSecondarySettlement 액션) — 정산 시점 (= 액션 호출 시점) 환율 재계산
 *     → exchange_difference_krw = (current_rate × Σforeign_amount) − sale_received_krw_accumulated
 *     → +이면 환차익 → 프리랜서 정산금 +
 *     → -이면 환차손 → 프리랜서 정산금 -
 *
 * 컬럼:
 *   - decimal(15, 2) nullable + signed (음수 허용 — 환차손)
 *   - secondary_closed_at 와 함께 set (closed 시점에 캡처)
 *
 * KRW 차량: rate=1 자연 → exchange_difference_krw = 0
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('exchange_difference_krw', 15, 2)
                ->nullable()
                ->after('secondary_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('exchange_difference_krw');
        });
    }
};
