<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 방별 마지막 읽기 결과(jin 11-12 «다시 고르기 눌러도 설정이 안되는 방» — 김다정 PC 에서 3개 방이 한 번도 서버에 안 왔는데 이유가 PC 로그에만 있었다).
 * 화면이 읽기가 끝날 때 에이전트 진행 줄(「[방] 이유」)을 넘겨 사람마다 저장한다. ok = 그 읽기에서 서버가 글을 받았나(kakao_reads 생성).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kakao_room_users', function (Blueprint $table) {
            $table->string('last_result', 200)->nullable()->after('last_seen_at');
            $table->boolean('last_result_ok')->nullable()->after('last_result');
            $table->timestamp('last_result_at')->nullable()->after('last_result_ok');
        });
    }

    public function down(): void
    {
        Schema::table('kakao_room_users', function (Blueprint $table) {
            $table->dropColumn(['last_result', 'last_result_ok', 'last_result_at']);
        });
    }
};
