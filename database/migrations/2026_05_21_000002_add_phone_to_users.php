<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21 — users.phone (전화번호) nullable string.
 *
 * 사용자 요청: /admin/users 폼에 전화번호 필드 추가 + 자동 하이픈 포맷.
 * User-Salesman 1:1 마스터 원칙에 따라 User 가 phone 소유, Salesman.phone 으로 자동 미러링.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
