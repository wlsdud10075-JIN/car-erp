<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vehicle_photos 에 category 컬럼 추가 (2026-07-06, jin) — 첨부를 탭별 갤러리로 분리.
 *   null/'basic' = 기본정보 탭 차량 사진(기존 전부 null), 'shipping' = 선적 탭 선박 사진.
 *   기존 행은 null 유지 → 기본정보 갤러리에 그대로 표시(하위호환). 테이블 신설 금지(메모리) — 컬럼만 추가.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
