<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 직원 명부 확장 (jin 2026-10-14 «직원들 월급 쓰고 하면 여기에 인원을 솔팅 할 수 있어야») — `salesmen.type` 에 **직원(staff)** 추가.
 *
 * 영업이 아닌 일반사용자(관리·수출통관·재무)도 사내직원관리에 올라와 급여 항목을 적는다. 차량을 팔지 않으므로
 * 검차직원과 같은 취급 — 정산·회사 기여 없음, 월정산엔 급여 지급합계만(공통 인건비). `Salesman::sales()` 가 거른다.
 *
 * 🚨 enum 은 코드 상수(Salesman::TYPES)와 **같은 커밋**에서 늘린다(§8 #36) — 가드 SalesmanTypeEnumTest(정적).
 * ⚠️ MODIFY 는 NOT NULL DEFAULT 를 다시 적어야 한다. ⚠️ SQLite 는 CHECK 제약이라 `change()`(테이블 재생성)가 필요하다(§8 #114).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance', 'inspector', 'staff') NOT NULL DEFAULT 'employee'");

            return;
        }
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance', 'inspector', 'staff'])->default('employee')->change();
        });
    }

    public function down(): void
    {
        DB::table('salesmen')->where('type', 'staff')->update(['type' => 'employee']);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance', 'inspector') NOT NULL DEFAULT 'employee'");

            return;
        }
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance', 'inspector'])->default('employee')->change();
        });
    }
};
