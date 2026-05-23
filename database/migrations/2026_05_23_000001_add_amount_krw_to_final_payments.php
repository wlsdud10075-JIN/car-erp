<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #6 보강 (2026-05-23) — final_payments.amount_krw 컬럼 추가.
 *
 * 사용자 명세:
 *   "환산되는 금액이 그냥 표시되는데 저것도 계산되서 나오면 칸이 추가되서 같이 저장되게 해줄 수 있어?
 *    그래야 추후 1차정산·2차정산·환차익 할때 사용자도 볼 수 있지 않아?"
 *
 * 흐름:
 *   - amount × exchange_rate 결과를 row별로 저장 (snapshot)
 *   - FinalPayment::saving 훅에서 amount 또는 exchange_rate 변경 시 자동 재계산
 *   - 정산 화면에서 별도 곱셈 없이 SUM(amount_krw) 사용 가능
 *
 * 컬럼:
 *   - decimal(15, 2) nullable
 *   - nullable: exchange_rate 미입력(기존 row) 시 NULL 유지 — "아직 미설정"과 "0원"을 구분
 *
 * Backfill:
 *   - 기존 row 중 amount > 0 AND exchange_rate IS NOT NULL → amount × exchange_rate 저장
 *   - raw SQL UPDATE 사용 — FinalPayment 모델 saving 훅·confirmed lock 우회
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->decimal('amount_krw', 15, 2)
                ->nullable()
                ->after('exchange_rate');
        });

        DB::statement('UPDATE final_payments SET amount_krw = amount * exchange_rate WHERE exchange_rate IS NOT NULL AND amount > 0');
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropColumn('amount_krw');
        });
    }
};
