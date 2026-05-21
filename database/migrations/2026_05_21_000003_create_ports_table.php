<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21 — 항구 마스터 테이블 (CIPL 드롭다운 이식).
 *
 * 출처: 사용자 운영 엑셀 # 26. 수출차량현황표.xlsm 의 RO_CIPL/con_CIPL 시트 드롭다운 옵션.
 * 사용처:
 *   - 차량 편집 폼: port_of_loading (loading), bl_loading_location (unloading), discharge_port_id (discharge)
 *   - VehicleCiplGenerator: CIPL Excel 생성 시 셀에 채워짐
 *   - /admin/ports CRUD: 운영 중 새 항구 추가 가능 (Phase C)
 *
 * type:
 *   - loading:   Port of Loading (출발항 — INCHOEN, BUSAN 등 4개)
 *   - unloading: 반입지 (한국 부두 — ICT, SNCT, 평택항 등 17개)
 *   - discharge: Discharge Port (목적항 — VLADIVOSTOK·RUSSIA 등 13개)
 *
 * code: 부두 코드 (괄호 안 번호). 없는 항구도 있어서 nullable.
 * unique(name, type): 같은 이름의 다른 type 은 허용 (이론상). 같은 type 내 중복 방지.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['loading', 'unloading', 'discharge']);
            $table->string('name', 100);
            $table->string('code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'type']);
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ports');
    }
};
