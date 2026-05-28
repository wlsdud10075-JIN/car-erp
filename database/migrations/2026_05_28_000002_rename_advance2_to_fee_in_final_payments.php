<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-05-28 — 선수금2(advance_2)를 송금 수수료(fee) 의미로 재용도화.
 *
 * 사용자 결정 (2026-05-28):
 *   - 바이어가 USD 5,000 송금 → 4,990 도착, 10 USD 은행 송금 수수료
 *   - 셀러(우리) 부담 처리 → 미수금 차감 (현 advance_2 동작 그대로)
 *   - 바이어별 누적 수수료 표시 → 영업이 협상 카드로 활용
 *
 * 마이그 범위:
 *   - final_payments.type enum 에서 'advance_2' → 'fee' rename
 *   - 기존 type='advance_2' row 를 'fee' 로 일괄 update
 *
 * DB driver 분기 (project-db-tier-mismatch 메모리):
 *   - MySQL/MariaDB: ALTER TABLE ... MODIFY ENUM (확장 → update → 축소 3단계)
 *   - SQLite: enum 이 CHECK constraint 로 구현되므로 PRAGMA writable_schema 로 sqlite_master 직접 수정
 *     (Laravel SQLite 의 ALTER TABLE 가 CHECK 변경을 직접 지원하지 않음 — 표준적 hack)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $isMysqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $isSqlite = $driver === 'sqlite';

        if ($isMysqlFamily) {
            // MySQL/MariaDB 운영 DB: enum 에 'fee' 임시 추가 ('advance_2' 와 공존)
            // (테스트 SQLite 는 2026_05_20_000004 보강으로 처음부터 'fee' 포함 → 이 단계 불필요)
            DB::statement("ALTER TABLE final_payments MODIFY COLUMN type ENUM('deposit_down', 'interim', 'advance_1', 'advance_2', 'fee', 'balance') NOT NULL DEFAULT 'balance'");
        }

        // 모든 driver: row update
        DB::table('final_payments')->where('type', 'advance_2')->update(['type' => 'fee']);

        if ($isMysqlFamily) {
            // 운영 DB: 'advance_2' 제거 (최종 5종 enum 확정)
            DB::statement("ALTER TABLE final_payments MODIFY COLUMN type ENUM('deposit_down', 'interim', 'advance_1', 'fee', 'balance') NOT NULL DEFAULT 'balance'");
        }
    }

    public function down(): void
    {
        $isMysqlFamily = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($isMysqlFamily) {
            DB::statement("ALTER TABLE final_payments MODIFY COLUMN type ENUM('deposit_down', 'interim', 'advance_1', 'advance_2', 'fee', 'balance') NOT NULL DEFAULT 'balance'");
        }

        DB::table('final_payments')->where('type', 'fee')->update(['type' => 'advance_2']);

        if ($isMysqlFamily) {
            DB::statement("ALTER TABLE final_payments MODIFY COLUMN type ENUM('deposit_down', 'interim', 'advance_1', 'advance_2', 'balance') NOT NULL DEFAULT 'balance'");
        }
    }
};
