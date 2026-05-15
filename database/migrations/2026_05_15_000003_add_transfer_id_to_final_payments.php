<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 큐 19-A — final_payments.transfer_id (회의록 v5 §13).
     *
     * inter_vehicle_transfers 1건은 양 차량에 final_payment를 각각 만든다
     * (source 음수 + target 양수). 두 final_payment를 같은 transfer_id로 묶어
     * 정합 추적·voided 시 짝 검색·감사 가능하게 함.
     *
     * nullable — 일반 매출 잔금은 transfer_id NULL.
     */
    public function up(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->after('vehicle_id')
                ->constrained('inter_vehicle_transfers')->nullOnDelete();
            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('final_payments', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
            $table->dropIndex(['transfer_id']);
            $table->dropColumn('transfer_id');
        });
    }
};
