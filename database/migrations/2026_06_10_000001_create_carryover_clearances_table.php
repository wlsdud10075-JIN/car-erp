<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 미청산 이월(stranded carryover) 청산 기록 — 퇴사자/관계종료 시 1회 정리.
 * amount_krw = 청산 시점의 미청산 이월 net(부호): + = 담당자에게 지급 / − = 담당자에게서 회수.
 * Salesman::unconsumed_carryover accessor 와 Settlement::creating 흡수 훅이 Σamount_krw 를 차감
 * → 청산 후 재흡수(이중계상) 차단.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carryover_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salesman_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_krw');           // signed net (+지급 / −회수)
            $table->string('direction', 10);            // 'pay' | 'collect'
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index('salesman_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carryover_clearances');
    }
};
