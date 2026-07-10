<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 여권/ID 번호 (jin 2026-07-10) — 판매계약서(sales_contract) 바이어 블록
 *   Passport/ID number 칸을 ERP 바이어 데이터와 일치시키기 위한 컬럼.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('passport_id')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('passport_id');
        });
    }
};
