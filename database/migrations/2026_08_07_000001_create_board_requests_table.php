<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board ↔ erp 요청·확인 신호 (카톡 대체) — 권위 스펙 = docs/integration/board-portal-api.md §11.
 *
 * board 영업이 "이 차 입금해주세요"(purchase_payment) / "이 바이어 차 N대 대금 넣었으니
 * 확인해주세요"(sale_payment_confirm) 두 마디만 보낸다. 계획서 = docs/design/board-erp-request-ack.md.
 *
 * 🚫 **금액 컬럼을 두지 않는다** (jin 2026-08-07) — 매입 지급액·판매 N잔금 기입은 전부 erp 관리 이상의
 *    일이고, 신호는 "누구의 어느 차"까지만 지목한다. 은행 API 연동 시 확장 논의(그때 이 파일 개정).
 * ⚠️ vehicles 상태 컬럼에 적재 금지 — shipping_requests 와 같은 이유(게이트 회귀).
 *
 * 묶음은 별도 테이블 없이 batch_id(uuid) 로 그룹핑 — shipping_requests 와 동일 방식.
 * 입금요청은 1행짜리 묶음, 판매대금확인은 N행 묶음이라 한 테이블로 둘 다 담긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_requests', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 36)->index();            // uuid — 판매대금확인 N대 묶음
            $table->string('type', 30);                         // purchase_payment / sale_payment_confirm
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained()->nullOnDelete();  // 판매대금확인만
            $table->string('status', 20)->default('open');      // open / done / cancelled
            $table->string('requested_by_email', 191);          // 요청 board 영업 (감사)
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // 멱등 조회 — 같은 차 + 같은 type 에 열린 요청이 둘 생기지 않게 (모델 가드가 이 인덱스로 조회)
            $table->index(['vehicle_id', 'type', 'status'], 'idx_boardreq_vehicle_type_status');
            $table->index(['status', 'type'], 'idx_boardreq_status_type');   // 뱃지 카운트
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_requests');
    }
};
