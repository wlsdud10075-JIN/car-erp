<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자금 현황 일별 스냅샷 (jin 2026-07-23) — 대표 자금/손익 추적.
 *   재무·[관리]·업무관리자가 매일 통장 마감잔액 3통화 입력 → 그 시점 ERP 값(재고·미수·미지급) 함께 캡처.
 *   손익 = 청산가치(현금+재고−미지급) − 투입원금(Setting capital_principal_krw). 미수는 손익에서 제외(대표 정책).
 *   추이 그래프·주간 알림톡의 단일 소스.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();           // 하루 1건
            // 통장 마감잔액 (수동 입력, 펌뱅킹 전까지)
            $table->bigInteger('balance_krw')->default(0);
            $table->decimal('balance_usd', 15, 2)->default(0);
            $table->decimal('balance_eur', 15, 2)->default(0);
            // 입력 시점 ERP 캡처 (원화 환산 합)
            $table->bigInteger('inventory_krw')->default(0);   // 재고 원가 (거래완료 제외)
            $table->bigInteger('receivable_krw')->default(0);  // 미수 (통화별 → KRW)
            $table->bigInteger('payable_krw')->default(0);     // 매입 미지급 (딜러)
            // 적용 환율 (→KRW)
            $table->decimal('fx_usd', 10, 2)->default(0);
            $table->decimal('fx_eur', 10, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_snapshots');
    }
};
