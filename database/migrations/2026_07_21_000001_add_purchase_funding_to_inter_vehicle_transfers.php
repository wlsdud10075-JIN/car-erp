<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 보증금 매입 funding (C2, jin 2026-07-21) — 차량간 이체의 매입 landing 변형.
 *   kind='purchase_funding': 소스 차 보증금(외화)으로 대상 차 매입대금(원화)을 funding → 매입 GREEN.
 *   - amount (기존, 외화) = 소스 차 차감 외화액 = amount_krw ÷ source_exchange_rate.
 *   - amount_krw (신규, 원화) = 대상 매입 funding 원화액 (매입 PBP 로 landing, 정확값).
 *   - source_exchange_rate (신규) = funding 시점 소스 판매환율 스냅샷 (회수 환차 baseline).
 *   - purchase_balance_payment_id (신규) = 생성된 매입 PBP 참조 (app 레벨 무결성, SQLite ALTER FK 미지원 → DB FK 없음).
 * additive-only. 기존 standard/deposit_apply 이체 무변경.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->decimal('amount_krw', 15, 2)->nullable()->after('amount');
            $table->decimal('source_exchange_rate', 15, 4)->nullable()->after('target_payment_type');
            $table->unsignedBigInteger('purchase_balance_payment_id')->nullable()->after('source_exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->dropColumn(['amount_krw', 'source_exchange_rate', 'purchase_balance_payment_id']);
        });
    }
};
