<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧾 자금 보고 「총자산 = 자본 + 부채」 (대표 2026-11-02 «자산 = 자본 + 부채 … 총자산은 매번 똑같아야») — 미수를 두 기준으로 담는다.
 *
 *   receivable_krw         = 오늘 환율로 재평가한 미수(종전, 순자산·손익 공식이 쓴다)
 *   receivable_frozen_krw  = 차량별 판매환율로 굳힌 미수 = 채권현황 알림톡의 「미수합계」와 같은 숫자
 *
 * 대표가 채권현황에서 읽는 미수(50억 → 36억)와 자금보고의 미수가 환율 때문에 달라 보이면 두 보고를 다 못 믿는다.
 * 굳힌 값을 같이 박아 두면 「미수 36억 + 환율 재평가 ±x」로 둘의 차이를 그대로 보여줄 수 있다.
 *
 * ⚠️ 과거 스냅샷은 소급하지 않는다 — null 이면 derive() 가 receivable_krw 로 폴백하고 환차 줄을 그리지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->bigInteger('receivable_frozen_krw')->nullable()->after('receivable_krw')
                ->comment('차량별 판매환율로 굳힌 미수(채권현황 기준) — 환율 재평가 분리용');
        });
    }

    public function down(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->dropColumn('receivable_frozen_krw');
        });
    }
};
