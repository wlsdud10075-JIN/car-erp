<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 적립금 거래에 **적립 시점 환율** 기록 (jin 2026-07-29).
 *
 * 적립금은 바이어×통화 크레딧이라 잔액이 외화로만 보였다. jin: "적립이 될 때 그때 판매탭에 환율이
 * 있을 거잖아? 그거에 맞게 적립되면 바이어탭 적립금탭에서 한화로도 표시해줄 수 있지 않나."
 *
 * ⚠️ 환율은 **유입(적립) 시점에 고정**된다. 사용할 때의 환율이 아니다 — 크레딧의 원화 가치는
 *    적립될 때 이미 정해졌기 때문. 그래서 어느 적립분이 나갔는지(선입선출)가 원화 환산을 좌우한다.
 *
 * 백필: `vehicle_id` 가 있는 행은 그 차량의 판매환율로 채운다(적립이 그 차량 판매탭에서 났으므로
 * 그 시점 환율이 맞다). 바이어탭 수기 거래는 차량이 없어 null — 화면에서 "환율 미입력"으로 표시된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->decimal('exchange_rate', 15, 4)->nullable()->after('currency');
        });

        // 차량 연결 행 백필 — SQLite 는 UPDATE ... JOIN 문법이 달라 드라이버 분기.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('
                UPDATE savings_statuses s
                JOIN vehicles v ON v.id = s.vehicle_id
                SET s.exchange_rate = v.exchange_rate
                WHERE s.vehicle_id IS NOT NULL AND v.exchange_rate > 0
            ');
        } else {
            DB::statement('
                UPDATE savings_statuses
                SET exchange_rate = (SELECT v.exchange_rate FROM vehicles v WHERE v.id = savings_statuses.vehicle_id)
                WHERE vehicle_id IS NOT NULL
                  AND (SELECT v.exchange_rate FROM vehicles v WHERE v.id = savings_statuses.vehicle_id) > 0
            ');
        }

        // 원화 적립분은 환율이 자명하다(1).
        DB::table('savings_statuses')->where('currency', 'KRW')->whereNull('exchange_rate')->update(['exchange_rate' => 1]);
    }

    public function down(): void
    {
        Schema::table('savings_statuses', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
