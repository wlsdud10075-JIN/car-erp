<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #6+7 보강 (2026-05-23) — 정산 화면 KRW 명세 (1차/입금/2차/환차) 검증.
 */
class SettlementKrwBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeManager(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    public function test_krw_breakdown_closed_settlement_shows_stored_diff(): void
    {
        $manager = $this->makeManager();
        $v = Vehicle::create([
            'vehicle_number' => 'KB-CLOSED-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 400, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,   // 저장된 환차익
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        $component = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id);

        $kb = $component->instance()->krwBreakdown;

        $this->assertFalse($kb['is_krw_vehicle']);
        $this->assertSame('closed', $kb['status']);
        $this->assertSame(12000.0, $kb['exchange_diff']);   // 저장된 확정 환차 (그대로 표시)
        $this->assertFalse($kb['is_preview']);
        $this->assertSame(540000, $kb['received_krw']);   // 400 × 1350
        $this->assertSame(540000, $kb['baseline_krw']);   // sale_total 400 × 판매환율 1350
    }

    public function test_krw_breakdown_pending_settlement_shows_live_preview(): void
    {
        // 2026-07-06 재피벗 — 판매환율 1350, 잔금을 1380 에 수령 → 환차 +30/USD.
        $manager = $this->makeManager();
        $v = Vehicle::create([
            'vehicle_number' => 'KB-PENDING-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 400, 'exchange_rate' => 1380,   // 수령 환율 1380 (판매환율 1350 보다 +30)
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        $component = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id);

        $kb = $component->instance()->krwBreakdown;

        $this->assertFalse($kb['is_krw_vehicle']);
        $this->assertSame('pending', $kb['status']);
        $this->assertTrue($kb['is_preview']);
        $this->assertSame(552000, $kb['received_krw']);     // 400 × 1380(수령환율)
        $this->assertSame(540000, $kb['baseline_krw']);     // 400 × 1350(판매환율)
        $this->assertSame(12000.0, $kb['exchange_diff']);   // 552000 - 540000
    }

    public function test_krw_breakdown_krw_currency_marks_as_no_exchange(): void
    {
        $manager = $this->makeManager();
        $v = Vehicle::create([
            'vehicle_number' => 'KB-KRW-'.++$this->counter,
            'sales_channel' => 'heyman',
            'currency' => 'KRW',
            'dhl_request' => false,
            'sale_price' => 5_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 5_000_000, 'exchange_rate' => 1,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        $component = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id);

        $kb = $component->instance()->krwBreakdown;

        $this->assertTrue($kb['is_krw_vehicle']);
        $this->assertArrayNotHasKey('exchange_diff', $kb);
        $this->assertArrayNotHasKey('baseline_krw', $kb);
    }

    public function test_krw_breakdown_rate_unavailable_marks_explicitly(): void
    {
        // 2026-07-06 재피벗 — baseline = 판매환율. 판매환율(exchange_rate)이 0/null 이면 계산 불가.
        $manager = $this->makeManager();
        $v = Vehicle::create([
            'vehicle_number' => 'KB-NORATE-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 0,   // 판매환율 미입력 → baseline 계산 불가
            'dhl_request' => false,
            'sale_price' => 400,
            'purchase_date' => '2026-04-01',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        $component = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id);

        $kb = $component->instance()->krwBreakdown;

        $this->assertTrue($kb['rate_unavailable']);
        $this->assertArrayNotHasKey('baseline_krw', $kb);
        $this->assertArrayNotHasKey('exchange_diff', $kb);
    }
}
