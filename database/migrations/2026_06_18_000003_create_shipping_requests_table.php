<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board 영업 포털 선적요청(③) 적재.
 *
 * ⚠️ vehicles 컬럼(특히 export_buyer_id)에 적재 금지(C4/C5 guardStageOrderForExport 게이트 회귀)
 *    → 별도 테이블. 권위 스펙 = docs/integration/board-portal-api.md §5.
 * board 영업이 "이 차들 이 바이어/컨사이니로 RORO/컨테이너 보내라" 지시. 관리/수출통관이 실무 진행.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('consignee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipping_method', 20);          // RORO / CONTAINER
            $table->string('requested_by_email', 191);      // 요청 영업 (감사)
            $table->string('status', 20)->default('requested'); // requested / in_progress / done
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status'], 'idx_shipreq_vehicle_status'); // 멱등 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_requests');
    }
};
