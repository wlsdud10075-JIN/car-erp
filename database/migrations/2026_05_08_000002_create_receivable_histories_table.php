<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // final_payments와의 양방향 미러링 링크
            // method=deposit으로 회수 이력 추가 시 자동 생성된 final_payment의 ID
            // final_payment 삭제 시 → 이 컬럼은 nullOnDelete (회수 이력은 활동 기록으로 보존)
            $table->foreignId('final_payment_id')->nullable()->constrained('final_payments')->nullOnDelete();

            $table->date('collected_at');
            $table->foreignId('collector_id')->constrained('users')->restrictOnDelete();

            // 회수 방법: deposit(입금) / cash(현금) / offset(상계) / other(기타)
            $table->enum('method', ['deposit', 'cash', 'offset', 'other']);

            // 통화별 미수금이지만 회수 금액은 차량의 currency 단위에 종속
            // (예: USD 차량 → USD로 회수, KRW 차량 → KRW로 회수)
            $table->decimal('amount', 15, 2);

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_histories');
    }
};
