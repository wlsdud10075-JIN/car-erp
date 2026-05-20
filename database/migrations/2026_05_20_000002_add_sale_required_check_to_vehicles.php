<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-05-19 풀회의 안건 E — 판매 필수 항목 DB CHECK constraint.
 *
 * 회의 결정: application validation + DB CHECK 이중 방어 (Gemini "데이터 무결성 최후 보루").
 *
 * 규칙: sale_price > 0 시 다음 3 필드 동시 충족.
 *   - sale_date IS NOT NULL
 *   - buyer_id IS NOT NULL
 *   - exchange_rate > 0 (KRW는 default 1로 자연 통과, 외화는 명시 입력)
 *   - currency는 column default 'USD' 있어 NULL 불가 → 제외
 *
 * SQLite skip: in-memory 테스트는 application validation만 검증 (ALTER ADD CONSTRAINT
 * SQLite 제한적). 운영 MySQL/MariaDB는 양쪽 모두 시행.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT chk_sale_required CHECK (
            sale_price = 0 OR (
                sale_date IS NOT NULL
                AND buyer_id IS NOT NULL
                AND exchange_rate > 0
            )
        )');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT chk_sale_required');
    }
};
