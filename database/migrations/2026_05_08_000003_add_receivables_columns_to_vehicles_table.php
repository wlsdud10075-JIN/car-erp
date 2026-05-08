<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 채권 담당자 (users FK, nullable — 미지정 시 영업담당자가 회수 책임)
            $table->foreignId('receivable_manager_id')
                ->nullable()
                ->after('salesman_id')
                ->constrained('users')
                ->nullOnDelete();

            // 위험도 캐시 — safe / caution / danger / critical / none
            // saving 이벤트에서 자동 갱신. 채권 목록 SQL 필터링용.
            $table->string('receivable_risk', 10)
                ->nullable()
                ->after('progress_status_cache');

            // 미납액(원화 환산) 캐시 — KPI 합산 + 정렬용
            // currency != KRW일 때 exchange_rate로 환산. progress_status 캐시 갱신과 동시 갱신.
            $table->bigInteger('sale_unpaid_amount_krw_cache')
                ->default(0)
                ->after('receivable_risk');

            // 카풀/헤이맨 계산서 (1·2차) — 해당 채널만 사용. 그 외 채널은 NULL 유지.
            $table->date('tax_invoice_1_date')->nullable();
            $table->bigInteger('tax_invoice_1_amount')->nullable();
            $table->date('tax_invoice_2_date')->nullable();
            $table->bigInteger('tax_invoice_2_amount')->nullable();

            // 카풀 대행수수료 (헤이맨/수출 미사용)
            $table->bigInteger('agency_fee')->nullable();

            $table->index('receivable_risk');
            $table->index('sale_unpaid_amount_krw_cache');
            $table->index('receivable_manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['receivable_manager_id']);
            $table->dropIndex(['receivable_manager_id']);
            $table->dropIndex(['receivable_risk']);
            $table->dropIndex(['sale_unpaid_amount_krw_cache']);

            $table->dropColumn([
                'receivable_manager_id',
                'receivable_risk',
                'sale_unpaid_amount_krw_cache',
                'tax_invoice_1_date',
                'tax_invoice_1_amount',
                'tax_invoice_2_date',
                'tax_invoice_2_amount',
                'agency_fee',
            ]);
        });
    }
};
