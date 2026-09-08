<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 내수(국내 판매) — 바이어 지정 + 정산 행 박제 (jin 2026-09-08).
 *
 * 🔑 **두 칸이 필요한 이유** — 지정은 바이어에 붙지만, 「이 정산이 내수인가」는 **정산이 만들어질 때
 *    박제**해야 한다. 매번 바이어를 보고 판정하면 나중에 그 바이어의 내수 체크를 풀었을 때
 *    **과거 정산이 조용히 일반정산으로 뒤집힌다**(담당자 승계에서 겪은 그 형태 — 「누구 것인가」를
 *    상위 객체에 붙이면 과거 건이 소급된다).
 *
 * 🚨 **내수 = 원화 전제**다. 외화 차량에 내수 바이어를 붙이면 운임 게이트·현금 원장·정산 환율
 *    세 가지가 조용히 다르게 돈다 → 저장 시 애플리케이션이 막는다(DB 제약은 안 건다 —
 *    기존 행을 깨뜨리지 않기 위해).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->boolean('is_domestic')->default(false)->after('is_active');
        });

        Schema::table('settlements', function (Blueprint $table) {
            // 정산 생성 시점의 바이어 상태를 박제한다. 이후 바뀌지 않는다.
            $table->boolean('is_domestic')->default(false)->after('settlement_type');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('is_domestic');
        });
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('is_domestic');
        });
    }
};
