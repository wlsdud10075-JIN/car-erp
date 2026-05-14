<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 16 — 채널 단순화 (회의록 v5.1 §9-6).
     *
     * 1. 모든 vehicles.sales_channel 을 'export'로 통일 (DB::table 사용 → 모델 이벤트 미발동, audit_logs 비스팸).
     * 2. 카풀/헤이맨 전용 5컬럼 drop.
     * 3. sales_channel enum을 'export' 단일값으로 축소.
     *
     * 데이터 손실 안내: 운영 더미 DB 기준 heyman 5건, carpul 3건, tax_invoice/agency 1건.
     * 모두 더미라 손실 무방 (사용자 명시: "DB 더미 → 시드 재생성"). 안전망: storage/backups/db/pre-q16-channel-simplify.sql.
     *
     * Rollback 불가 — down()은 enum/컬럼 복구만 가능, 데이터는 복구 불가.
     */
    public function up(): void
    {
        // (1) 비-export → export. updated_at 무변경 (시드 시뮬이라 timestamp 보존).
        DB::table('vehicles')
            ->where('sales_channel', '!=', 'export')
            ->update(['sales_channel' => 'export']);

        // (2) 5컬럼 drop. (인덱스 별도 없음 — index 정의는 receivables 컬럼에만)
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'tax_invoice_1_date',
                'tax_invoice_1_amount',
                'tax_invoice_2_date',
                'tax_invoice_2_amount',
                'agency_fee',
            ]);
        });

        // (3) enum 축소. MySQL/MariaDB 양쪽 호환되는 raw SQL.
        DB::statement("ALTER TABLE vehicles MODIFY COLUMN sales_channel ENUM('export') NOT NULL DEFAULT 'export'");
    }

    public function down(): void
    {
        // enum 복원 (heyman/carpul 추가)
        DB::statement("ALTER TABLE vehicles MODIFY COLUMN sales_channel ENUM('export','heyman','carpul') NOT NULL DEFAULT 'export'");

        // 컬럼 복원 (데이터는 NULL — 복구 불가)
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('tax_invoice_1_date')->nullable();
            $table->bigInteger('tax_invoice_1_amount')->nullable();
            $table->date('tax_invoice_2_date')->nullable();
            $table->bigInteger('tax_invoice_2_amount')->nullable();
            $table->bigInteger('agency_fee')->nullable();
        });
    }
};
