<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 묶음 (jin 2026-11-24, board-portal-api.md §20) — board 가 같은 바이어의 클레임 N건을 한 번에 보낸다.
 * 묶음 표는 따로 두지 않는다 — 행마다 board 가 붙인 묶음 id(문자열, 예 「ssancarboard-B12」)만 남기고 합계는 화면에서 더한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_claims', function (Blueprint $table) {
            $table->string('board_bundle_id', 40)->nullable()->after('revision')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_claims', function (Blueprint $table) {
            $table->dropIndex(['board_bundle_id']);
            $table->dropColumn('board_bundle_id');
        });
    }
};
