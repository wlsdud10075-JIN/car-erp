<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카톡자동화 담당 분야(jin 2026-11-11 «각 인원별로 메인으로 맡고 있는 업무 — 본인이 체크해서 보게»).
 *  - kakao_messages.topic : 분류기가 요청마다 찍는 분야(등록·말소·통관·선적·입금·보험·서류·기타). null = 이 기능 전에 분류된 글.
 *  - kakao_user_topics    : 사용자 한 명이 고른 분야(JSON 배열). 행 없음 = 아직 안 고름(지금처럼 전부 보기). users 표에 안 두는 이유 = User 감사 훅.
 * 문자열 칸(enum 아님 — §8 #36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kakao_messages', function (Blueprint $table) {
            $table->string('topic', 10)->nullable()->after('to_side');
        });
        Schema::create('kakao_user_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('topics');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kakao_user_topics');
        Schema::table('kakao_messages', function (Blueprint $table) {
            $table->dropColumn('topic');
        });
    }
};
