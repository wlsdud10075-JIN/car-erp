<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 19-F-A — 자금 이체 재무 확정 게이트 (회의록 2026-05-16).
     *
     * SSANCAR 실무 = 관리(박관리, 의사결정) ≠ 재무(김진영 정산 role, 실물 자금 처리) 분리.
     * 5상태 머신: pending → approved_awaiting_finance(의사결정만) → executed(재무 확정 + ledger) → voided.
     *
     * status 컬럼 길이: string(20) → string(30) ('approved_awaiting_finance' = 25자).
     *
     * 신규 컬럼:
     *   confirmed_by_user_id — 재무 확정 사용자 (settlement role)
     *   confirmed_at         — 실물 자금 처리 시각
     *   finance_note         — 은행 거래 번호 또는 처리 메모 (선택)
     *
     * 기존 executed transfer는 backfill로 confirmed_by_user_id=approver_id, confirmed_at=executed_at.
     * 매우 드물게 남아있을 수 있는 approved 상태 transfer는 의미 보존 위해 approved_awaiting_finance로 전환.
     */
    public function up(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();

            $table->foreignId('confirmed_by_user_id')->nullable()
                ->after('approver_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_user_id');
            $table->text('finance_note')->nullable()->after('confirmed_at');

            $table->index(['status', 'confirmed_at'], 'idx_finance_processing');
        });

        DB::table('inter_vehicle_transfers')
            ->where('status', 'executed')
            ->whereNotNull('approver_id')
            ->update([
                'confirmed_by_user_id' => DB::raw('approver_id'),
                'confirmed_at' => DB::raw('executed_at'),
            ]);

        DB::table('inter_vehicle_transfers')
            ->where('status', 'approved')
            ->update(['status' => 'approved_awaiting_finance']);
    }

    public function down(): void
    {
        DB::table('inter_vehicle_transfers')
            ->where('status', 'approved_awaiting_finance')
            ->update(['status' => 'approved']);

        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->dropIndex('idx_finance_processing');
            $table->dropForeign(['confirmed_by_user_id']);
            $table->dropColumn(['confirmed_by_user_id', 'confirmed_at', 'finance_note']);

            $table->string('status', 20)->default('pending')->change();
        });
    }
};
