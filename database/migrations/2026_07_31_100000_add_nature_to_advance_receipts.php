<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가수금 성격 구분 (jin 2026-07-31) — 갚아야 할 돈인지, 대표 자산성인지.
 *
 * 청산가치는 가수금을 전액 부채로 차감해 왔는데, 실제로는 성격이 섞여 있다.
 *   · 김진숙차입 → 갚아야 할 돈 (liability)
 *   · 대표이사 가수금 · 싼카대여 → 대표 본인 돈이라 자산성일 수 있음 (equity) — jin 확인 중
 *
 * ⚠️ 기본값을 'liability' 로 두는 게 핵심이다. 현행 계산(전액 부채 차감)과 동일하므로
 *    배포하는 순간 청산가치가 흔들리지 않는다. 분류를 정한 뒤 화면에서 바꾸면 그때 반영된다.
 *
 * ⚠️ enum 이 아니라 string 이다 — enum 은 SQLite(테스트)가 강제하지 않아 운영 MySQL 에서만
 *    터진다(SKILLS §8 #36). 값 목록은 AdvanceReceipt::NATURES 가 단일 출처.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advance_receipts', function (Blueprint $table) {
            $table->string('nature', 20)->default('liability')->after('amount')
                ->comment('liability=갚아야 할 돈 / equity=대표 자산성');
        });
    }

    public function down(): void
    {
        Schema::table('advance_receipts', function (Blueprint $table) {
            $table->dropColumn('nature');
        });
    }
};
