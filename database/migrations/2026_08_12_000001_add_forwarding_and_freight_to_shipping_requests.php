<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 선적 계획 묶음에 **포워딩사**와 **컨테이너 운임비(USD) 총액** — board 인계 2026-08-11 요청 ③④.
 *
 * 둘 다 buyer_id·shipping_method 와 같은 **묶음 속성**이라 같은 자리(멤버 행마다 복제)에 둔다.
 * 차량 원장(`vehicles.forwarding_company_id`·`transport_fee_usd`)에도 반영하지만,
 * 여기 값은 **영업이 요청한 값**이고 저기 값은 **실제 적용된 값**이다 — `bl_type` 이 이미 쓰는
 * 이중 보관과 같은 구조(§5-0). 갈라지면 관리가 "board 가 뭘 골랐는지" 를 볼 수 있어야 하기 때문.
 *
 * ⏱️ 컬럼 추가 2개는 nullable 이라 MySQL 8 INSTANT 지만, **FK 추가는 INSTANT 가 아니다**
 *    (별도 `ADD CONSTRAINT`). `shipping_requests` 는 작아서(3사 다 수백 행) 실제로는 즉시 끝나지만,
 *    "무중단 보장" 으로 읽지 말 것 — 큰 표에 같은 패턴을 복사하면 락이 걸린다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            $table->foreignId('forwarding_company_id')->nullable()->after('consignee_id')
                ->constrained()->nullOnDelete();
            // 묶음 총액(달러 정수). 차량별 1/N 은 저장 시점에 계산해 vehicles.transport_fee_usd 로 간다.
            $table->bigInteger('transport_fee_usd_total')->nullable()->after('shipping_method');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            $table->dropForeign(['forwarding_company_id']);
            $table->dropColumn(['forwarding_company_id', 'transport_fee_usd_total']);
        });
    }
};
