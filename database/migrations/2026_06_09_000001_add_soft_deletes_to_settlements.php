<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review2 항목 B (2026-06-09) — settlements SoftDeletes.
 *
 * deleting 가드(confirmed/paid/closed 차단)는 이미 있으나 pending/calculating 은 hard delete 였다.
 * 회계 추적성상 soft delete(복구 가능)가 적절 → deleted_at 추가.
 * 데모/임포트 정리는 forceDelete() 를 명시적으로 사용 중이라 영향 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
