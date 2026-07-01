<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleCostService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 2차 정산 비용 일괄 기입 (면허비 묶음 n/1 · 탁송비 명세서) 공용 뒷단 검증.
 *
 * - 비용 컬럼만 기입 가능(민감 21필드 봉인) / 잠긴 차량 자동 잠금해제+재잠금 / 감사로그 / 권한.
 */
class BulkVehicleCostTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function service(): BulkVehicleCostService
    {
        return app(BulkVehicleCostService::class);
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'BULK-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 1000000,
            'cost_towing' => 30000,
            'dhl_request' => false,
        ], $overrides));
    }

    private function lock(Vehicle $v): void
    {
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 500000,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => User::factory()->create(['permission' => 'super'])->id,
        ]);
        $v->refresh();
    }

    public function test_fleet_apply_overwrites_locked_cost_and_relocks_and_audits(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);
        $v = $this->makeVehicle();
        $this->lock($v);
        $this->assertTrue($v->fresh()->hasConfirmedPaymentLock());

        $res = $this->service()->apply('cost_towing', [$v->id => 227000], $admin, '위카 탁송비 명세서 일괄 (2026-06)', true);

        $this->assertSame(1, $res['applied']);
        $this->assertSame([], $res['skipped']);
        $this->assertSame(227000, (int) $v->fresh()->cost_towing);
        // 저장 1회 후 즉시 재잠금 — 토큰 소비됨
        $this->assertFalse(Cache::has(Vehicle::ledgerUnlockCacheKey($v->id)));
        // 감사로그 — 잠금해제 사유 기록
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $v->id,
            'action' => 'ledger_field_unlocked',
            'new_value' => '위카 탁송비 명세서 일괄 (2026-06)',
        ]);
    }

    public function test_non_cost_column_is_rejected(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);
        $v = $this->makeVehicle(['sale_price' => 1000000]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->apply('sale_price', [$v->id => 999], $admin, '민감필드 시도 차단 테스트', true);
    }

    public function test_unlocked_vehicle_updates_without_token(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);
        $v = $this->makeVehicle();   // 확정 잔금 없음 → 안 잠김

        $res = $this->service()->apply('cost_towing', [$v->id => 45000], $admin, '안 잠긴 차량 자유 기입 테스트', true);

        $this->assertSame(1, $res['applied']);
        $this->assertSame(45000, (int) $v->fresh()->cost_towing);
    }

    public function test_fleet_apply_requires_can_approve(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($sales);
        $v = $this->makeVehicle();

        $this->expectException(AuthorizationException::class);
        $this->service()->apply('cost_towing', [$v->id => 45000], $sales, '영업은 권한 없음 테스트', true);
    }

    public function test_missing_vehicle_is_reported_skipped(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $res = $this->service()->apply('cost_towing', [999999 => 45000], $admin, '미매칭 차량 스킵 리포트 테스트', true);

        $this->assertSame(0, $res['applied']);
        $this->assertSame('not_found', $res['skipped'][0]['reason']);
    }
}
