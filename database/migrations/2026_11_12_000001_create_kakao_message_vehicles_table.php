<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카톡 메시지 ↔ ERP 차량 (jin 2026-11-11 «ERP 연동 — 카톡 내용과 ERP 에서 확인되는 사항을 혼합», 설계 §13-4·§14 ②).
 *  - 메시지 1건이 여러 대를 말하면(서렌더 5대·면허 8건) 줄이 여러 개다. 기존 kakao_messages.vehicle_id(모델이 고른 1대)는 그대로 둔다.
 *  - ident = 본문에서 뽑은 식별자(VIN:17자 / P:차량번호 / V:끝 6자리). vehicle_id 가 비어 있으면 「ERP 에 아직 없음」 — 화면이 매번 다시 찾는다(그 뒤 등록된 차).
 *  - via = self(이 글에 있음) | inherit(짧은 요청이 30분 안 앞 글의 차를 이어받음 — from_message_id 가 그 앞 글).
 * 문자열 칸(enum 아님 — §8 #36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kakao_message_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('kakao_messages')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('ident', 30);
            $table->string('via', 10)->default('self');
            $table->foreignId('from_message_id')->nullable()->constrained('kakao_messages')->nullOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'ident']);
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kakao_message_vehicles');
    }
};
