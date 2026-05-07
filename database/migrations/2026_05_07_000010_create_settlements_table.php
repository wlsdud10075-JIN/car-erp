<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->enum('settlement_type', ['ratio', 'per_unit']); // 비율 or 건당
            $table->decimal('settlement_ratio', 5, 2)->nullable();  // 비율 (%)
            $table->decimal('per_unit_amount', 15, 2)->nullable();  // 건당 금액 (원)
            $table->decimal('other_deduction', 15, 2)->default(0); // 기타공제
            $table->enum('settlement_status', ['pending', 'calculating', 'confirmed', 'paid'])->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('settlement_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
