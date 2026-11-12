<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 예약 작업 실행 기록 (jin 2026-11-12 관제센터 「루틴」 칸 — 1단계).
 * 22개 정기 작업이 돌 때마다 한 줄: 시작·끝·성공/실패·이유·걸린 시간. 지금까지는 실패만 텔레그램으로 오고 성공은 어디에도 안 남았다.
 * status = running | ok | failed (문자열 — §8 #36). 90일 넘은 줄은 매일 지운다(model:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 120);
            $table->string('expression', 60)->nullable();   // cron 식(언제 도는 작업인가)
            $table->string('status', 10)->default('running');
            $table->smallInteger('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->index(['command', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
