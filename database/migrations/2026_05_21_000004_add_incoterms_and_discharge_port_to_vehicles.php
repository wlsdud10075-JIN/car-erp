<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21 — vehicles.incoterms + discharge_port_id (CIPL 이식).
 *
 * incoterms:
 *   - enum [FOB, CFR] nullable. 차량별 다름 (사용자 결정 2026-05-21).
 *   - CIPL 생성 시 C32/C37 셀에 채움. NULL 이면 빈 값.
 *
 * discharge_port_id:
 *   - ports.id FK. nullable.
 *   - NULL 이면 CIPL 생성 시 buyer.country.name fallback (기존 동작 유지).
 *   - 같은 국가 내 다른 항구 (예: VLADIVOSTOK·NAKHODKA in RUSSIA) 구분 필요.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('incoterms', ['FOB', 'CFR'])->nullable()->after('port_of_loading');
            $table->foreignId('discharge_port_id')->nullable()->after('incoterms')
                ->constrained('ports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discharge_port_id');
            $table->dropColumn('incoterms');
        });
    }
};
