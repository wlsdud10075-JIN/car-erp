<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 B 수신 — board(매입보드)가 push 하는 낙찰차를 받기 위한 컬럼 2개.
 * 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
 *
 *   purchase_source — 매입 출처 (encar | auction). 기존 purchase_from(구입처, 자유서식)과
 *                     별개 — board origin 추적용. 수동 등록 차량은 NULL 정상.
 *   c_no            — 연동 A(respond.io) 조인 thread 키. nullable + index, NON-UNIQUE
 *                     (동일인 보장 못 함 — board §12). 엔카·기타 출처는 NULL 정상.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('purchase_source', 20)->nullable()->after('purchase_from');
            $table->string('c_no')->nullable()->after('purchase_source');
            $table->index('c_no');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['c_no']);
            $table->dropColumn(['purchase_source', 'c_no']);
        });
    }
};
