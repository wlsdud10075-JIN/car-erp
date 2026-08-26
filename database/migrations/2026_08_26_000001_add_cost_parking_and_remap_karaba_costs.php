<?php

use App\Support\KarabaCostRemap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 주차료(cost_parking) 컬럼 추가 + karaba 비용 칸 재배치 (jin 2026-08-26).
 *
 * 매입탭 비용이 3사 공통 10칸이 된다 — 말소·면허·탁송·캐리·쇼링·보험·이전·**주차료**·기타비1·기타비2.
 * karaba 는 기타비1/2 를 「점검비」/「기타비」로 부른다(Vehicle::costLabel).
 *
 * 이관은 **karaba 에서만** 돈다(KarabaCostRemap 안에서 게이팅). heymanerp 는 cost_extra1 에
 * 진짜 기타비1이 7대·614,818원 들어 있어(2026-08-26 실측) 같이 옮기면 회계가 틀어진다.
 *
 * 🔑 cost_total 에 cost_parking 이 함께 추가되므로 이관 전후 합계가 같다 = 마진·정산 불변.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 기존 비용 컬럼과 동일 타입(unsignedBigInteger, default 0).
            $table->unsignedBigInteger('cost_parking')->default(0)->after('cost_transfer');
        });

        KarabaCostRemap::run();
    }

    public function down(): void
    {
        // ⚠️ 이관은 되돌리지 않는다 — 컬럼을 지우면 karaba 의 주차료 금액이 사라진다.
        //    롤백이 필요하면 audit_logs 의 cost_parking / cost_extra1 / cost_inspection 기록을 볼 것.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('cost_parking');
        });
    }
};
