<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 통관 서류 자동기입 (2026-05-24).
 *
 * - nice_raw: NICE 응답 원본 JSON. 전용 컬럼 없는 5개(resValidPeriod·resSpecControlNo·
 *   maxPower·mtrsFomNm·fomNm) + engineSpec 원본 보관. 통관 서류가 여기서 읽어 채움.
 * - deregistration_date: 말소등록일 (NICE 비제공 — 수동/도출). 통관 구매리스트 B7.
 * - nice_spec_cylinders: 기통수. NICE engineSpec "6/3342" 의 앞 숫자 (ingest 시 파싱).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->json('nice_raw')->nullable()->after('memo');
            $table->date('deregistration_date')->nullable()->after('is_deregistered');
            $table->unsignedSmallInteger('nice_spec_cylinders')->nullable()->after('nice_spec_displacement');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['nice_raw', 'deregistration_date', 'nice_spec_cylinders']);
        });
    }
};
