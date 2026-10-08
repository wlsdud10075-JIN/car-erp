<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 v3 (jin 2026-10-08) — `salesmen.type` 에 **검차직원(inspector)** 추가.
 *
 * 검차직원은 ERP 에 로그인하지 않고(계정 없음) 사내직원관리에서 **이름만 등록**한다. 차량을 팔지 않으므로
 * 정산·회사 기여가 없고, 월정산에는 급여 지급합계만 올라가 송금 총액에 들어간다(회사 순이익에서 「공통 인건비」).
 *
 * 🚨 enum 은 코드 상수(Salesman::TYPES)와 **같은 커밋**에서 늘린다(§8 #36) — SQLite 는 enum 을 강제하지 않아
 *    로컬·CI 는 통과하고 운영 MySQL 만 `1265 Data truncated` 로 죽는다. 가드 = SalesmanTypeEnumTest(정적).
 * ⚠️ MODIFY 는 NOT NULL DEFAULT 를 다시 적어야 한다 — 빼면 기본값이 조용히 사라진다.
 * ⚠️ SQLite 는 enum 을 **CHECK 제약**으로 만든다(Laravel 문법) — 「enum 미강제」가 아니다. 그래서 테스트(SQLite)에서도
 *    'inspector' 를 넣으려면 컬럼을 다시 정의해야 한다(`->change()` = 테이블 재생성). 실측 2026-10-09:
 *    `CHECK constraint failed: type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance', 'inspector') NOT NULL DEFAULT 'employee'");

            return;
        }
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance', 'inspector'])->default('employee')->change();
        });
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('salesmen')->where('type', 'inspector')->update(['type' => 'employee']);
            DB::statement("ALTER TABLE salesmen MODIFY COLUMN type ENUM('employee', 'freelance') NOT NULL DEFAULT 'employee'");

            return;
        }
        DB::table('salesmen')->where('type', 'inspector')->update(['type' => 'employee']);
        Schema::table('salesmen', function (Blueprint $table) {
            $table->enum('type', ['employee', 'freelance'])->default('employee')->change();
        });
    }
};
