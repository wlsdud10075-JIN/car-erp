<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 관제센터 (jin 2026-11-16 «시스템 문제도 잡지만 우리 루틴을 했는지 안 했는지도 알아야»).
 *   ops_marks          = 「이 루틴을 했다」 한 줄 — 사람 루틴(씬 점검·Notion 반영 확인·메모리 정리) + 서버 밖 루틴(GPU PC 야간 작업)
 *   telegram_messages  = 텔레그램으로 나간 알림의 사본 — 관제에서 모아 본다(텔레그램을 대체하지 않는다)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_marks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);                 // scene-check · nightly-investigate …(OpsRoutines 가 뜻을 안다)
            $table->string('status', 10)->default('ok');   // ok | failed
            $table->string('note', 500)->nullable();
            $table->string('source', 20);             // button | command | api
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['key', 'created_at']);
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');                       // 실제로 보낸 전문(머리글 포함)
            $table->boolean('sent');                    // 텔레그램 API 가 받았나
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('ops_marks');
    }
};
