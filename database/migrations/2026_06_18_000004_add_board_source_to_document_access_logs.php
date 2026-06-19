<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board 영업 포털 서류 다운로드 감사 — board 유저는 car-erp 계정(user_id) 없음.
 * user_id nullable 화 + source('board_api')·actor_email(요청 영업) 추가.
 * 권위 = docs/integration/board-portal-api.md §6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_access_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('source', 30)->nullable()->after('user_id');      // 예: 'board_api'
            $table->string('actor_email', 191)->nullable()->after('source'); // board 요청 영업 이메일
        });
    }

    public function down(): void
    {
        Schema::table('document_access_logs', function (Blueprint $table) {
            $table->dropColumn(['source', 'actor_email']);
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
