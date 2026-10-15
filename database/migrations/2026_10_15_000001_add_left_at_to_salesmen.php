<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🚪 **퇴사일** (jin 2026-10-15 「퇴사자 사용 씬」) — 사내직원관리 편집 패널에서 적는다.
 *
 * 왜 `is_active=false` 재사용이 아니라 별도 칸인가 — 사용자 관리가 저장할 때마다 미러가 `is_active=true` 로 되돌린다
 * (admin/users/index `updateOrCreate`). 전화번호 하나만 고쳐도 퇴사가 풀렸다. 퇴사일이 있으면 미러가 활성을 건드리지 않는다.
 *
 * 쓰임 — ①프리랜서 퇴사자 = 정산관리 「퇴사자」에서 **즉시 정산 배치**(월정산 후보에서 빠진다, 이중 지급 방지).
 *        ②사내직원·급여형 퇴사자 = 종전대로 월정산 10일 지급(jin «사내직원은 똑같이 10일에 지급»), 비활성이어도 마지막 달 급여 줄이 빠지지 않게.
 * NULL = 재직. 되돌리면(비우면) 종전 흐름으로 돌아간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->date('left_at')->nullable()->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropIndex(['left_at']);
            $table->dropColumn('left_at');
        });
    }
};
