<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 선적·B/L 묶음 v2 (2026-06-30 회의 조건부 GO) — shipping_requests 묶음을 B/L 단계까지 확장.
 *
 * 권위 스펙 = docs/integration/board-portal-api.md §5-0. 회의록 = docs/meetings/2026-06-30-bl-shipment-bundle-v2.md.
 * - 1 묶음(batch_id) = 1 선적 = 1 B/L = 1 오리지널/써랜더. 묶음은 batch_id 로 영속.
 * - bl_type/bl_status = 묶음 단위 B/L 의도(영업 요청). B/L 실데이터는 vehicles(progress cascade per-vehicle).
 * - 전부 nullable/default → MySQL 8 INSTANT DDL 무중단. 기존 행 backfill 불필요.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            // 묶음 단위 B/L 방식 (영업 요청값) — 관리가 vehicle.bl_type 으로 확인(이중가드)
            $table->string('bl_type', 20)->nullable()->after('shipping_method');        // original / surrender
            // B/L 단계 상태 — 선적단계(status)와 별개. none → requested(B/L요청) → issued(발급)
            $table->string('bl_status', 20)->default('none')->after('status');
            // in_progress 차 변경요청 (영업 명시 액션 → 관리 수락/거절)
            $table->timestamp('change_requested_at')->nullable()->after('processed_at');
            $table->json('change_request_meta')->nullable()->after('change_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            $table->dropColumn(['bl_type', 'bl_status', 'change_requested_at', 'change_request_meta']);
        });
    }
};
