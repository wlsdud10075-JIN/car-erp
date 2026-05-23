<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #6+7 보강 (2026-05-23) — settlements.exchange_rate_at_close 컬럼 추가.
 *
 * 사용자 명세:
 *   "1차 완료 후 환차 입력할 수도 있고, 2차 하면서 환차 입력할 수도 있는데 이런 씬이 없는거같은데"
 *
 * 결정:
 *   - 자동 + 수정 가능 (default = ExchangeRateService 자동 fetch)
 *   - 2차 대기 중 언제든 입력 + 추후 수정 가능 (closed 전까지)
 *   - 환율 입력 → diff 자동 derive (audit trail: rate 보존)
 *
 * 흐름:
 *   - secondary_status='pending' 동안 saveExchangeRate 액션으로 환율 저장·수정
 *   - closeSecondarySettlement 시 저장된 exchange_rate_at_close 우선 사용 (없으면 자동 fetch fallback)
 *   - closed 후 환율 잠금 — exchange_rate_at_close, exchange_difference_krw 변경 불가
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('exchange_rate_at_close', 15, 4)
                ->nullable()
                ->after('exchange_difference_krw');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('exchange_rate_at_close');
        });
    }
};
