<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 선적·B/L 묶음 v2 — 차량 단위 B/L 방식(이중가드 관리 확인값).
 *
 * 이중가드 = shipping_requests.bl_type(영업 요청) vs vehicles.bl_type(관리가 B/L 문서 업로드 전 확인) 비교.
 * 묶음 「B/L 발급」 bulk-apply 시 묶음 bl_type 이 멤버 차량 전체에 기입됨.
 * nullable → INSTANT DDL 무중단. progress cascade(bl_document)와 무관.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('bl_type', 20)->nullable()->after('bl_document');   // original / surrender
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('bl_type');
        });
    }
};
