<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 20-A — 매입처 계좌 정보 vehicles 직접 추가 (회의록 2026-05-17, B1 채택).
     *
     * 시드 데이터 분석 결과 `purchase_from` 비정형 (경매 낙찰 / 개인 매입 / 법인 / 현대직영).
     * 재사용 패턴 없음 — 마스터 테이블 과설계. B1 vehicles 컬럼 4개로 충분.
     *
     * 신규 컬럼:
     *   purchase_seller_bank    — 매입처 은행명 (예: '국민은행')
     *   purchase_seller_account — 매입처 계좌번호 (Crypt::encryptString — string 500 — 개인정보)
     *   purchase_seller_holder  — 예금주명 (개인/법인 모두)
     *   purchase_bank_memo      — 송금 시 메모 (선택)
     *
     * 마스킹: AuditLog::MASKED_COLUMNS 에 purchase_seller_account 별도 등록 필수.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('purchase_seller_bank', 100)->nullable()->after('purchase_from');
            $table->string('purchase_seller_account', 500)->nullable()->after('purchase_seller_bank');
            $table->string('purchase_seller_holder', 100)->nullable()->after('purchase_seller_account');
            $table->text('purchase_bank_memo')->nullable()->after('purchase_seller_holder');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_seller_bank',
                'purchase_seller_account',
                'purchase_seller_holder',
                'purchase_bank_memo',
            ]);
        });
    }
};
