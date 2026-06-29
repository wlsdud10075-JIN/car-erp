<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 데이터 export 감사 로그 (append-only). 2026-06-29 라운드테이블 필수 선행조건.
 * 누가/언제/어떤 컬럼·범위/몇 행을 내려받았는지 — 책임소재(Codex 사외이사) + §29.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('target', 40)->default('vehicles');   // export 대상
            $table->string('scope', 20)->default('all');          // all / own / team
            $table->unsignedInteger('row_count')->default(0);
            $table->json('columns')->nullable();                  // export 컬럼 key 목록
            $table->json('filters')->nullable();                  // 적용 필터
            $table->timestamp('created_at')->useCurrent();        // append-only — updated_at 없음
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
    }
};
