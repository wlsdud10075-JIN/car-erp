<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 큐 2.6 — 11단계 분류 규칙 버전 + admin 우회 활성 flag
        // - progress_status_rule_version: 마감 row(settlement=paid 또는 dhl_request=true) 보호.
        //   변경된 분류 규칙(누수 4건 이중 트리거화)이 retroactive으로 적용되지 않도록 grandfather.
        //   v1 = 이전 단일 트리거 / v2 = 이중 트리거 강화 (큐 2.6 이후 신규 차량)
        // - is_override_active: admin이 unpaid_export_overrides에 승인 레코드를 만들면 true.
        //   progress_status 분류 자체는 영향 X (Engineer 원칙). UI/대시보드에서 별도 표시.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_status_rule_version')->default(2)->after('progress_status_cache');
            $table->boolean('is_override_active')->default(false)->after('progress_status_rule_version');

            $table->index('is_override_active');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['is_override_active']);
            $table->dropColumn(['progress_status_rule_version', 'is_override_active']);
        });
    }
};
