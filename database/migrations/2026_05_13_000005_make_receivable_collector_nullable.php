<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 큐 10 H5 — FinalPayment::created → ReceivableHistory 자동 미러링 시
        // collector_id는 시스템 자동 처리라 user 없음. nullable로 완화.
        // UI 회수 이력 입력은 Volt::test/실제 폼 단에서 여전히 collector_id required.
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->foreignId('collector_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->foreignId('collector_id')->nullable(false)->change();
        });
    }
};
