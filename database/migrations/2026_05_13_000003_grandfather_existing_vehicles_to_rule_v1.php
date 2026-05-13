<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 큐 2.6 — 3-tier 이관 (Specialist 제안)
        // 본 마이그 시점에 존재하는 모든 차량 row를 v1으로 grandfather하여 retroactive drift 차단.
        // 신규 차량(이 마이그 이후 생성)은 default=2(이중 트리거 강화)로 적용.
        // 이후 별도 backfill 명령(php artisan vehicles:backfill-progress-rule-v2)으로
        // 3-tier에 따라 점진적 v2 전환:
        //   tier 1: settlement.paid 또는 dhl_request=true → v1 고정 (재계산 skip)
        //   tier 2: sale_price>0 + 미마감 → 관리자 수동 검토 큐 (--review)
        //   tier 3: 매입중/매입완료 → 자동 backfill (--auto)
        DB::table('vehicles')->update(['progress_status_rule_version' => 1]);
    }

    public function down(): void
    {
        DB::table('vehicles')->update(['progress_status_rule_version' => 2]);
    }
};
