<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 매입 선지급(보증금 purchase_funding) 링크 컬럼 — final_payments.transfer_id 대칭.
     *
     * 2026-07-21 배포된 매입 선지급은 대상 차량에 PurchaseBalancePayment(원화)를 만드는데,
     * 이체와의 연결이 note 마커 문자열("바이어 보증금 선지급 ← 차량 #N")뿐이라
     * 구조적 보호가 불가능했다(차량 패널 저장이 그냥 수정·삭제). 판매 잔금과 동일하게
     * transfer_id 를 두어 모델 가드(append-only)로 지킬 수 있게 한다.
     *
     * nullable — 일반 매입 잔금은 transfer_id NULL. 기존 행 전부 NULL 이라 INSTANT DDL.
     */
    public function up(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->after('vehicle_id')
                ->constrained('inter_vehicle_transfers')->nullOnDelete();
            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_balance_payments', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
            $table->dropIndex(['transfer_id']);
            $table->dropColumn('transfer_id');
        });
    }
};
