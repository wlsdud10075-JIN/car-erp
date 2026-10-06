<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💱 **2차 마감 차액의 분해를 박제** — 환차분 / 비용(2차 차액)분 (jin 2026-10-06).
 *
 * jin: *「1차 정산에서 실지급액 준 거 대비 +,- 가 되어서 차액이 표시되는 행이 보여지면 좋겠고,
 *      결국은 환차, 2차 차액, 이월금액(최종) 이렇게 되는 그림」*.
 *
 * 마감 전에는 지급 스냅샷 대비 **지금 값**으로 미리 계산해 보여 주지만(「예상」), 마감하면 그 순간 값으로
 * 확정돼야 한다 — 미수가 남은 채 마감한 차에 뒤늦게 돈이 들어오면 환율이 움직여 실시간 계산은 변한다.
 * `carryover_out_krw`(합계)는 이미 마감 때 저장되고 있었고, 그 분해 둘을 함께 박는다.
 *   기타(서류비·발송비·기타공제 변동) = carryover_out − fx − cost 로 유도(저장 안 함).
 * NULL = 분해 없음(이 컬럼 이전에 마감된 행 — 합계만 보인다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('secondary_fx_krw', 15, 2)->nullable()->after('carryover_out_krw');
            $table->decimal('secondary_cost_krw', 15, 2)->nullable()->after('secondary_fx_krw');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['secondary_fx_krw', 'secondary_cost_krw']);
        });
    }
};
