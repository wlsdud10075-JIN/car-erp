<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 보증금 적용(deposit_apply) — 차량간 이체의 변형 (jin 2026-07-20).
 *   kind: standard(기존 요청→관리→재무) / deposit_apply([관리]·업무관리자 기안→최고관리자 승인=즉시적용).
 *   target_payment_type: 적용된 입금이 타겟 차 계약금(deposit_down)/잔금(balance) 중 무엇으로 꽂히는지.
 * 기존 standard 이체는 kind 기본값으로 무변경.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->string('kind', 20)->default('standard')->after('currency');
            $table->string('target_payment_type', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('inter_vehicle_transfers', function (Blueprint $table) {
            $table->dropColumn(['kind', 'target_payment_type']);
        });
    }
};
