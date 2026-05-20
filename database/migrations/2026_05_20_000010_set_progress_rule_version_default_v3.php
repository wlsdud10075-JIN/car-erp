<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 안건 J 본격 (2026-05-20) — vehicles.progress_status_rule_version default 3.
 *
 * 회의록 docs/meetings/2026-05-19-group-revenue-progress-redesign.md 3-C NO-GO → 본격.
 * 사용자 결정 2026-05-20: 거래완료 = bl_document 단독 (DHL 무관).
 *
 * 핵심 정책 (advisor 권고 + 안건 J 회의록 line 325 위험 해소):
 *   1. 신규 row default = 3 (column default 변경).
 *   2. 기존 v2 row 자동 강등 X — DEFAULT 변경은 INSERT 시점만 적용, 기존 row 영향 없음.
 *   3. v1 grandfather row 도 자동 강등 X — 같은 이유.
 *   4. progress_status_cache 컬럼 값은 v2/v3 모두 동일 string ('거래완료' 등) 사용 → SQL 호환.
 *
 * down: default 를 2로 복원 (신규 row 만 영향, 기존 v3 row 자동 강등 X).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite / MySQL 양쪽 호환 — change() 로 default 만 변경.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_status_rule_version')->default(3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_status_rule_version')->default(2)->change();
        });
    }
};
