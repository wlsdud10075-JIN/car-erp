<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 추가 (2026-05-22) — 사용자 결정 #5-1: 바이어에 영업담당자 직접 지정.
 *
 * 배경:
 *   - 기존: buyers ↔ salesman 직접 컬럼 없음. vehicles 통한 간접 관계 (Step 3).
 *   - 사용자 의도 #5-1 (2026-05-22): "바이어에도 영업 담당자를 지정 할 수 있어야 할것같음.
 *     그래야 4번도 가능할것으로 보임" — 직접 관계로 [관리] 솔팅 정확성 ↑
 *
 * 정책:
 *   - nullable: 기존 row 영향 0. 운영자가 UI에서 일괄 입력 필요.
 *   - nullOnDelete: 영업담당자 비활성/삭제 시 buyers 자동 NULL (안전).
 *
 * Step 3 query 영향:
 *   - 옵션 A 채택 (직접만): buyers.salesman_id IN subordinates_salesman_ids
 *   - 기존 vehicles 간접 (whereHas) 제거 — 직접 관계가 단일 출처
 *   - 운영 데이터 마이그 후 [관리] 솔팅 결과 일시 0 가능. UI에서 영업담당자 지정 후 정상화.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->foreignId('salesman_id')
                ->nullable()
                ->after('country_id')
                ->constrained('salesmen')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropForeign(['salesman_id']);
            $table->dropColumn('salesman_id');
        });
    }
};
