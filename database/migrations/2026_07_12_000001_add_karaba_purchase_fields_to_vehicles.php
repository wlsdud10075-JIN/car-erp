<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * karaba 매입탭 커스터마이징 — 매입증빙 / 거래처구분 (2026-07-12).
 *
 * - purchase_evidence_type: 매입증빙 (자유입력). 엑셀 "계산서 구분" 대응이나 값 고정 안 함.
 * - purchase_partner_type : 거래처구분 (드롭박스). 매매상/경매장/헤이딜러/수출회사/딜러.
 *   ⚠️ 매매상 10일 잔금 알림이 '매매상' 값을 감지 → 고정값 필수.
 *
 * 공통 스키마(3사 공유)이나 UI 노출은 karaba 프로파일에서만(Setting::isKaraba()).
 * nullable string — SQLite/MariaDB/MySQL8 3-DB 공통 안전. karaba 신규라 데이터 손실 없음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('purchase_evidence_type', 100)->nullable()->after('purchase_from');
            $table->string('purchase_partner_type', 30)->nullable()->after('purchase_evidence_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['purchase_evidence_type', 'purchase_partner_type']);
        });
    }
};
