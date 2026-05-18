<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 21 fix — audit_logs.action 컬럼 길이 확장 (20 → 50).
 *
 * 큐 11-4에서 도입된 string(20)이 큐 21 'ledger_field_unlocked' (21자) 차단.
 * 향후 'inter_vehicle_transfer_finance_rejected' (40자) 등 더 긴 라벨 대비.
 *
 * MySQL ALTER COLUMN length 변경 — InnoDB INSTANT 알고리즘 적용 가능 (0초 lock).
 * 인덱스는 (auditable_type, auditable_id, created_at) 등으로 action 별도 인덱스 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 50)->change();
        });
    }

    public function down(): void
    {
        // 다운그레이드: 기존 데이터에 50자 짜리 라벨이 있을 수 있어 잘릴 위험.
        // 운영 사고 가정 시 down 사용 비권장 — git revert로 코드만 되돌리는 게 안전.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 20)->change();
        });
    }
};
