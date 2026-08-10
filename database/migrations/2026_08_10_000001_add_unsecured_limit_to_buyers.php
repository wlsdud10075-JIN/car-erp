<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 무담보 한도 (jin 2026-08-10) — 단골 바이어에게 담보 없이 내주는 매입 한도.
 *
 * 기존 매입 게이트는 **선적 전 국내 차량**만 담보로 본다(`Buyer::computeReceivableGauge`).
 * 그래서 차가 다 선적돼 국내에 0대면 게이지가 `null` 이라 락이 통째로 안 걸린다 —
 * 실측(heymanerp 2026-08-10): AUTO DIOR 선적전 0대인데 **선적 후 미수 3.19억**.
 *
 * 이 컬럼은 그 상태에서 "얼마까지는 담보 없이 매입해준다"를 바이어별로 정한다.
 *   - NULL·0 = 미설정 → **기존 동작 그대로**(미수율 기반 게이트). 운영 충격 없음.
 *   - > 0     = 설정  → 한도 기반 게이트로 전환(기본 한도 + 무담보 한도 − 사용액 ≤ 0 이면 차단).
 *
 * ⚠️ 실제로 받아둔 현금이 아니라 **우리가 부여하는 외상 한도**다(jin 확정).
 *    따라서 자금현황(`CapitalStatusService`)에 잡지 않는다 — 우리 돈이 나간 것도, 남의 돈을 맡은 것도 아니다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->unsignedBigInteger('unsecured_limit_krw')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('unsecured_limit_krw');
        });
    }
};
