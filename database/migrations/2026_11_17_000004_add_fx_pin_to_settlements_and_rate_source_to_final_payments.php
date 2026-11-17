<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 💱 환차 제외 (jin 2026-11-17) — 두 칸.
 *
 * ① settlements.fx_excluded (nullable bool) — 「이 정산은 환차를 뺐나」 **박제**.
 *    정산 숫자(총마진·실지급·회사몫)는 지급 뒤에도 실시간 계산이라, 판정을 설정만 보고 하면 설정을 바꾸는 순간 지급 끝난 정산까지
 *    소급해서 움직인다. ⇒ 월정산 배치에 묶일 때·지급될 때 한 번 정해 박는다(Settlement::pinFx). NULL = 아직 안 정함(설정을 따른다).
 *    기존 정산 중 이미 배치에 묶였거나 지급·2차 마감된 것은 **환차 포함(false)** 으로 박는다 — 지금까지 그렇게 지급됐다.
 *
 * ② final_payments.rate_source_date · rate_kind — 시스템이 자동 기록한 환율이 **어느 날짜·어떤 종류**였는지.
 *    입금일 전날 마감이 아직 수집 안 됐으면 그 전 영업일 값이 들어간다(조용히 D-2) — 그걸 나중에 가려낼 근거.
 *    사람이 적은 옛 행은 NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->boolean('fx_excluded')->nullable()->after('is_domestic');
        });
        Schema::table('final_payments', function (Blueprint $table) {
            $table->date('rate_source_date')->nullable()->after('exchange_rate');
            $table->string('rate_kind', 10)->nullable()->after('rate_source_date');
        });

        DB::table('settlements')
            ->where(fn ($q) => $q->whereNotNull('payout_batch_id')
                ->orWhere('settlement_status', 'paid')
                ->orWhere('secondary_status', 'closed'))
            ->update(['fx_excluded' => false]);
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('fx_excluded');
        });
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropColumn(['rate_source_date', 'rate_kind']);
        });
    }
};
