<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 내수직원 평가수수료 표 (jin 2026-11-03) — 「평가수수료정산」 엑셀 딜러수수료 탭의 평가사 한 줄을 그대로 보관한다.
 *
 * 항목 이름(헤더)도 같이 저장한다 — 엑셀 헤더가 달마다 바뀌어도 그 달 표는 그 달 이름으로 보인다.
 * 송금액(`payout` = 총 평가사수수료)은 같은 달 PayrollEntry 한 줄로도 들어가 월정산 합계·송금 총액은 기존 경로를 탄다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domestic_fee_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->char('month', 7);              // YYYY-MM (귀속월)
            $table->json('headers');               // ['컨설팅비', '회사컨설팅비', …] — 이름 열(평가사) 제외
            $table->json('values');                // 같은 순서의 금액·건수
            $table->bigInteger('payout');          // 총 평가사수수료 = 송금액
            $table->string('source_file', 200)->nullable();
            $table->timestamps();
            $table->unique(['salesman_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domestic_fee_statements');
    }
};
