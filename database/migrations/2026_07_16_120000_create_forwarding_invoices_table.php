<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 포워딩사 운임 인보이스(지급 청산 기록) — jin 2026-07-16.
 *
 * 목적 = "우리가 이 운임을 포워딩사에 줬나/안 줬나" 지급 여부 추적(차액 정산 아님).
 * 포워딩사가 주는 인보이스(컨테이너/RORO 단위)의 실금액을 기입하고 청산(지급완료) 표시한다.
 *
 * ⚠️ paid_at 이 지급여부의 단일 출처 — 차량 재그룹핑으로 파생 판정 금지(그룹키가 변해도 지급기록은 살아있어야).
 * ⚠️ 격리: transport_fee 는 매출측·정산 무관(CLAUDE.md)이라 이 테이블은 정산·미수 캐시·Vehicle computed 어디에도 연결 안 함.
 * ⚠️ 3-DB(SQLite/MariaDB/MySQL8) 안전: enum 미사용(group_type/currency=string), CHECK 없음, FK no hard-cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forwarding_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forwarding_company_id')->nullable()->constrained()->nullOnDelete();
            // 묶음 기준 — container(컨테이너번호) / declaration(수출신고번호) / vessel(선박명). 앱단 검증.
            $table->string('group_type', 20);
            $table->string('group_key');
            $table->string('currency', 3)->default('USD');
            $table->decimal('amount', 15, 2)->default(0);   // 인보이스 실금액(포워딩사 청구대로)
            $table->date('invoice_date')->nullable();
            // 지급(청산) 시각 — NULL=미지급, 값=지급완료. 지급여부 단일 출처(파생 금지).
            $table->timestamp('paid_at')->nullable();
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['forwarding_company_id', 'group_type', 'group_key'], 'fwd_inv_company_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forwarding_invoices');
    }
};
