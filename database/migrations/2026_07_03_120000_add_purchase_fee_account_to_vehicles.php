<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 매입 정산계좌 2개 분리 (2026-07-03, board→car-erp 인계 handoff-car-erp-purchase-two-accounts).
     *
     * 기존 매입처 계좌(purchase_seller_*) = **매입가**(차값−할인) 지급 대상 = 판매자 계좌.
     * 신규 매도비 계좌(purchase_fee_*)   = **매도비**(selling_fee) 지급 대상 = 별도 주체.
     * 금액(purchase_price/selling_fee)은 이미 별도 컬럼 — 이번엔 계좌만 같은 축으로 2개로 분리.
     *
     * 신규 컬럼:
     *   purchase_fee_bank    — 매도비 은행명
     *   purchase_fee_account — 매도비 계좌번호 (Crypt encrypted cast — string 500 — 개인정보)
     *   purchase_fee_holder  — 매도비 예금주명
     *
     * 마스킹: AuditLog::MASKED_COLUMNS 에 purchase_fee_account 별도 등록 (purchase_seller_account 와 동일).
     * purchase-sync contract_version 4 에서 selling_fee_payee_* → 위 컬럼으로 매핑.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('purchase_fee_bank', 100)->nullable()->after('purchase_bank_memo');
            $table->string('purchase_fee_account', 500)->nullable()->after('purchase_fee_bank');
            $table->string('purchase_fee_holder', 100)->nullable()->after('purchase_fee_account');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_fee_bank',
                'purchase_fee_account',
                'purchase_fee_holder',
            ]);
        });
    }
};
