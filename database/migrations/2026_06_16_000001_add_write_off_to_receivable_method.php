<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 채권관리 회수방법에 'write_off'(손실/셀러부담) 추가.
 * 입금/현금/상계/기타와 달리 — 우리가 떠안은 손실을 기록 + 바이어 패널에 누적 표시(영업 협상 카드).
 * 미수 차감은 method!='deposit' 규칙에 따라 자동 포함.
 *
 * MySQL/MariaDB: ALTER MODIFY ENUM. SQLite는 enum 미강제(varchar) → skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other') NOT NULL");
        }
    }
};
