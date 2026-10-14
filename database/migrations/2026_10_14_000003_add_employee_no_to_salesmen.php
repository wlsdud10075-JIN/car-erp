<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 급여대장 업로드 매칭 키 (jin 2026-10-14) — 급여대장 A열 「사원번호」를 명부에 저장해 다음 달부터 이름이 달라도 자동 매칭.
 * 🚫 unique 로 두지 않는다 — soft-delete 된 옛 행이 같은 번호의 재등록을 막는다. index 만.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->string('employee_no', 20)->nullable()->after('initials')->index();
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropIndex(['employee_no']);
            $table->dropColumn('employee_no');
        });
    }
};
