<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 현금 원장 1단계 — 입금 1건(통장에 찍힌 그 건). 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚨 **원화·환율 컬럼이 없는 것은 의도다**(jin 2026-09-04 — "어차피 판매잔금 N에 기입할 때
 *    환율을 넣으니까"). 이 원장은 **외화 풀**이고 원화 환산은 판매잔금 행이 계속 담당한다.
 *    여기에 환율을 넣으면 같은 돈의 원화값이 두 곳에서 나와 정산 환율이 갈린다.
 *
 * 🚫 적립금(`savings_statuses`)과 별개 원장이다 — 적립금은 「회사가 준 크레딧」이고
 *    이건 「실제로 들어온 현금」이다. 섞지 말 것.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->enum('currency', ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'])->default('USD');
            $table->date('received_date');          // 한국에서 받은 날
            $table->decimal('amount', 15, 2);       // 외화 (원화 없음 — 위 주석)
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // 잔액 조회가 항상 (바이어 × 통화) 단위 + 오래된 순(FIFO)이다.
            $table->index(['buyer_id', 'currency', 'received_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_cash_receipts');
    }
};
