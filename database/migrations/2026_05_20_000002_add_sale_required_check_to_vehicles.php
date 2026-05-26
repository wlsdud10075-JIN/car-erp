<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-05-19 풀회의 안건 E — 판매 필수 항목 DB CHECK constraint.
 *
 * 회의 결정: application validation + DB CHECK 이중 방어 (Gemini "데이터 무결성 최후 보루").
 *
 * 규칙: sale_price > 0 시 다음 필드 동시 충족.
 *   - sale_date IS NOT NULL
 *   - exchange_rate > 0 (KRW는 default 1로 자연 통과, 외화는 명시 입력)
 *   - currency는 column default 'USD' 있어 NULL 불가 → 제외
 *
 * ⚠️ 2026-05-26 — buyer_id 를 CHECK 에서 제외 (운영 MySQL 8.0.45 에러 3823 fix):
 *   MySQL 8 은 외래 키(vehicles_buyer_id_foreign)에 쓰인 컬럼을 CHECK 제약에
 *   함께 사용하는 것을 금지한다(error 3823). SQLite(테스트)·MariaDB(로컬)는 허용해
 *   그동안 드러나지 않았다. sale_date·exchange_rate 는 FK 아님 → CHECK 유지 가능.
 *
 *   "판매 차량은 buyer_id 필수" 비즈니스 규칙은 application 레벨이 단일 enforcement:
 *     resources/views/livewire/erp/vehicles/index.blade.php::validateVehicleForm()
 *     (sale_price > 0 → buyer_id_str 'required'). 모든 UI save() 경로가 이를 거침.
 *   모델 saving 훅에 옮기지 않은 이유: 이 코드베이스는 도메인 가드를 UI save() 에서만
 *   호출하고 시드·factory 는 의도적으로 우회한다(guardStageOrderForExport 패턴).
 *
 * SQLite skip: in-memory 테스트는 application validation 만 검증 (ALTER ADD CONSTRAINT
 * SQLite 제한적). 운영 MySQL/MariaDB는 sale_date·exchange_rate 를 DB 레벨에서도 시행.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // 재실행 안전 — 기존 chk_sale_required (구 buyer_id 포함본 또는 부분 복구 잔재)
        // 가 있으면 먼저 제거 후 재생성. ADD CONSTRAINT 는 원자적이라 3823 실패 시 부분
        // 잔재는 없으나, 수동 복구 후 깨끗한 재실행을 보장하기 위한 방어.
        $this->dropCheckIfExists();

        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT chk_sale_required CHECK (
            sale_price = 0 OR (
                sale_date IS NOT NULL
                AND exchange_rate > 0
            )
        )');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->dropCheckIfExists();
    }

    /**
     * chk_sale_required 가 존재하면 제거 (없으면 무동작 — 재실행/롤백 안전).
     *
     * DROP CONSTRAINT 는 MySQL 8.0.19+ 와 MariaDB 10.2+ 공통 지원.
     * DROP CHECK 는 MySQL 전용 문법이라 MariaDB 호환 위해 회피.
     */
    private function dropCheckIfExists(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS present
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'vehicles'
                AND CONSTRAINT_NAME = 'chk_sale_required'
                AND CONSTRAINT_TYPE = 'CHECK'"
        );

        if ($exists) {
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT chk_sale_required');
        }
    }
};
