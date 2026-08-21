<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 저당 설정 표시 (jin 2026-08-21).
 *
 * 국내 딜러에게 나가는 매입대금 입금완료 알림톡(`erp_purchase_paid_v2`)에
 * **「저당이 설정된 차량입니다. 저당 해지를 부탁드립니다.」** 한 줄을 실을지 가르는 값이다.
 *
 * 🚗 **원부조회(carmodoo)가 저당 건수를 읽어오지만 저장하지는 않는다** — 그건 on-demand 조회다.
 *    딜러에게 나가는 문장을 자동 판정에 맡기지 않고 **사람이 명시**한다. 조회 결과가 최신이라는
 *    보장이 없고, 잘못 켜지면 "저당 없는 차에 해지 요청" 이 딜러에게 나간다.
 *
 * ⚠️ **해제 주체가 사람이다.** 딜러가 저당을 풀어도 이 값은 자동으로 안 꺼진다. 그래서 발송
 *    확인창에서 저당 문구 포함 여부를 매번 보여준다(끄는 걸 잊었으면 그때 눈에 띈다).
 *
 * 딜러에게 나가는 문장을 좌우하므로 변경은 감사로그에 남긴다(`Vehicle::AUDITED_COLUMNS`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('has_mortgage')->default(false)->after('is_unsecured_down');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('has_mortgage');
        });
    }
};
