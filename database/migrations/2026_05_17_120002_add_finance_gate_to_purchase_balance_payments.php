<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 20-A — 매입 잔금 재무 확정 게이트 (회의록 2026-05-17, P2 채택).
     *
     * 신규 컬럼:
     *   confirmed_by_user_id — 재무 확정 사용자 (settlement role)
     *   confirmed_at         — 재무 확정 시각 (분자 A안 필터의 SoT)
     *   finance_note         — 송금 영수증 번호 또는 처리 메모 (선택)
     *
     * Backfill 옵션 α — 매입 잔금은 transfer_id 컬럼 없음. 기존 row 전체를
     * `confirmed_at = created_at` 으로 채워 운영 중단 방지.
     */
    public function up(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->after('note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_user_id');
            $table->text('finance_note')->nullable()->after('confirmed_at');

            $table->index(['vehicle_id', 'confirmed_at'], 'idx_pbp_confirmed');
        });

        DB::table('purchase_balance_payments')
            ->update(['confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->dropIndex('idx_pbp_confirmed');
            $table->dropForeign(['confirmed_by_user_id']);
            $table->dropColumn(['confirmed_by_user_id', 'confirmed_at', 'finance_note']);
        });
    }
};
