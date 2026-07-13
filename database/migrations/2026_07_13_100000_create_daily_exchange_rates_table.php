<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일별 마감환율 이력 (2026-07-13) — 잔금 날짜 지정 시 그 날짜의 마감환율(송금 받으실때/전신환 매입률)을
 * 자동 기입하기 위한 저장소. 과거=jin 제공 xlsx backfill / 이후=매일 09:00 네이버 스냅샷(전날 마감).
 *
 * 3-DB 안전(project_db_tier_mismatch): decimal/date/string + FK/CHECK 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);          // USD/JPY/EUR/GBP/CNY (KRW 제외, JPY=100엔 기준)
            $table->date('rate_date');              // 그 날짜의 마감
            $table->decimal('rate', 15, 4);         // 송금 받으실때(전신환 매입률) — 네이버 th_ex5 / xlsx H열
            $table->string('source', 20)->default('naver');   // history(과거 xlsx) | naver(스냅샷)
            $table->timestamps();

            $table->unique(['currency', 'rate_date']);   // 통화·일자 유일 + rateForDate(<=date desc) 조회 인덱스
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_exchange_rates');
    }
};
