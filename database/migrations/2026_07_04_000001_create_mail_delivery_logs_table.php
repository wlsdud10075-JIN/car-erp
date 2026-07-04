<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10);              // gmail | ses
            $table->string('from_address')->nullable();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->json('document_names')->nullable(); // 첨부 파일명 목록
            $table->string('status', 10);               // sent | failed
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_delivery_logs');
    }
};
