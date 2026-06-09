<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Review2 항목 B·E (2026-06-09).
 *  B — Settlement SoftDeletes (pending 삭제는 복구 가능 / 회계잠금 row 는 여전히 deleting 가드로 차단)
 *  E — 외화 차량 환율 조회 실패 시 2차정산 close 차단 + 미흡수 이월 리포트 명령
 */
class SettlementSoftDeleteCloseGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'SDC-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    // ── B — SoftDeletes ──────────────────────────────────────────

    public function test_pending_settlement_soft_deletes_and_is_recoverable(): void
    {
        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 100000,
            'settlement_status' => 'pending',
        ]);

        $this->actingAs($this->admin());
        $s->delete();

        $this->assertNull(Settlement::find($s->id), '기본 쿼리에선 soft-deleted 제외');
        $this->assertNotNull(Settlement::withTrashed()->find($s->id), 'withTrashed 로 복구 가능하게 보존');
        $this->assertNotNull(Settlement::withTrashed()->find($s->id)->deleted_at);
    }

    public function test_paid_settlement_still_blocked_after_soft_deletes(): void
    {
        $v = $this->makeVehicle();
        $s = new Settlement;
        $s->vehicle_id = $v->id;
        $s->settlement_type = 'per_unit';
        $s->per_unit_amount = 100000;
        $s->settlement_status = 'pending';
        $s->save();
        $s->settlement_status = 'paid';
        $s->save();

        $this->actingAs($this->admin());
        $this->expectException(\DomainException::class);
        $s->delete();   // deleting 가드는 SoftDeletes 후에도 confirmed/paid/closed 차단
    }

    // ── E — 외화 환율 실패 시 close 차단 ──────────────────────────

    public function test_close_blocked_when_foreign_rate_unavailable(): void
    {
        // 자동 환율 조회 실패 시뮬레이션
        $this->mock(ExchangeRateService::class, function ($mock) {
            $mock->shouldReceive('getRate')->andReturn(null);
        });

        $v = $this->makeVehicle(['currency' => 'USD', 'exchange_rate' => 1300]);
        $s = new Settlement;
        $s->vehicle_id = $v->id;
        $s->settlement_type = 'ratio';
        $s->settlement_ratio = 50;
        $s->settlement_status = 'paid';   // → secondary='pending' 자동
        $s->save();
        $s->settlement_status = 'paid';
        $s->save();

        $this->actingAs($this->admin());

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $this->assertSame('pending', $s->fresh()->secondary_status, '환율 없으면 close 차단(여전히 pending)');
        $this->assertNull($s->fresh()->exchange_difference_krw, 'null 환차로 잠기지 않아야');
    }

    public function test_close_succeeds_for_krw_without_rate(): void
    {
        // KRW 차량은 환차 0 → 환율 없이도 마감 가능 (가드는 외화만)
        $v = $this->makeVehicle(['currency' => 'KRW', 'exchange_rate' => 1]);
        $s = new Settlement;
        $s->vehicle_id = $v->id;
        $s->settlement_type = 'ratio';
        $s->settlement_ratio = 50;
        $s->settlement_status = 'paid';
        $s->save();
        $s->settlement_status = 'paid';
        $s->save();

        $this->actingAs($this->admin());
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $this->assertSame('closed', $s->fresh()->secondary_status, 'KRW는 환율 없이도 마감');
    }

    // ── E — 미흡수 이월 리포트 ────────────────────────────────────

    public function test_carryover_report_runs(): void
    {
        $salesman = Salesman::create(['name' => '퇴사영업', 'is_active' => false, 'type' => 'freelance']);
        $v = $this->makeVehicle(['salesman_id' => $salesman->id]);
        // closed + carryover_out 있는 정산을 명시 구성 (이월 흡수 대상 없음 = stranding)
        Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'carryover_out_krw' => 50000,
        ]);

        $this->artisan('settlements:carryover-report --stranded')
            ->assertExitCode(0);
    }
}
