<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->enum('currency', ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'])->default('USD');
            $table->enum('transaction_type', ['EARNED', 'REFUND', 'USED', 'ADJUSTMENT', 'CANCELLED']);
            $table->decimal('savings', 15, 2);  // 거래액 (양수/음수 가능)
            $table->decimal('balance', 15, 2);  // 잔액 스냅샷 (>= 0)
            $table->foreignId('original_transaction_id')->nullable()->constrained('savings_statuses')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_statuses');
    }
};
