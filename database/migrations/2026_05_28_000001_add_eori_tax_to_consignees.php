<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 컨사이니 일괄 업로드 deep-interview (2026-05-28) — Q1 결정.
 *
 * 양식(컨사이니_업로드양식.xlsx)에 D:EORI NUMBER, E:TAX NUMBER 컬럼이 있는데
 * 현재 consignees 테이블엔 대응 컬럼이 없음. 컨사이니별 0~1개씩만 부착되는 식별번호라
 * 단순 nullable string 2개로 추가 (별도 identifiers 테이블 도입 안 함).
 *
 * 암호화: EORI/TAX는 평문 (해외 법인의 공개 식별번호. id_value(RRN/여권/사업자)와 정책 분리).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->string('eori_number', 50)->nullable()->after('id_value');
            $table->string('tax_number', 50)->nullable()->after('eori_number');
        });
    }

    public function down(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->dropColumn(['eori_number', 'tax_number']);
        });
    }
};
