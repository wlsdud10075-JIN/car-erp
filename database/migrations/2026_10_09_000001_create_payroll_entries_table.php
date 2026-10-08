<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 v3 (jin 2026-10-08) — 사내직원·검차직원 급여 항목, **귀속월별**.
 *
 * 구 `salesmen.base_salary_krw`(한 번 저장해 매달 재사용)를 대체한다 — jin «매번 바뀐다, 재무가 들어와서 기입한다.
 * 전부 빈칸으로 둬». 고정 항목 18개(PayrollEntry::ITEMS, 순서 고정) + 직접 추가 행(is_custom).
 *
 * - 행이 없다 = 미입력. 0 을 저장한 행 = 「없음」 명시. (`base_salary_krw` 의 null/0 구분과 같은 결)
 * - 금액은 **부호 있음** — 전월소급 같은 항목은 음수가 된다(입력칸은 data-money-signed).
 * - 저장은 (salesman_id, month) 단위 **지우고 다시 넣기** 한 트랜잭션 — 직접 추가 행의 라벨은 겹칠 수 있어
 *   라벨 유니크를 두지 않는다. 다른 표가 이 행을 참조하지 않으므로 id 가 바뀌어도 무관.
 * - 🚫 `base_salary_krw` 는 여기서 건드리지 않는다 — 10/10 월배치 「기본급만」 줄이 아직 그 칸을 읽는다.
 *   비우는 것은 v3 배포 뒤(§6 10일차).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->char('month', 7);                       // 귀속월 'YYYY-MM'
            $table->string('label', 40);                    // 항목명 (고정 18 또는 직접 입력)
            $table->bigInteger('amount')->default(0);       // KRW, 부호 있음
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_custom')->default(false);   // true = 직접 추가 행
            $table->timestamps();

            $table->index(['salesman_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
