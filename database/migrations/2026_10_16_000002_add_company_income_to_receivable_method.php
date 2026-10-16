<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 채권관리 회수방법에 'company_income'(회사이익 전환) 추가 — 과입금을 **회사이익으로** 돌리는 세 번째 갈래 (jin 2026-10-16).
 *
 * 과입금 정리 = 적립금(바이어 크레딧) / 잡손실(기록만, 숫자 불변) / **회사이익(관리자 대시보드 회사 순이익에 더함, jin «B 맞아»)**.
 * 잡손실과 달리 「회사가 번 돈」이라 대시보드 `companyProfit()` 이 기간 내 이 행을 KRW 로 더한다. 미수 계산에서는 misc_loss 와 같이 제외(미러).
 *
 * 🚨 코드 상수(`ReceivableHistory::METHODS`)만 늘리고 이 enum 을 안 늘리면 **운영 MySQL 에서만 죽는다**(`1265 Data truncated`).
 *    로컬·CI 는 SQLite 라 테스트로 안 잡힌다. 가드 = `ReceivableMethodEnumTest`(상수 ↔ 이 문자열 정적 대조).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off', 'savings', 'misc_loss', 'company_income') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off', 'savings', 'misc_loss') NOT NULL");
        }
    }
};
