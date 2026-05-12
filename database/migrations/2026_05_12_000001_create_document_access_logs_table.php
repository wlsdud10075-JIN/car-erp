<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 문서 다운로드 감사 로그 (개인정보보호법 §29 안전조치 — 접근 기록)
        // 모든 인증 user가 RRN 포함 PDF·CIPL을 다운로드할 때마다 1행 기록.
        // 7종 document_type: deregistration / registration_application / transfer_certificate / invoice / sales_contract / ro_cipl / con_cipl
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['vehicle_id', 'created_at']);
            $table->index(['document_type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
