<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💱 일별 환율에 매매기준율(`base_rate`) 칸 (jin 2026-11-17).
 *
 * 12월부터 판매잔금 환율은 사람이 안 적고 시스템이 「전날 마감」을 기록한다. 그 종류를 매매기준율 / 송금 받으실 때 중
 * 기능설정으로 고른다(은행 우대율 확인 뒤 jin 결정). `rate` 는 **종전대로 송금 받으실 때(전신환 매입률)** — 기존 읽는 곳이 많다.
 * 기존 행은 base_rate NULL — `exchange:snapshot-daily --pages=N` 으로 네이버 날짜별 확정값을 다시 받아 채운다(마이그에서 외부 호출 안 함).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_exchange_rates', function (Blueprint $table) {
            $table->decimal('base_rate', 15, 4)->nullable()->after('rate');   // 매매기준율(네이버 closePrice)
        });
    }

    public function down(): void
    {
        Schema::table('daily_exchange_rates', function (Blueprint $table) {
            $table->dropColumn('base_rate');
        });
    }
};
