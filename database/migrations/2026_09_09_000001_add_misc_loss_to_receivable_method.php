<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 채권관리 회수방법에 'misc_loss'(잡손실) 추가 — 과입금을 회사 돈으로 돌리는 항목 (jin 2026-09-09).
 *
 * 바이어가 송금 수수료 명목으로 조금씩 더 보내서 금액이 몇십 단위로 남는 일이 있다. 우리가 수수료를
 * 떠안은 것도 있으니 그 잔돈을 「잡손실」 명목으로 회사 몫으로 돌리고 미수를 0 으로 만든다.
 *
 * 🚨 코드 상수(`ReceivableHistory::METHODS`)만 늘리고 이 enum 을 안 늘리면 **운영 MySQL 에서만 죽는다**
 *    (`1265 Data truncated for column 'method'`). 2026-07-28 적립금 배포가 정확히 그래서 3사에서
 *    적립금 사용이 통째로 죽었다. 로컬·CI 는 SQLite 라 enum 을 강제하지 않아 테스트로 안 잡힌다
 *    ([[project_db_tier_mismatch]]). 가드 = `ReceivableMethodEnumTest`(상수 ↔ 이 문자열 정적 대조).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off', 'savings', 'misc_loss') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off', 'savings') NOT NULL");
        }
    }
};
