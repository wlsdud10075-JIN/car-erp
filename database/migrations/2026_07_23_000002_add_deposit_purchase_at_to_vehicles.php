<?php

use App\Models\InterVehicleTransfer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 보증금 매입 도장 일시 (2026-07-23, jin) — 바이어 60% 독촉 알림톡 타이머 기준.
 *   재무가 보증금 매입 선지급(confirmPurchaseFundingByFinance)을 확정하는 시점에 최초 1회 기록.
 *   D+5부터 독촉(영업·관리), D+10 초과 시 대표 처분요청 알림의 기산점.
 * 3-DB(SQLite/MariaDB/MySQL8) 안전 — nullable datetime, INSTANT DDL 무중단.
 * backfill: 이미 is_deposit_purchase=true 인데 날짜 없는 행(코드 선행분)은
 *   해당 차의 최초 executed 보증금선지급 이체 executed_at 으로 채움(orphan 플래그 방지).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('deposit_purchase_at')->nullable()->after('is_deposit_purchase');
        });

        // backfill — 기존 도장 행에 날짜 소급(최초 선지급 확정 시각)
        DB::table('vehicles')->where('is_deposit_purchase', true)->whereNull('deposit_purchase_at')
            ->orderBy('id')->pluck('id')
            ->each(function ($vid) {
                $at = InterVehicleTransfer::where('target_vehicle_id', $vid)
                    ->where('kind', InterVehicleTransfer::KIND_PURCHASE_FUNDING)
                    ->where('status', InterVehicleTransfer::STATUS_EXECUTED)
                    ->whereNotNull('executed_at')
                    ->min('executed_at');
                if ($at) {
                    DB::table('vehicles')->where('id', $vid)->update(['deposit_purchase_at' => $at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('deposit_purchase_at');
        });
    }
};
