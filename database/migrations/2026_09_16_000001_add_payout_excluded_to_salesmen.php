<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🚪 정산 지급 대상이 아닌 영업담당자 (jin 2026-09-16).
 *
 * 「헤이맨」처럼 **사람이 아닌 계정**(자매 회사)이 담당자로 들어가 있는 경우가 있다.
 * 그 건들은 기록으로만 남기고 실지급이 0 원인데, 확정하면 **월배치 대상에 0 원 줄로 올라온다**
 * (실측 ssancarerp 2026-08 배치 대상 15건이 전부 그것이었다 — 지급 합계 0원).
 *
 * 🚫 「실지급 0원이면 빼기」로 만들지 않았다 — 0원 정산이 3사 통틀어 58건인데 그중 39건은
 *    **진짜 사람의 정산**이다(서류비로 몫이 깎였거나 기준액이 건당 금액보다 작은 경우).
 *    그건 0원이어도 그 달 기록이라 재무가 확정하고 넘어가야 한다. 게다가 음수 지급(손실 분담)이
 *    48건 있어 금액으로 가르면 그것까지 휩쓸린다.
 *
 * ⇒ 「누구인가」로 가른다. 기본값 false 라 기존 담당자는 전부 종전대로 동작한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->boolean('payout_excluded')->default(false)->after('per_unit_tier_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('payout_excluded');
        });
    }
};
