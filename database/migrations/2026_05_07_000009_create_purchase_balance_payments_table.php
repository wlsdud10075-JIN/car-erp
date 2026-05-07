<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_balance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0); // 매입 잔금 (원)
            $table->date('payment_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_balance_payments');
    }
};
