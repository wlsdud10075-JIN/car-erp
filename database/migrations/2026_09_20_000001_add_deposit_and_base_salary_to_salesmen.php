<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏦 예치금(프리랜서) · 기본급(사내직원) — 담당자에 붙는 두 금액 (jin 2026-09-18).
 *
 * jin: *「프리랜서는 예치금, 사내직원은 기본급, 예치금은 그냥 보유하면되고,
 *        사내직원은 기본급 + 정산금 = 월급 … 영업담당자탭에 나타내게 하는데
 *        사용자관리에서 프리랜서냐 사내직원이냐에 따라서 그걸 기입 할 수 있게」*
 *
 * 🚫 **지급액에 더하지 않는다.** 둘 다 **표시 전용**이다 —
 *    `SettlementPayoutBatch::total_payout` 과 회사이익(`총마진 − 지급 − 발송비`)은 불변.
 *    기본급을 배치 총액에 넣으면 그 지표의 뜻이 바뀐다(SKILLS §8 #72 의 그 형태).
 *
 * 🔑 **null 과 0 을 구분한다** — null = 미입력(화면에 「−」) / 0 = 「없음」을 명시한 것.
 *    default 를 0 으로 두면 전 담당자가 「기본급 0원」으로 보여 미입력을 못 찾는다.
 *
 * 💰 원 단위 정수다. 소수가 없어 decimal 이 필요 없고, 음수도 없다
 *    (예치금 반환은 **배치 수동 조정**으로 처리한다 — 9/10 에 그렇게 했다).
 *    🅿️ 예치금 입출금 이력(원장)은 다음 과제.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->unsignedBigInteger('deposit_krw')->nullable()->after('payout_excluded');
            $table->unsignedBigInteger('base_salary_krw')->nullable()->after('deposit_krw');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn(['deposit_krw', 'base_salary_krw']);
        });
    }
};
