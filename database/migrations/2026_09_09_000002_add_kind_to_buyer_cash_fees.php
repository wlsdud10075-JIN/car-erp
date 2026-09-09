<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 현금 원장의 「털어낸 행」에 종류를 붙인다 (jin 2026-09-09).
 *
 * 지금까지는 송금 수수료 한 종류뿐이라 화면이 전부 「수수료로 털기」로 그렸다. 여기에 **과입금 정리**가
 * 합류한다 — 과입금을 적립금·잡손실로 돌릴 때 감액된 잔금만큼 현금이 지갑으로 되돌아오는데, 그걸
 * 원장에서 빼는 행이다. 둘을 같은 라벨로 그리면 원장을 읽는 사람이 「무슨 수수료지?」 하게 된다.
 *
 * 🚫 enum 이 아니라 string — 앞으로 종류가 더 붙을 수 있고, enum 은 값 추가마다 3사 ALTER 가 필요하다
 *    (SKILLS §8 #4·#36). 검증은 애플리케이션(`BuyerCashFee::KINDS`)이 한다.
 * 기존 행은 전부 수수료라 default 'fee' 가 곧 데이터 이관이다(백필 불필요).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_cash_fees', function (Blueprint $table) {
            $table->string('kind', 20)->default('fee')->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_cash_fees', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
