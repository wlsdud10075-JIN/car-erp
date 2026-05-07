<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0); // 판매 잔금 (currency 기준)
            $table->date('payment_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_payments');
    }
};
