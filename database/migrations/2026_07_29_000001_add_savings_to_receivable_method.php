<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 채권관리 회수방법에 'savings'(적립금) 추가 — 2026-07-28 배포 누락분 복구.
 *
 * 🚨 2026-07-28 "적립금 채권관리 회수방법" 배포가 코드만 나가고 이 enum 을 안 늘렸다. 그 결과
 *    3사 전부에서 적립금 사용이 죽어 있었다(jin 제보 ssancarerp 18누0304, 실측 로그
 *    `SQLSTATE[01000] 1265 Data truncated for column 'method'`). 차량 판매탭·채권관리 양쪽 —
 *    `Vehicle::saved` H6 가 method='savings' 회수이력을 만들고, 그게 실패하며 차량 저장이 통째로 롤백돼
 *    savings_used 가 0 으로 남았다.
 *
 * ⚠️ 로컬 테스트가 이걸 못 잡은 이유 = **SQLite 는 enum 을 강제하지 않는다**([[project_db_tier_mismatch]]).
 *    코드 화이트리스트(ReceivableHistory::METHODS)와 DB enum 이 어긋나도 테스트는 통과한다.
 *    가드 = ReceivableMethodEnumTest (코드 ↔ 마이그레이션 문자열 대조, 드라이버 무관).
 *
 * MySQL/MariaDB: ALTER MODIFY ENUM. SQLite 는 enum 미강제(varchar) → skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off', 'savings') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE receivable_histories MODIFY COLUMN method ENUM('deposit', 'cash', 'offset', 'other', 'write_off') NOT NULL");
        }
    }
};
