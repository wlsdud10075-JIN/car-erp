<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alimtalk_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_code', 40);        // erp_deal_done ...
            $table->string('phone', 20);
            $table->text('message')->nullable();        // 실제 발송 본문(변수 치환 후)
            $table->string('msgid')->nullable();        // BizM 응답 msgid (결과조회용)
            $table->string('status', 10);               // sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['template_code', 'created_at']);
            $table->index(['vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alimtalk_logs');
    }
};
