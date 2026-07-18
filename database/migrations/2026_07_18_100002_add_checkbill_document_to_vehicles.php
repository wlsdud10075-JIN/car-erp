<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 체크빌(check B/L) 문서 (jin 2026-07-18, item 4).
 * B/L 발급 전 확인용 draft(테스트) B/L 업로드. bl_document(최종 발급본)과 별개. 순수 additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('checkbill_document')->nullable()->after('bl_document');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('checkbill_document');
        });
    }
};
