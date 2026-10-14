<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검차직원을 사용자 관리에서 등록 (jin 2026-10-14 «사용자관리에서 한번에 관리») — 로그인하지 않는 사람이라 이메일이 없다.
 *
 * `users.email` 을 nullable 로. unique 인덱스는 그대로 — MySQL·SQLite 모두 NULL 은 여러 행 허용.
 * 로그인(`Auth::attempt(['email' => …])`)은 NULL 과 절대 안 맞으므로 접근이 열리지 않는다. 비밀번호는 무작위 해시.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
