<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 소유자 주민(법인)등록번호 — 자동차 서류(말소/등록증/양도) 자동 생성용.
            // NICE API에서 자동 채워지지만 수동 입력도 허용. 평문 저장 (법적 서류 출력 필요).
            $table->string('nice_reg_owner_rrn', 20)->nullable()->after('nice_reg_owner_addr');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('nice_reg_owner_rrn');
        });
    }
};
