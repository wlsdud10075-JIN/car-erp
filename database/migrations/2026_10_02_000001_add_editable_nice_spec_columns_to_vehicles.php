<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 기본정보 NICE 칸 교체 (jin 2026-10-02 «필요없는 건 빼고, 필요한 건 넣자 — 수정할 수 있어야 해»).
 *
 * NICE 가 100% 주고 통관 SET·말소증이 이미 찍는데 `nice_raw` JSON 에만 있어 화면에서 못 고치던 값 5개에
 * 전용 컬럼을 준다(기통수는 2026-05-24 의 `nice_spec_cylinders` 를 그대로 쓴다).
 *   제원관리번호 resSpecControlNo · 형식 fomNm · 최대출력 maxPower · 검사 시작/종료 resValidPeriod
 * 서류 매핑은 「컬럼 우선 · raw 폴백」(DocValue) — 기존 차량은 `vehicles:sync-nice-spec-columns` 로 채운다.
 * 죽은 칸(색상·변속기·구동방식·축거)은 **컬럼을 지우지 않고** 화면에서만 뺀다(heymanerp 수기값 5건 보존).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('nice_spec_control_no', 40)->nullable()->after('nice_spec_cylinders');   // 제원관리번호 "A041-00012-0000-1219"
            $table->string('nice_spec_form_name', 40)->nullable()->after('nice_spec_control_no');  // 형식 "I3W13-5D"
            $table->string('nice_spec_max_power', 30)->nullable()->after('nice_spec_form_name');   // 최대출력 "152/5500" (서류가 원문 그대로 찍는다)
            $table->date('nice_inspection_start')->nullable()->after('nice_spec_max_power');       // 검사 유효기간 시작
            $table->date('nice_inspection_end')->nullable()->after('nice_inspection_start');       // 검사 유효기간 종료
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['nice_spec_control_no', 'nice_spec_form_name', 'nice_spec_max_power', 'nice_inspection_start', 'nice_inspection_end']);
        });
    }
};
