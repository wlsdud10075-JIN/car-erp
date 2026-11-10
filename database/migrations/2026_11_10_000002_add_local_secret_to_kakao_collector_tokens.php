<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📱 카톡자동화 §11 「사용 화면은 ERP 하나」(jin 2026-11-10) — 실무자 PC 의 창 없는 에이전트(127.0.0.1:47321)를 ERP 페이지 JS 가 부를 때
 * 같은 PC 의 다른 사이트가 못 부르게 쓰는 비밀값. 설치 .bat 이 토큰과 함께 심고, ERP 페이지는 그 사용자의 기기 비밀값을 헤더로 보낸다.
 * 토큰 행마다 하나(= PC 마다 하나). 기존 행(exe 창 시절)은 null 로 남고 다시 연결하면 새 행이 생긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kakao_collector_tokens', function (Blueprint $table) {
            $table->string('local_secret', 64)->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('kakao_collector_tokens', function (Blueprint $table) {
            $table->dropColumn('local_secret');
        });
    }
};
