<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 미입금 우회 stage 에 'bl' 추가 — B/L 100% 우회를 선적 50% 우회('shipping')와 분리.
 * (선적 진입 우회만으로 B/L 발행까지 같이 뚫리던 것을 별개 승인으로 분리. jin 2026-06-23.)
 *
 * 비파괴: 'dhl'(폐기)도 enum 에 그대로 유지 — 기존행 보존(코드/UI 에서만 미사용).
 * SQLite(테스트)는 enum 을 TEXT 로 다뤄 제약이 없으므로 skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE unpaid_export_overrides MODIFY stage ENUM('clearance','shipping','dhl','bl') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE unpaid_export_overrides MODIFY stage ENUM('clearance','shipping','dhl') NOT NULL");
    }
};
