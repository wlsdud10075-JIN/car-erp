<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임(파손보상) — board 에서 결재가 끝난 클레임을 받아 쌓는다(jin 2026-11-11, docs/design/claim-management.md §10).
 * 바이어 드로어 「클레임」 탭이 이걸 모아 보여 준다(횟수·확정 금액·결과·사유). 적립금·미수·정산과는 무관한 기록.
 * 문자열 칸은 enum 이 아니다(§8 #36) — 값 목록은 모델 상수.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_claims', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10)->default('board');
            $table->unsignedBigInteger('board_claim_id')->nullable()->unique();   // 멱등 키 — 회사(인스턴스)마다 DB 가 따로라 회사 구분 불필요
            $table->unsignedInteger('revision')->default(1);                      // board 재승인 회차 — 더 큰 것만 갱신
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->string('buyer_name', 120)->nullable();                       // 당시 이름(바이어명이 바뀌어도 기록 보존)
            $table->string('vehicle_number', 30)->nullable();
            $table->string('vin', 30)->nullable();
            $table->decimal('confirmed_amount', 15, 2)->default(0);              // 확정 금액(기각 = 0)
            $table->string('currency', 3);
            $table->string('compensation_type', 10)->nullable();                  // refund(송금) | credit(차감) | null(담당자가 안 고름)
            $table->string('result', 10);                                         // accepted | declined
            $table->text('result_reason')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('approved_by_name', 100)->nullable();
            $table->string('handler_name', 100)->nullable();
            $table->string('salesman_email', 150)->nullable();
            $table->unsignedInteger('board_buyer_claim_count')->nullable();       // board 가 센 그 바이어 누적 클레임 수
            $table->string('board_url', 300)->nullable();
            $table->timestamps();
            $table->index(['buyer_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_claims');
    }
};
