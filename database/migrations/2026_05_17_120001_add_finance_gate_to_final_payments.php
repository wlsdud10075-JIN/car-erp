<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 20-A — 판매 잔금 재무 확정 게이트 (회의록 2026-05-17, P2 채택).
     *
     * 신규 컬럼:
     *   confirmed_by_user_id — 재무 확정 사용자 (settlement role)
     *   confirmed_at         — 재무 확정 시각 (분자 A안 필터의 SoT)
     *   finance_note         — 은행 거래 번호 또는 처리 메모 (선택)
     *
     * Backfill 옵션 α — 기존 row 중 transfer_id IS NULL (직접 입력된 영업 잔금) 만
     * `confirmed_at = created_at` 으로 채워 운영 중단 방지. transfer_id 있는 row 는
     * 이미 InterVehicleTransfer.confirmed_at 으로 추적되므로 별도 backfill 불필요.
     */
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->after('transfer_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_user_id');
            $table->text('finance_note')->nullable()->after('confirmed_at');

            $table->index(['vehicle_id', 'confirmed_at'], 'idx_final_payments_confirmed');
        });

        DB::table('final_payments')
            ->whereNull('transfer_id')
            ->update(['confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropIndex('idx_final_payments_confirmed');
            $table->dropForeign(['confirmed_by_user_id']);
            $table->dropColumn(['confirmed_by_user_id', 'confirmed_at', 'finance_note']);
        });
    }
};
