<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가수금 상환 표시 (jin 2026-08-12) — 갚은 행을 지우지 않고 **남긴다**.
 *
 * 종전엔 반제 = 행 삭제였다(목록 합계 = 현재 잔액). 숫자는 맞았지만 **갚은 이력이 화면에서 사라져서**
 * "그동안 얼마를 갚았나" 를 볼 수 없었다. 이제 상환일을 찍어 목록에 남기고, 집계에서만 뺀다.
 *
 * ⚠️ **집계에서 빼는 게 이 변경의 핵심이다.** `liabilityKrw()`(청산가치 차감)·`equityKrw()`(원금 가산)이
 *    상환분을 계속 세면 **갚았는데도 부채로 잡히거나 원금이 부풀려진다** — 화면만 초록으로 바뀌고
 *    숫자는 틀린 상태가 된다. 모델 스코프(`outstanding`)가 단일 출처.
 *
 * nullable 2개 = MySQL 8 INSTANT DDL. 기존 행은 전부 NULL(미상환)이라 배포 시 숫자가 안 흔들린다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advance_receipts', function (Blueprint $table) {
            $table->date('repaid_at')->nullable()->after('nature')
                ->comment('상환(반제)일 — NULL 이면 미상환. 집계는 미상환만 센다.');
            // 누가 눌렀나 — 돈이 나간 사건이라 사람 없이 남으면 나중에 못 따진다.
            $table->foreignId('repaid_by')->nullable()->after('repaid_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advance_receipts', function (Blueprint $table) {
            $table->dropForeign(['repaid_by']);
            $table->dropColumn(['repaid_at', 'repaid_by']);
        });
    }
};
