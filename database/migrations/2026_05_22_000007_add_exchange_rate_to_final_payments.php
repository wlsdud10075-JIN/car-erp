<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #7 (2026-05-22) — final_payments.exchange_rate 컬럼 추가.
 *
 * 사용자 명세 (2026-05-21):
 *   "환율 실시간을(송금받을 때 환율) 판매쪽 잔금N+에 추가할 때 즉시 반영...
 *    그때 입금된 날짜의 환율로 계산함"
 *
 * 사용자 결정 (2026-05-22):
 *   "1차 정산 = 입금 시점 환율 합산, 2차 정산 = 최종 환율 재계산해서 차이 ± 지급"
 *
 * 흐름:
 *   - 잔금 추가 시 ExchangeRateService::getRate(vehicle.currency) 자동 기입 (옵션 B)
 *   - 1차 정산: Σ(row.amount × row.exchange_rate) 합산
 *   - 2차 정산(closeSecondarySettlement): 최종 환율로 재계산 → 차이 ± 지급
 *
 * 컬럼:
 *   - decimal(15, 4) nullable — 1 USD = 1367.5000 같은 정밀도
 *   - nullable: 기존 row 영향 X. 차량 currency=KRW 면 NULL 가능 (또는 1.0)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 15, 4)
                ->nullable()
                ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
