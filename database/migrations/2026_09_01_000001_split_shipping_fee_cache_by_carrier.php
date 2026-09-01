<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 발송비 캐시를 **운송사별로** 쪼갠다 (jin 2026-09-01).
 *
 * 목록에서 「우편요금(EMS)」과 「청구금액(DHL)」을 따로 보고, 필터한 것만의 **합계**를
 * 상단 요약(총 차량 · 운임비합 · 판매총액합 옆)에 띄우려면 컬럼이 필요하다 —
 * 합계는 `SUM()` 이고 정렬은 `ORDER BY` 라 둘 다 행이 아니라 컬럼을 본다.
 *
 * 🔑 **셋은 한 함수가 같이 계산한다**(`Vehicle::shippingCacheValues`) — `ems + dhl ≡ total` 이
 *    항상 참이어야 한다. 따로 갱신하면 「합계는 맞는데 줄을 더하면 안 맞는」 화면이 된다(SKILLS §8 #64).
 *    `shipping_fee_total_cache` 는 그대로 **정산이 읽는 단일 출처**로 남는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('ems_fee_total_cache')->default(0);
            $table->unsignedInteger('dhl_fee_total_cache')->default(0);
            $table->index('ems_fee_total_cache');
            $table->index('dhl_fee_total_cache');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['ems_fee_total_cache']);
            $table->dropIndex(['dhl_fee_total_cache']);
            $table->dropColumn(['ems_fee_total_cache', 'dhl_fee_total_cache']);
        });
    }
};
