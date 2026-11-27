<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏦 은행 서류 「PDF 한 파일로 합쳐 보내기」 (jin 2026-11-27) — 은행에 실제로 간 합친 PDF 를 건마다 가리킨다.
 * 여러 건이 같은 파일을 나눠 쓴다(마지막 건이 지워질 때 파일도 지운다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_remittances', function (Blueprint $table) {
            $table->string('sent_bundle_path')->nullable()->after('mailed_at');
            $table->string('sent_bundle_name', 200)->nullable()->after('sent_bundle_path');
        });
    }

    public function down(): void
    {
        Schema::table('bank_remittances', function (Blueprint $table) {
            $table->dropColumn(['sent_bundle_path', 'sent_bundle_name']);
        });
    }
};
