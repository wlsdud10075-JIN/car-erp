<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자금현황에 예치·가수금 반영 (jin 2026-07-27, 안건4 2단계).
 *
 * - auction_deposit_krw: 경매장에 예치한 돈 = **자산**. 통장에서 나갔지만 어디에도 안 잡혀
 *   자산이 통째로 증발하고 있었다(보증금 낼수록 청산가치 감소).
 * - advance_krw: 가수금 = **부채**(갚는 돈, 투입원금과 별개 — jin 2026-07-27).
 *   통장에 들어와 현금은 늘었는데 갚을 의무가 안 잡혀 손익이 부풀려지고 있었다.
 *
 * ⚠️ 스냅샷 컬럼으로 캡처하는 이유: derive() 는 며칠 지난 스냅샷을 받을 수 있다.
 *    거기서 실시간 합계를 더하면 **옛 통장잔액 + 오늘 보증금**이 짝지어져 그 금액만큼 부풀려진다.
 *    inventory/receivable/payable 과 동일하게 capture() 시점 값을 박아둔다.
 *
 * 기존 스냅샷은 0 — 그 시점엔 실제로 기록된 예치·가수금이 없었으므로 맞다. 백필하지 않는다
 * (오늘 합계를 과거에 채우면 없던 이력을 만들어내는 것).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->bigInteger('advance_krw')->default(0)->after('payable_krw');
            $table->bigInteger('auction_deposit_krw')->default(0)->after('advance_krw');
        });
    }

    public function down(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->dropColumn(['advance_krw', 'auction_deposit_krw']);
        });
    }
};
