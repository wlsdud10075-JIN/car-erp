<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🤝 B2B 파트너스 메모 (jin 2026-11-26) — 일반 바이어 메모(`memo`)와 따로. 바이어 창의 B2B 칸에서 쓰고 B2B 목록 행에 보인다.
 * nullable 추가만 — 3-DB 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $t) {
            $t->text('b2b_memo')->nullable()->after('api_linked');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $t) {
            $t->dropColumn('b2b_memo');
        });
    }
};
