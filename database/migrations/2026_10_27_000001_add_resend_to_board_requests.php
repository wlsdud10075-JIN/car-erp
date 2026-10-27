<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board 요청 「재전송」 (jin 2026-10-27, board 세션 설계 §11) — 영업이 확인 전 재촉할 길이 없어
 * 보낸 버튼이 「계약금 재전송 (N/5)」 로 바뀌어 같은 열린 요청을 다시 알리게 한다.
 *
 * 행을 새로 만들지 않는다(멱등 유지) — 열린 행에 회차·마지막 시각만 적는다. 상한 = `BoardRequest::RESEND_LIMIT`(5).
 * 둘 다 default/nullable 추가만이라 3-DB 안전. 행이 닫히고(done·rejected) 새 요청이 열리면 새 행이라 0 부터.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_requests', function (Blueprint $t) {
            $t->unsignedSmallInteger('resent_count')->default(0)->after('amount_krw');
            $t->timestamp('last_resent_at')->nullable()->after('resent_count');
        });
    }

    public function down(): void
    {
        Schema::table('board_requests', function (Blueprint $t) {
            $t->dropColumn(['resent_count', 'last_resent_at']);
        });
    }
};
