<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review2 항목 C (2026-06-09) — 운영 MySQL 전용 CHECK 제약 회귀.
 *
 * SQLite는 chk_sale_required CHECK 를 마이그에서 skip하므로 SQLite 환경(로컬·기존 CI 잡)에선
 * 자동 markTestSkipped. CI 의 mysql-check 잡(MySQL 8 서비스)에서만 실제 검증된다.
 *
 * 목적: #5(환율0 정산음수)를 운영에서 막는 게 정산 레이어가 아니라 이 DB CHECK 라는 사실 →
 * 그 방어가 살아있는지 CI 가 직접 보증(SQLite만 돌리면 검증 못 하는 false confidence 해소).
 */
class MysqlCheckConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_chk_sale_required_rejects_zero_exchange_rate(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('chk_sale_required CHECK 는 MySQL 전용 (SQLite 마이그에서 skip)');
        }

        $buyer = Buyer::create(['name' => 'CHK BUYER', 'is_active' => true]);

        $this->expectException(QueryException::class);

        // sale_price>0 인데 exchange_rate=0 → chk_sale_required 위반.
        // 운영에서 외화 환율0 차량이 INSERT 되는 걸 막아 음수 정산(#5)을 상류 차단하는 제약.
        Vehicle::create([
            'vehicle_number' => 'CHK-0RATE',
            'sales_channel' => 'export',
            'sale_price' => 1_000_000,
            'sale_date' => '2026-01-01',
            'buyer_id' => $buyer->id,
            'currency' => 'USD',
            'exchange_rate' => 0,
        ]);
    }
}
