<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * karaba 매입탭 2단 캐스케이드 (Phase 1, 2026-07-22).
 *   - purchase_registration_type = 1단 매입등록 (일반/의제/혼합/리스캐피탈/구매대행/선적대행)
 *   - purchase_evidence_subtype  = 2단 증빙유형 (1단에 따라 캐스케이드)
 *   - is_dealer_purchase         = 매매상 체크박스 (잔금 10일 알림 트리거를 여기로 이관)
 * 기존 flat purchase_evidence_type/purchase_partner_type 컬럼은 존치(데이터 안전, karaba 소량).
 * karaba 프로파일 전용 UI. 3-DB(SQLite/MariaDB/MySQL8) 안전 — nullable varchar + boolean default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('purchase_registration_type', 30)->nullable()->after('purchase_partner_type');
            $table->string('purchase_evidence_subtype', 60)->nullable()->after('purchase_registration_type');
            $table->boolean('is_dealer_purchase')->default(false)->after('purchase_evidence_subtype');
        });

        // 기존 매매상 데이터 이관 — 알림 트리거를 is_dealer_purchase 로 옮겨도 끊기지 않게.
        DB::table('vehicles')->where('purchase_partner_type', '매매상')->update(['is_dealer_purchase' => true]);
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['purchase_registration_type', 'purchase_evidence_subtype', 'is_dealer_purchase']);
        });
    }
};
