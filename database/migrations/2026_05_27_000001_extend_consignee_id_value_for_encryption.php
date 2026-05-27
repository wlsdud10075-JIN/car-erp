<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * claudefinalreview 3-3 — consignees.id_value 암호화 대비 컬럼 확장.
 *
 * id_type='rrn'(주민번호) 등 개인정보가 평문 저장되던 것을 Consignee 모델
 * `'id_value' => 'encrypted'` cast 로 전환한다. 암호문은 평문보다 훨씬 길어
 * string(50)→(500) 으로 확장 (차량 RRN nice_reg_owner_rrn 과 동일 패턴).
 *
 * 운영 데이터 없음(미사용) — 평문 legacy row scrub 불필요.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->string('id_value', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->string('id_value', 50)->nullable()->change();
        });
    }
};
