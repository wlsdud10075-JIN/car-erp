<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board 요청에 **금액**을 싣는다 (jin 2026-08-11) — 권위 = docs/integration/board-portal-api.md §11.
 *
 * 2026-08-07 원본 마이그레이션의 "🚫 금액 컬럼을 두지 않는다" 는 여기서 **개정된다**. 그 판단은
 * "신호는 어느 차인지만 지목한다" 였는데, 실무에서 받는 사람이 **얼마를 보내야 하는지 몰라** 결국
 * 카톡으로 되돌아갔다. 금액이 없으면 신호가 일을 끝내지 못한다.
 *
 * 🚫 그래도 **회계에는 반영하지 않는다** — §11-5 흡수 금지는 그대로다. `final_payments` ·
 *    `purchase_balance_payments` · `vehicles.*` 에 간접적으로라도 쓰지 않는다. 이 컬럼은
 *    **표시 전용**이다(돈 보낼 사람이 화면에서 금액을 읽기 위한 것). 자동 기입은 은행 API 연동 이후.
 *
 * ⚠️ type 은 원래부터 `string(30)` 이라 새 값 2개(`purchase_deposit`·`purchase_balance`, 각 16자)를
 *    넣는 데 DDL 변경이 필요 없다. enum 이었다면 SKILLS §8 #36(테스트는 SQLite·운영은 MySQL 1265)
 *    함정에 걸렸을 자리다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            // 표시 전용. KRW 정수. 계약금/매입잔금에만 채워지고 판매대금확인은 null.
            $table->unsignedBigInteger('amount_krw')->nullable()->after('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            $table->dropColumn('amount_krw');
        });
    }
};
