<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board 요청 「거절」 씬 (jin 2026-10-12, board 세션 설계) — 예: 매입잔금 요청이 왔는데 바이어 돈이 아직 안 들어와
 * 국내 잔금을 못 보낼 때, ERP 사용자가 「입금 확인」 대신 「거절」 + 사유를 눌러 board 영업이 알게 한다.
 *
 * 🔑 거절자·시각을 **confirmed_by_id/confirmed_at 에 겹쳐 쓰지 않는다** — 구 board(칩 수정 전)가 라인의 `confirmed_at`
 *    을 「확인됨」 신호로 읽는다. 거절 라인은 confirmed_at 이 null 이어야 거짓 신호가 안 나간다.
 * `status` 는 string(20) 이라 'rejected' 에 DDL 이 필요 없다(§8 #36 해당 없음). nullable 추가만이라 3-DB 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_requests', function (Blueprint $t) {
            $t->string('reject_reason', 200)->nullable()->after('note');
            $t->foreignId('rejected_by_id')->nullable()->after('reject_reason')->constrained('users')->nullOnDelete();
            $t->timestamp('rejected_at')->nullable()->after('rejected_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('board_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rejected_by_id');
            $t->dropColumn(['reject_reason', 'rejected_at']);
        });
    }
};
