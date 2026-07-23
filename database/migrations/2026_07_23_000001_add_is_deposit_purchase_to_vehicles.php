<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 보증금 매입 마커 (2026-07-23, jin).
 *   is_deposit_purchase = 이 차 매입대금을 바이어 보증금으로 선지급(C2)했음을 표시하는 영구 도장.
 *   재무가 보증금 매입 선지급(confirmPurchaseFundingByFinance)을 확정하는 시점에 자동 set.
 *   차량 목록·편집 패널 뱃지로 노출(판매완료=초록 / 미완납=주황). 게이트엔 영향 없음
 *   — 선적 진입 락 %는 전역 Setting::lockThreshold('shipping_entry') 로 별도 관제(회사별 super 조정).
 * 3-DB(SQLite/MariaDB/MySQL8) 안전 — boolean default false, INSTANT DDL 무중단.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('is_deposit_purchase')->default(false)->after('is_dealer_purchase');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('is_deposit_purchase');
        });
    }
};
