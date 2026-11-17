<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 💳 **현금 → 적립금 전환의 두 줄을 잇는다** (jin 2026-11-17, heymanerp R.S.H 331마3285).
 *
 * 전환은 현금 차감(`buyer_cash_fees` kind=savings)과 적립금 EARNED 를 한 트랜잭션으로 만들지만, 둘을 잇는 칸이 없어
 * 적립금을 취소해도 현금 차감은 그대로 남았다 — 「현금도 아니고 적립금도 아닌」 돈이 생겼다(실측 2,400 EUR).
 * 이 칸이 있으면 취소가 그 현금 차감 줄을 같이 지운다(배분 cascade → 현금 복귀).
 *
 * 기존 전환분 연결 = 같은 바이어·통화·금액 + 생성 시각 ±5초의 EARNED(한 트랜잭션이라 실제로는 같은 초).
 * 짝이 없거나 두 개 이상이면 연결하지 않는다(추측으로 잇지 않는다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->foreignId('buyer_cash_fee_id')->nullable()->after('original_transaction_id')
                ->constrained('buyer_cash_fees')->nullOnDelete();
        });

        foreach (DB::table('buyer_cash_fees')->where('kind', 'savings')->orderBy('id')->get() as $fee) {
            $at = Carbon::parse($fee->created_at);
            $ids = DB::table('savings_statuses')
                ->where('buyer_id', $fee->buyer_id)
                ->where('currency', $fee->currency)
                ->where('transaction_type', 'EARNED')
                ->where('savings', $fee->amount)
                ->whereNull('buyer_cash_fee_id')
                ->whereBetween('created_at', [$at->copy()->subSeconds(5), $at->copy()->addSeconds(5)])
                ->pluck('id');
            if ($ids->count() === 1) {
                DB::table('savings_statuses')->where('id', $ids->first())->update(['buyer_cash_fee_id' => $fee->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buyer_cash_fee_id');
        });
    }
};
