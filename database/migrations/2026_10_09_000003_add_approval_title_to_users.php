<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 v3 (jin 2026-10-08) — 최고관리자(admin)의 **결재 직급**: 부장 / 전무 / 대표.
 *
 * 월정산 결재선 = 업무관리자 상신 → 부장 → 전무 → 대표. 직급은 사용자관리에서 영업담당자처럼 지정하고,
 * 상신할 때 업무관리자가 각 직급의 결재권자를 고른다(그 직급에 사람이 없으면 건너뜀).
 *
 * 🔑 **null 의 뜻 = 종전 동작.** 직급을 아무도 안 넣은 회사(heymanerp·karabaerp)는 결재선이 「최고관리자 = 최종」
 *    한 칸으로 돌아 지금과 똑같이 동작해야 한다 — 3사 동시 배포라 설정 0 으로도 월정산이 막히면 안 된다.
 * 값은 코드 상수 User::APPROVAL_TITLES 로 검증(enum 아님 — 직급은 늘어난다, §8 #4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_title', 10)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approval_title');
        });
    }
};
