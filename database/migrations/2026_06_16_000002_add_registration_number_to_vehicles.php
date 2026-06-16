<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 차량 등록번호 — 통관 SET 구매리스트 D3("등록번호") → 말소증 "제 [등록번호] 호" cascade 용.
 * 차량번호(번호판, vehicle_number)와 별개. 매입 탭에서 수기 입력.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('registration_number', 50)->nullable()->after('purchase_remittance_memo');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('registration_number');
        });
    }
};
