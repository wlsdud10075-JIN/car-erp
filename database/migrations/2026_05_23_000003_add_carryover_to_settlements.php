<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 새회의.txt 8번 보강 (2026-05-23) — 정산 캐리오버 컬럼 추가.
 *
 * 사용자 명세:
 *   "정산에서는 지급후에 최종정산금액에서 +-가 되었을 때
 *    다음달에 1차정산에 +-로 반영이 될 수 있는지"
 *
 * 사용자 결정 정책 (2026-05-23):
 *   - 그룹 기준: 영업담당자별 이월 (개인 잔액)
 *   - 트리거: secondary_status='closed' 시점
 *   - 음수 이월 허용 (환차손 누적 시 다음 정산에서 차감)
 *
 * 흐름:
 *   - 1차 paid 시점 confirmed_snapshot['actual_payout'] 캡처 (기존)
 *   - 2차 closed 시점 actual_payout 재계산 (cost·환차 모두 반영)
 *   - carryover_out_krw = closed_payout - snapshot_payout (이번 정산이 다음 달로 이월시킬 차이)
 *   - 다음 신규 정산 creating 훅 — 영업담당자별 미적용 이월 합산 → carryover_in_krw set
 *   - actual_payout = base + carryover_in_krw (해당 정산이 흡수한 이월액)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('carryover_in_krw', 15, 2)
                ->nullable()
                ->after('exchange_rate_at_close');
            $table->decimal('carryover_out_krw', 15, 2)
                ->nullable()
                ->after('carryover_in_krw');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['carryover_in_krw', 'carryover_out_krw']);
        });
    }
};
