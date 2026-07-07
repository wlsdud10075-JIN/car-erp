<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * item 1 (jin 2026-07-07) — 업무관리자(manager) 권한 등급 신설.
     *
     * 등급 사다리: super > admin(대표) > manager(업무관리자) > user.
     * manager = admin 등가 권한에서 [기능설정·단계강제·super/admin 계정관리]만 제외.
     * Phase 2 정산지급 월배치 승인 사다리의 중간 계단([관리]→manager→대표).
     *
     * ⚠️ MySQL8 운영: enum MODIFY 로 값 추가(기존 super/admin/user 데이터 무영향).
     *    SQLite(테스트): Laravel enum=varchar 제약 없음 → 별도 처리 불요.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN permission ENUM('super','admin','manager','user') NOT NULL DEFAULT 'user'");
        }
    }

    public function down(): void
    {
        // 롤백 전 manager → user 강등(enum 축소 시 orphan 방지).
        DB::table('users')->where('permission', 'manager')->update(['permission' => 'user']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN permission ENUM('super','admin','user') NOT NULL DEFAULT 'user'");
        }
    }
};
