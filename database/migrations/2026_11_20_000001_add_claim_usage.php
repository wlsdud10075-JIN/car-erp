<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 사용 (jin 2026-11-20) — 인정된 클레임만큼 바이어가 다음 차 대금을 덜 보낸다 → 그 차 미수를 「클레임」으로 턴다.
 *
 * - `receivable_histories.source` : 그 회수 줄이 어디서 만들어졌나. 'claim' = 판매탭 「클레임 사용」이 만든 상계 줄.
 *   🚫 회수 방법(method enum)에 새 값을 만들지 않는다 — 상계(offset) 그대로라 미수 공식·MySQL enum 이 안 바뀐다.
 * - `vehicle_claim_usages` : 어느 클레임에서 얼마를 어느 차에 썼나(오래된 클레임부터). 회수 줄에 물려 있어
 *   차량이 지워지면(DB cascade — 모델 이벤트 없음) 회수 줄 → 사용 내역이 같이 지워져 클레임 잔액이 되살아난다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('method');
        });

        Schema::create('vehicle_claim_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_claim_id')->constrained('vehicle_claims')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('receivable_history_id')->constrained('receivable_histories')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'vehicle_claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_claim_usages');
        Schema::table('receivable_histories', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
