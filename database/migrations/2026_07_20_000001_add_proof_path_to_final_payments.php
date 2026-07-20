<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 전시문(전신환 송금증) 첨부 경로 — 잔금 금액별 증빙 (jin 2026-07-20, item2).
 * 파일은 vehicle_docs_disk(로컬 public / 운영 s3) 에 저장, 이 컬럼엔 경로만.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->string('proof_path', 500)->nullable()->after('finance_note');
        });
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropColumn('proof_path');
        });
    }
};
