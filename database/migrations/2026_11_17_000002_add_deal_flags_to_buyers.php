<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏷️ 바이어별 DP·FD 진행 표시 (jin 2026-11-17).
 *
 * 11-01 엔 DP/FD 를 차량(거래)에만 두고 바이어 목록은 「그 유형 차량 대수」만 셌다. 실무 요청 —
 * «계약서 받은 시점부터 지정이니까 차량별로 해야 하고, 해당 바이어도 DP·FD 진행 중인 바이어인지 확인은 되어야».
 * ⇒ 바이어에 DP·FD 를 **각각** 켜는 칸(둘 다 가능). 차량 지정(vehicles.deal_type)은 그대로다 — 둘은 따로 지정한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->boolean('is_dp')->default(false)->after('is_b2b_partner');
            $table->boolean('is_fd')->default(false)->after('is_dp');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn(['is_dp', 'is_fd']);
        });
    }
};
