<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 휴가 대리 위임 (2026-08-07 jin) — 휴가 갈 때 **담당 영업 전원을 한 번에** 대타에게 넘긴다.
 *
 * 해결하는 마찰 하나: 종전엔 [관리]가 자리를 비우면 담당 영업을 **한 명씩 열어**
 * 「담당 [관리]」 체크박스에 대타를 추가해야 그 사람 눈에 보였다(영업 5명이면 5번).
 *
 * ⚠️ 넘어가는 건 **담당 영업 스코프뿐**이다. 승인 계단·권한 등급은 안 넘긴다 —
 *   jin: "월배치 승인 정도는 며칠이라 가기 전에 처리하거나 다녀와서 해도 된다."
 *
 * 켜고 끄는 건 **본인(from)**. 복귀일(`ends_at`)은 필수이고, 만료는 **조회 시점 판정**이 정본이다
 * (cron 누락에 강함). `is_active` 는 "사람이 켜뒀다"는 뜻일 뿐 날짜가 지나면 무효다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();   // 휴가 가는 사람
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();     // 대신 해줄 사람
            $table->boolean('is_active')->default(false);
            $table->date('ends_at')->nullable();          // 복귀 예정일 — 이 날짜까지 유효(당일 포함)
            $table->string('reason', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_user_id', 'to_user_id']);
            $table->index(['to_user_id', 'is_active']);   // 로그인마다 "나에게 위임된 것" 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_delegations');
    }
};
