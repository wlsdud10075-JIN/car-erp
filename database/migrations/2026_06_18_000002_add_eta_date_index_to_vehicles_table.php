<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * alarms:scan 매일잡이 eta_date 범위 스캔(WHERE eta_date <= today+N) 시
 * 전 차량 풀스캔을 방지하기 위한 인덱스. (Ops 조건부 GO 선결조건)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('eta_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['eta_date']);
        });
    }
};
