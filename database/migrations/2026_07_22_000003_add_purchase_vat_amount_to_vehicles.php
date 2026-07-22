<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * karaba 매입세액(VAT) 입력칸 (Phase 3, 2026-07-22).
 *   purchase_vat_amount = 세금계산서 세액 수기 입력. karaba 이익율 정산의 영업이익 계산에 사용.
 *   영업이익 = 판매가 − (구매가 + 부대비용 − 매입세액). 미입력 시 정산 부정확 → 정산 확정 가드로 강제.
 *   공통 스키마(nullable). heyman/ssancar 는 미사용(그들 정산은 별도 총마진 공식). 3-DB 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->bigInteger('purchase_vat_amount')->nullable()->after('parts_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('purchase_vat_amount');
        });
    }
};
