<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 무담보로 지급한 계약금 표시 (jin 2026-08-10).
 *
 * 무담보 한도는 **회사가 바이어 대신 내준 계약금**을 담는 주머니다. 그런데 계약금 행만 봐서는
 * 그 돈이 바이어가 보낸 것인지 회사가 대신 낸 것인지 알 수 없다 —
 * jin: "이거 실제로 50만원이 누구 돈일 줄 알고? 맘대로 무담보에서 소모처리 되는 거야?"
 *
 * 그래서 사람이 명시한다. 체크된 차량의 계약금만 무담보를 소모한다.
 *   체크 안 함 = 바이어 돈으로 낸 계약금 → 무담보 무관 (우회가 아니라 사실 기록)
 *
 * 「보증금으로 매입」(`is_deposit_purchase`)과 같은 성격의 표시이고, 돈의 출처 기록이라
 * 변경은 감사로그에 남는다. 권한은 [관리] 이상(`canApprove`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('is_unsecured_down')->default(false)->after('is_deposit_purchase');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('is_unsecured_down');
        });
    }
};
