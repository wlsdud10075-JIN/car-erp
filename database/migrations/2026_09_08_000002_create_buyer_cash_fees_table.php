<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 현금 수수료 (jin 2026-09-08) — 기획 = `docs/design/buyer-cash-ledger.md`.
 *
 * 왜 필요한가: 바이어가 보낸 돈을 차량 잔금에 쓰다 보면, **한참 뒤에 송금 수수료가 잡혀**
 * 실제 들어온 돈이 기재한 금액보다 조금 적었던 것으로 드러난다. 그러면 원장에 **영영 안 없어지는
 * 잔돈**이 남는다(예: 12.35 USD). 그걸 「수수료」로 명시해 0 으로 터는 통로다.
 *
 * 🔑 **설계 = 헤더 1행 + 배분 N행.** 수수료도 입금을 FIFO 로 갉아먹는다는 점에서 판매잔금 배분과
 *    완전히 같다. 그래서 **같은 `buyer_cash_allocations` 를 쓴다** —
 *    입금의 `remaining_amount`(= amount − Σ allocations)와 `balanceFor` 가 **한 글자도 안 바뀌고**
 *    수수료를 반영한다. 뺄셈이 두 곳으로 갈리지 않는다(SKILLS §8 #45).
 *
 * 🚫 **적립금(`savings_statuses`)을 쓰지 않는다** — 다른 원장이고, 재사용 금지가 명시돼 있다.
 * 🚫 **입금 행을 고치거나 지워서 맞추지 않는다** — 감사 기록이 사라지고 배분이 cascade 로 날아간다.
 *
 * 삭제하면 배분이 cascade 로 사라져 **현금이 그대로 돌아온다**(되돌리기 = 헤더 삭제 하나).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_cash_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->date('charged_date');            // 수수료가 잡힌 날 (사람이 고른다)
            $table->decimal('amount', 15, 2);        // 외화 — 입금과 같은 통화
            $table->string('note')->nullable();      // 사유 (예: 중계은행 수수료)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'currency', 'charged_date']);
        });

        Schema::table('buyer_cash_allocations', function (Blueprint $table) {
            $table->foreignId('fee_id')->nullable()->after('final_payment_id')
                ->constrained('buyer_cash_fees')->cascadeOnDelete();
        });

        // 🚨 판매잔금 배분과 수수료 배분을 구분하는 건 **`final_payment_id` / `fee_id` 중 무엇이 찼는가**다.
        //    수수료 행은 잔금도 차량도 없으므로 두 컬럼을 nullable 로 낮춘다.
        //    ⚠️ SQLite 는 컬럼 변경을 테이블 재생성으로 처리하는데, 그때 FK 를 잃는 경우가 있어
        //       `doctrine/dbal` 없이도 안전한 change() 를 쓴다(Laravel 11+ 네이티브).
        Schema::table('buyer_cash_allocations', function (Blueprint $table) {
            $table->foreignId('final_payment_id')->nullable()->change();
            $table->foreignId('vehicle_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('buyer_cash_allocations', function (Blueprint $table) {
            $table->dropForeign(['fee_id']);
            $table->dropColumn('fee_id');
        });
        Schema::dropIfExists('buyer_cash_fees');

        // 낮췄던 NOT NULL 을 되돌린다 — 안 되돌리면 롤백해도 스키마가 어긋난 채 남는다.
        // ⚠️ 수수료 배분이 남아 있으면 여기서 **시끄럽게 실패한다**. 그게 맞다 —
        //    그 행들은 잔금도 차량도 없어서 NOT NULL 로 되돌릴 수가 없다(먼저 지워야 한다).
        Schema::table('buyer_cash_allocations', function (Blueprint $table) {
            $table->foreignId('final_payment_id')->nullable(false)->change();
            $table->foreignId('vehicle_id')->nullable(false)->change();
        });
    }
};
