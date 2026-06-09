<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * i18n Phase 0 — 사용자별 언어 설정.
     * 'ko'(기본) / 'en'. super가 기능설정에서 영어를 켜야 사용자가 en 선택 가능.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('ko')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
