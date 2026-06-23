<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 큐 2.6 — admin 미입금 우회 승인 감사 (append-only)
        // 미수금 잔존 차량에 대한 통관·선적·DHL 단계 진입을 admin이 승인할 때
        // 누가 / 언제 / 왜 / 어떤 단계 / 그 시점 미입금 금액을 영구 기록.
        // update/delete 모델 레벨 차단 (UnpaidExportOverride::updating/deleting deny).
        Schema::create('unpaid_export_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // 단계별 승인 (per-stage). clearance/shipping=진입(50%) · bl=B/L발행(100%) 우회.
            // 'dhl'(폐기 2026-06-23)은 기존 운영행 보존용으로 enum 유지(신규 미사용). 신규 fresh/test DB도 동일 집합.
            $table->enum('stage', ['clearance', 'shipping', 'dhl', 'bl']);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason'); // application-level min:20 검증
            $table->timestamp('approved_at');
            $table->string('ip_address', 45)->nullable();
            $table->decimal('sale_unpaid_amount_snapshot', 18, 2)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'stage']);
            $table->index(['approved_by', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unpaid_export_overrides');
    }
};
