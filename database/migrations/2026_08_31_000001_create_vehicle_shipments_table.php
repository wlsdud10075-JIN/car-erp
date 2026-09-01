<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 서류 발송(우체국 EMS · DHL) 이력 — 1행 = 「차량 × 발송」 (jin 2026-08-31).
 *
 * ## 왜 컬럼이 아니라 테이블인가
 * 실무 관리표(`## NEW_2026 DHL & 우체국EMS 발송 내역.xlsx`) 실측:
 *   - 한 발송이 **최대 42대**를 덮는다 (EMS 316발송/896대 · DHL 1,023발송/3,140대) → 금액은 N/1 분배
 *   - **같은 차가 2~3번 발송**되는 일이 있다 (DHL 73대 = 2.3%). 차량에 칸 1개씩만 두면
 *     나중 발송이 앞 발송을 덮어 **연 1,322,624원(DHL 배정액의 2.04%)이 아무에게도 청구되지 않는다.**
 *     예외도 경고도 없이 조용히 사라지는 부류라 행으로 보관한다(jin 확정).
 *   - EMS 는 재발송 0건이었지만 같은 구조로 둔다(둘을 다르게 만들 이유가 없다).
 *
 * ## 금액의 뜻
 * `fee` = 그 차량에 배정된 몫(원). 발송 총액이 아니다 — 일괄 기입이 총액을 N 으로 나눠 넣는다.
 * 0 원 허용 = **회사 부담**(번호는 남겨 바이어가 조회할 수 있게 하되 담당자 정산에서는 안 뺀다).
 * 정산 반영 = 실지급액에서 **전액** 차감(프리랜서·사내직원 동일, jin 2026-08-31).
 *
 * 🚫 `carrier` 를 enum 으로 만들지 않는다 — 운송사는 늘어날 수 있고, enum 에 값을 더하는 변경은
 *    SQLite 테스트를 100% 통과하고 운영 MySQL 에서만 `1265 Data truncated` 로 죽는다(SKILLS §8 #36).
 *    허용값 단일 출처 = `VehicleShipment::CARRIERS`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('carrier', 10);                        // ems | dhl
            $table->string('tracking_no', 40)->nullable();        // 등기번호 / 운송장번호
            $table->unsignedInteger('fee')->default(0);           // 이 차량 몫 (원)
            $table->date('sent_date')->nullable();                // 접수일
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'carrier']);
            $table->index(['carrier', 'tracking_no']);            // 일괄 기입의 replace 키
            $table->index('sent_date');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // 목록 정렬·검색·필터·포털 전송용 캐시. 돈의 원본은 vehicle_shipments 행이다.
            //   VehicleShipment 모델 이벤트가 단일 경로로 갱신하고, 야간 vehicles:rebuild-caches 가 자가복구.
            $table->string('ems_tracking_no_cache', 40)->nullable();
            $table->string('dhl_tracking_no_cache', 40)->nullable();
            $table->unsignedInteger('shipping_fee_total_cache')->default(0);
            $table->date('shipping_sent_date_cache')->nullable();

            $table->index('ems_tracking_no_cache');
            $table->index('dhl_tracking_no_cache');
            $table->index('shipping_fee_total_cache');
            $table->index('shipping_sent_date_cache');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['ems_tracking_no_cache']);
            $table->dropIndex(['dhl_tracking_no_cache']);
            $table->dropIndex(['shipping_fee_total_cache']);
            $table->dropIndex(['shipping_sent_date_cache']);
            $table->dropColumn([
                'ems_tracking_no_cache', 'dhl_tracking_no_cache',
                'shipping_fee_total_cache', 'shipping_sent_date_cache',
            ]);
        });
        Schema::dropIfExists('vehicle_shipments');
    }
};
