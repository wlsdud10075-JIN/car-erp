<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 월정산 빈틈 메우기 (jin 2026-10-21)
 *
 * ① settlements.payout_submitted_krw — 월정산 제출 때의 실지급액 박제. 결재 중에 판매가 등이 바뀌면
 *    「제출 때 ↔ 지금」을 비교해 바뀐 정산을 띄운다(ssancarerp 196다2669: 제출 20만 → 승인 10만, 표시 없음).
 * ② salesmen.pension_split_enabled — 퇴직연금 1/12 전환 대상(사람별 토글). 켜면 (급여 + 정산금 + 헤이맨 정산금 + 조정) ÷ 12 를
 *    퇴직연금으로 돌리고 나머지를 송금한다. 급여대장 판매수당 대조도 이 값으로 한다.
 * ③ external_settlement_pays — 다른 회사(heymanerp)에서 정산된 금액을 이 회사 급여에 얹어 주는 몫. 귀속월·사람별 수기 입력.
 *    송금엔 들어가고 회사이익에선 뺀다(마진은 그 회사에 있다). 나중에 연동이 들어오면 같은 행을 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->bigInteger('payout_submitted_krw')->nullable()->after('payout_batch_id');
        });

        Schema::table('salesmen', function (Blueprint $table) {
            $table->boolean('pension_split_enabled')->default(false)->after('per_unit_tier_enabled');
        });

        Schema::create('external_settlement_pays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->char('month', 7);                        // 귀속월 'YYYY-MM'
            $table->string('source', 20)->default('heyman'); // 어느 회사에서 정산됐나
            $table->bigInteger('amount')->default(0);        // KRW
            $table->string('note', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['salesman_id', 'month', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_settlement_pays');
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('pension_split_enabled');
        });
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('payout_submitted_krw');
        });
    }
};
