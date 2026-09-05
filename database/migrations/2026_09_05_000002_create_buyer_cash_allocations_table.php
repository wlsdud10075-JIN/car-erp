<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 현금 원장 1단계 — 배분 1줄 = 「이 입금에서 이 판매잔금으로 얼마」.
 * 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🧭 **왜 `final_payments` 에 칸 하나가 아니라 별도 테이블인가** — 잔금 하나가 여러 입금을
 *    소진하는 경우가 실재한다(3,000 남은 입금 + 새 7,000 입금 → 10,000 잔금). 칸 하나면
 *    그 순간 어느 한쪽이 조용히 사라진다.
 *
 * 🔑 **행으로 저장하는 것이 이 기능의 요점이다.** 적립금은 배분을 DB 에 안 남기고 매 조회마다
 *    원장을 재생해 FIFO 를 계산해서(`App\Services\SavingsLedger`) 「이 입금이 어느 차에 얼마」를
 *    조인·엑셀·포털로 못 뽑는다. 여기선 그 추적이 1순위라 처음부터 행이다.
 *
 * ⚠️ `final_payment_id` cascadeOnDelete = **회수의 구현 자체**다(jin 2026-09-04 "그 잔금 행을 지운다").
 *    잔금을 지우면 배분이 사라지고 → 입금 잔액이 자동 복원되고 → `FinalPayment::deleted` 가
 *    차량 캐시를 되살려 미수도 복원된다. 별도 되돌리기 로직을 만들지 말 것.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_cash_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('buyer_cash_receipts')->cascadeOnDelete();
            $table->foreignId('final_payment_id')->constrained('final_payments')->cascadeOnDelete();
            // 비정규화 — final_payment 로도 도달하지만, 배분 내역 조회가 차량 기준이라 조인을 줄인다.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);   // 외화 (입금과 같은 통화)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('receipt_id');
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_cash_allocations');
    }
};
