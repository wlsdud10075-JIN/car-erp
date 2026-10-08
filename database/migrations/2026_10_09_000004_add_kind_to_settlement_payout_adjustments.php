<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 v3 (jin 2026-10-08) — 조정 줄의 **종류**(kind): manual · carryover · loss · incentive.
 *
 * 지금까지 조정은 사유 문자열로만 갈렸다(이월 자동 반영 · 매입취소 손실 · 수기). v3 의 「추가 인센티브」(사람당 N건,
 * 결재 중 수정 가능)는 사람 카드와 결재 내역에서 **따로 보여야** 하므로 종류 칸을 둔다. 문자열 + 코드 상수
 * (SettlementPayoutAdjustment::KINDS) — enum 아님(§8 #4).
 *
 * 백필(기존 행):
 *   - cancel_vehicle_ids 가 있으면 loss (매입취소 손실 차감만 이 칸을 쓴다)
 *   - carryover_clearances(payout_batch_id·salesman_id·amount_krw) 와 짝이 맞으면 carryover
 *     — 🚫 사유 문자열 매칭 금지: 사유는 사람이 자유롭게 적는다
 *   - 나머지 = manual
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payout_adjustments', function (Blueprint $table) {
            $table->string('kind', 16)->default('manual')->after('amount');
        });

        DB::table('settlement_payout_adjustments')->whereNotNull('cancel_vehicle_ids')->update(['kind' => 'loss']);

        DB::table('carryover_clearances')->whereNotNull('payout_batch_id')
            ->orderBy('id')
            ->each(function ($c) {
                DB::table('settlement_payout_adjustments')
                    ->where('batch_id', $c->payout_batch_id)
                    ->where('salesman_id', $c->salesman_id)
                    ->where('amount', (int) $c->amount_krw)
                    ->where('kind', 'manual')
                    ->limit(1)
                    ->update(['kind' => 'carryover']);
            });
    }

    public function down(): void
    {
        Schema::table('settlement_payout_adjustments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
