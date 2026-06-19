<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 차량등록증 자동차등록번호 — 통관SET 구매리스트 G3(한글/영문등록증 cascade) 수기 입력.
            // 매입 등록번호(registration_number, 말소증 D3)와 별개 필드.
            $table->string('reg_cert_number', 50)->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('reg_cert_number');
        });
    }
};
