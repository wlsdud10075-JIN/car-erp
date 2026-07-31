<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 청산가치 정합성 (jin 2026-07-31) — 같은 거래를 두 번 세던 문제를 없앤다.
 *
 * 문제: 재고를 "출고일 없음"으로 잡는데, 출고일은 **위치 이동**이지 소유권 이동이 아니다.
 *   07-29 에 출고일 60건을 소급 입력하자 재고가 17.8억 → 4.97억으로 떨어져
 *   원금대비손익이 +9.85억에서 -5.01억으로 뒤집혔다. 실측하니 그 재고의 92%가 **이미 팔린 차**였다.
 *
 * 정리:
 *   · 재고 = **선적 전**(한국에 남아 있어 되팔 수 있는 차)  ← inventory_krw 의 의미가 바뀐다
 *   · advance_payment_krw = 선적 전인데 **이미 받은 판매대금**. 청산하면 돌려줘야 하므로 차감.
 *     (차 + 받은 돈을 둘 다 자산으로 세는 이중계상을 없애는 항목)
 *   · unsold_inventory_krw = **안 팔린 차**의 매입가. 순자산(굴리는 자금) 계산용 —
 *     팔린 차의 가치는 이미 현금/미수로 옮겨갔으므로 재고로 또 세면 안 된다.
 *
 * ⚠️ 과거 스냅샷은 소급하지 않는다(그 시점 값을 박는 구조). 새 컬럼이 null 이면
 *    derive() 가 옛 방식으로 폴백해 기존 기록이 그대로 읽힌다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->bigInteger('advance_payment_krw')->nullable()->after('inventory_krw')
                ->comment('선적 전 차량이 이미 받은 판매대금(선수금) — 청산가치에서 차감');
            $table->bigInteger('unsold_inventory_krw')->nullable()->after('advance_payment_krw')
                ->comment('안 팔린 차량 매입가 — 순자산 계산용');
        });
    }

    public function down(): void
    {
        Schema::table('cash_snapshots', function (Blueprint $table) {
            $table->dropColumn(['advance_payment_krw', 'unsold_inventory_krw']);
        });
    }
};
