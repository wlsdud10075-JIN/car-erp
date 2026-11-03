<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 내수직원 (jin 2026-11-03 «검차직원과 같아») — `salesmen.type` 에 **domestic_staff** 추가(목록 끝).
 *
 * 국내 평가사. 차량을 팔지 않아 검차직원과 같은 취급 — 정산·회사 기여 없음, 월정산엔 총 평가사수수료만(공통 인건비).
 *
 * 🚨 enum 은 코드 상수(Salesman::TYPES)와 **같은 커밋**에서 늘린다(§8 #36) — 가드 SalesmanTypeEnumTest(정적, 순서까지).
 * ⚠️ MODIFY 는 NOT NULL DEFAULT 를 다시 적어야 한다. ⚠️ SQLite 는 CHECK 제약이라 `change()`(테이블 재생성)가 필요하다(§8 #114).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance', 'inspector', 'staff', 'domestic_staff') NOT NULL DEFAULT 'employee'");

            return;
        }
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance', 'inspector', 'staff', 'domestic_staff'])->default('employee')->change();
        });
    }

    public function down(): void
    {
        DB::table('salesmen')->where('type', 'domestic_staff')->update(['type' => 'employee']);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance', 'inspector', 'staff') NOT NULL DEFAULT 'employee'");

            return;
        }
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance', 'inspector', 'staff'])->default('employee')->change();
        });
    }
};
