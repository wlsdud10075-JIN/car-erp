<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매입 지급(계약금·매입잔금) 이체확인증 — 판매 잔금 전시문(`final_payments.proof_path`)의 매입 쪽 쌍둥이 (jin 2026-10-23).
 *
 * 딜러 알림톡 📨 모달에서 스크린샷을 붙여넣거나 파일을 고르면 그 지급 행에 붙고,
 * 알림톡 「이체확인증 보기」 버튼(만료 서명 링크)이 이 파일을 연다. 회계 잠금 대상 아님.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->string('proof_path', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->dropColumn('proof_path');
        });
    }
};
