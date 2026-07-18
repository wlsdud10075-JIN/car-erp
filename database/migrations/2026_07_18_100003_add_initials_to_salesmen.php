<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 영업담당자 이니셜 (jin 2026-07-18, item 7).
 * Proforma Invoice No. = {이니셜}MU{차대번호 숫자}. 담당자 관리 화면에서 입력. 순수 additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->string('initials', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('initials');
        });
    }
};
