<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 안건 1 (2026-05-21) — vehicles.progress_status_rule_version default 4.
 *
 * 사용자 결정 2026-05-21: 워크플로우 순서 변경.
 *   v3: 통관 → 선적 → B/L → 거래완료
 *   v4: 반입(선적) → 통관 → B/L → 거래완료
 *
 * v4 cascade 5단계 (우선순위 높→낮):
 *   1. bl_document 단독                                    → 거래완료
 *   2. bl_document AND is_export_cleared                   → 통관완료 (실질 도달 불가 — #1 우선)
 *   3. is_export_cleared AND bl_loading_location           → 통관중
 *   4. bl_loading_location AND export_declaration_document → 선적완료
 *   5. bl_loading_location                                 → 선적중
 *
 * 단계명 변경: 수출통관중/완료 → 통관중/완료. '선적'의 의미 = 반입(bl_loading_location).
 *
 * 핵심 정책 (v3 마이그와 동일):
 *   1. 신규 row default = 4 (column default 변경).
 *   2. 기존 v2/v3 row 자동 강등 X — DEFAULT 변경은 INSERT 시점만 적용.
 *   3. 사용자 환경 운영 데이터 0 (테스트 시드 비움) — backfill 불필요.
 *
 * down: default 를 3 으로 복원.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_status_rule_version')->default(4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_status_rule_version')->default(3)->change();
        });
    }
};
