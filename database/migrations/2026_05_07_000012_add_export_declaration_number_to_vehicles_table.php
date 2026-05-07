<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 수출신고번호 — 수출통관완료 컬럼 바로 뒤에 추가
            $table->string('export_declaration_number')->nullable()->after('export_declaration_document');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('export_declaration_number');
        });
    }
};
