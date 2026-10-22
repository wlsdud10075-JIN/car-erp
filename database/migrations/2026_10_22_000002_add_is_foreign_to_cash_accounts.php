<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 외화 계좌 표시 (jin 2026-10-22) — 외화 계좌에 원화를 넣으면 「맞냐」고 한 번 묻는다(막지는 않는다).
 *
 * 🚫 판정을 구분 글자(label)로 하지 않는다 — 이름이 바뀌면 조용히 깨진다(§8 #103). 칸으로 둔다.
 * 기존 계좌는 배포 시점 한 번만 구분이 「외화」인 것을 켠다(heymanerp 신한 180-012-458342 등). 이후는 화면 체크로.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->boolean('is_foreign')->default(false)->after('label');
        });
        DB::table('cash_accounts')->where('label', 'like', '%외화%')->update(['is_foreign' => true]);
    }

    public function down(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropColumn('is_foreign');
        });
    }
};
