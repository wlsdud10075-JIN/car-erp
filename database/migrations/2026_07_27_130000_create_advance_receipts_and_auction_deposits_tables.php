<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 예치·가수금 (jin 2026-07-27, 안건4 1단계).
 *
 * - advance_receipts(가수금): 대표·관계사가 회사에 넣은 돈. 상호명·담당자·금액.
 * - auction_deposits(경매보증금): 경매장에 예치한 돈. 업체·금액.
 *   회수하면 행을 삭제한다 → **목록에 남은 합계 = 지금 묶여 있는 돈**(jin: 반환일 칸보다 단순).
 *   단 softDelete 라 DB 에는 남는다(3단계 월보고에서 기간별 예치/회수가 필요해지면 그때 사용).
 *
 * 2단계(CapitalStatusService 반영)·3단계(월보고)는 별도. 여기선 입력·목록용 테이블만.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_receipts', function (Blueprint $table) {
            $table->id();
            $table->date('received_date');                    // 입금일
            $table->string('company_name', 100);              // 상호명
            $table->string('person_name', 50)->nullable();    // 담당자
            $table->decimal('amount', 15, 2);                 // 금액(원)
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('received_date');
        });

        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->id();
            $table->date('deposited_date');                   // 예치일
            $table->string('auction_house', 100);             // 경매장(업체)
            $table->decimal('amount', 15, 2);                 // 금액(원)
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('deposited_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_deposits');
        Schema::dropIfExists('advance_receipts');
    }
};
