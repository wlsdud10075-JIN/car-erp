<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 11-4 G7 — 핵심 컬럼 변경 감사 로그.
     *
     * document_access_logs(읽기 이벤트)와 분리. 무기한 보존 (운영 결정).
     * 1차 추적 범위:
     *   - Vehicle: sale_price, is_disposed, progress_status_cache,
     *              nice_reg_owner_rrn(변경 사실만, old/new는 마스킹),
     *              결제 컬럼 7종(deposit_down_payment, interim_payment,
     *              advance_payment1·2, down_payment, selling_fee_payment,
     *              savings_used)
     *   - Settlement: settlement_status, paid_at
     *
     * Bulk update(DB::table()->update())는 모델 이벤트 미발동 → 감사 불가.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 20);             // created|updated|deleted|restored|force_deleted
            $table->string('column_name', 60)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();   // append-only — updated_at 없음

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_target_idx');
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->index(['column_name', 'created_at']);    // RRN 변경 검색
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
