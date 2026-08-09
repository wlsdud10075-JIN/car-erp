<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어 미정 매입 (jin 2026-08-09) — 바이어가 정해지기 전에 사 두는 **투기 매입**.
 *
 * 배경: 바이어 미수금 통제 때문에 차량 등록 시 영업담당자·바이어를 반드시 기재하기로 했는데,
 * 실제로는 바이어 없이 먼저 사는 경우가 있다(재고관리 「일반재고」의 정의 그 자체 — SKILLS §14).
 * 그동안은 등록 자체가 막혀 있었다.
 *
 * 🚫 **바이어를 빈 채로 두는 것만으로 통과시키지 않는다** — 그러면 "실수로 빠뜨린 것"과 구분이 안 되고,
 *    빠뜨림 방지라는 원래 목적이 사라진다. 사람이 이 플래그를 **명시적으로 켜야** 통과한다.
 *
 * 미수 통제는 안 뚫린다: 나중에 바이어를 지정하는 순간 `shouldCheckPurchaseGate()` 가
 * "편집 중 바이어 교체"로 보고 미수 게이트를 발동시킨다(null → 실제 바이어도 교체로 잡힌다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('buyer_undecided')->default(false)->after('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('buyer_undecided');
        });
    }
};
