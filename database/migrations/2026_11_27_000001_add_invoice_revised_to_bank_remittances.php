<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏦 은행용 Proforma 수정본 올리기 (jin 2026-11-26) — 엑셀로 받아 고친 파일을 다시 올리면 그 파일이 은행으로 간다.
 * 언제·누가 바꿨는지만 남긴다(파일 자체는 invoice_path 를 바꿔 쓴다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_remittances', function (Blueprint $table) {
            $table->dateTime('invoice_revised_at')->nullable()->after('invoice_name');
            $table->foreignId('invoice_revised_by')->nullable()->after('invoice_revised_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_remittances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_revised_by');
            $table->dropColumn('invoice_revised_at');
        });
    }
};
