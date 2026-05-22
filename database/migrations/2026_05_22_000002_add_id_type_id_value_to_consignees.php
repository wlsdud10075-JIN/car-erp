<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #4 (2026-05-22) — Consignee ID 2컬럼 추가 (사용자 결정 A 패턴).
 *
 * 사용자 명세 (2026-05-21):
 *   "컨사이니 추가의 항목에는 [이름], [ID]-(주민번호, 여권번호, 사업자번호 등),
 *    [주소], [전화번호], [이메일] 이 있고..."
 *
 * 패턴 A (사용자 결정):
 *   - id_type: 'rrn'(주민) / 'passport'(여권) / 'business'(사업자)
 *   - id_value: 실제 번호 (마스킹 동적 — 추후 UI 처리)
 *
 * enum 대신 string 사용 사유 (CLAUDE.md SKILLS.md §4):
 *   - SQLite 호환성 (운영 MariaDB + 테스트 SQLite)
 *   - 신규 ID 종류 추가 시 마이그 부담 (외국인등록번호·법인등록번호 등)
 *   - application validation 으로 enum 효과 (Consignee 모델 ID_TYPES 상수)
 *
 * 보안:
 *   - 평문 저장 (차량 owner RRN 의 nice_reg_owner_rrn 암호화 패턴과 다름 — 컨사이니는 외국인 여권·사업자 번호 포함이라 평문 일관성).
 *   - 추후 사용자가 강 암호화 요구 시 별도 마이그 (cast 변경 + APP_KEY 의존성).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->string('id_type', 20)->nullable()->after('name');
            $table->string('id_value', 50)->nullable()->after('id_type');
        });
    }

    public function down(): void
    {
        Schema::table('consignees', function (Blueprint $table) {
            $table->dropColumn(['id_type', 'id_value']);
        });
    }
};
