<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 17 — 폐기 컨셉 전체 제거.
     *
     * 운영상 차량 "폐기" 단계가 존재하지 않음 (사용자 정정).
     * vehicles.is_disposed 컬럼 + 진행상태 11단계 중 '폐기' 제거 → 10단계로 정리.
     *
     * 큐 16 채널 단순화 패턴 적용:
     *   - 코드: 분기 제거 (Vehicle 모델 + UI)
     *   - 마이그: drop 후 별도 history 남김
     *   - 테스트: 폐기 시나리오 3건 삭제
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('is_disposed');
        });
    }

    /**
     * Rollback: 컬럼 복원 (default=false). 데이터는 복원 안 됨 (모두 false였음).
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('is_disposed')->default(false)->after('sales_channel');
        });
    }
};
