<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-07-06 2차 정산 환차 재피벗 (실현손익) 단위 검증.
 *
 * 신모델: 환차 = 실입금KRW − baseline
 *   실입금KRW = Σ(잔금외화 × 잔금환율) + Σ(기타회수외화 × 판매환율)
 *   baseline  = sale_total_amount(외화) × 판매환율(vehicle.exchange_rate)
 *   완납게이트(sale_unpaid_amount ≤ 0) 하 → 환차 = Σ(잔금외화 × (잔금환율 − 판매환율))
 *   → 기타회수는 판매환율로 평가되어 환차에 0 기여 (FX 중립).
 */
class SettlementFxRepivotTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function foreignVehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => 'FX-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1300,   // 판매환율 (baseline)
            'dhl_request' => false,
            'sale_price' => 1000,
            'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ], $attrs));
    }

    private function paidPendingSettlement(Vehicle $v): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
    }

    /** exchange_rate_at_close 컬럼은 물리 삭제하지 않고 유지 (기존 closed 감사행 판독용, nullable-deprecate). */
    public function test_exchange_rate_at_close_column_preserved_for_audit(): void
    {
        $this->assertTrue(Schema::hasColumn('settlements', 'exchange_rate_at_close'));
    }

    /** 기타회수(method≠deposit)는 판매환율로 평가 → 환차에 0 기여 (FX 중립). */
    public function test_receivable_history_is_fx_neutral(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // V1: 잔금 600@1350 + 기타회수 400(판매환율) → 완납(1000)
        $v1 = $this->foreignVehicle();
        $v1->finalPayments()->create([
            'amount' => 600, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-05', 'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v1->receivableHistories()->create([
            'amount' => 400, 'method' => 'other',
            'collected_at' => '2026-05-06', 'collector_id' => $admin->id,
        ]);
        $s1 = $this->paidPendingSettlement($v1);

        // V2: 잔금 600@1350 만, sale_total 600 → 완납. 기타회수 없음.
        $v2 = $this->foreignVehicle(['sale_price' => 600]);
        $v2->finalPayments()->create([
            'amount' => 600, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-05', 'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $s2 = $this->paidPendingSettlement($v2);

        $c = Volt::test('erp.settlements.index');
        $c->call('closeSecondarySettlement', $s1->id);
        $c->call('closeSecondarySettlement', $s2->id);

        // 둘 다 환차 = 600 × (1350 − 1300) = 30,000. 기타회수 400 은 환차에 0 기여.
        $this->assertSame(30000.0, (float) $s1->fresh()->exchange_difference_krw, '기타회수 포함 V1');
        $this->assertSame(30000.0, (float) $s2->fresh()->exchange_difference_krw, '기타회수 없는 V2');
    }

    /** 미완납(외화)에서는 2차 마감 차단 — 원금 미수가 환차로 둔갑하는 것 방지. */
    public function test_unpaid_foreign_blocks_secondary_close(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $v = $this->foreignVehicle();   // sale_total 1000
        $v->finalPayments()->create([
            'amount' => 600, 'exchange_rate' => 1350,   // 600 만 입금 → 미수 400
            'payment_date' => '2026-05-05', 'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $s = $this->paidPendingSettlement($v);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $this->assertSame('pending', $s->fresh()->secondary_status, '미완납 외화는 마감 차단');
        $this->assertNull($s->fresh()->exchange_difference_krw);
    }

    /** 환차 = Σ(잔금외화 × (잔금환율 − 판매환율)) — 여러 잔금 row 합산. */
    public function test_pure_realized_fx_equals_row_rate_delta(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $v = $this->foreignVehicle();   // sale_total 1000, 판매환율 1300
        $v->finalPayments()->create([
            'amount' => 500, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-05', 'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->finalPayments()->create([
            'amount' => 500, 'exchange_rate' => 1360,
            'payment_date' => '2026-05-10', 'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $s = $this->paidPendingSettlement($v);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        // 500×(1350−1300) + 500×(1360−1300) = 25,000 + 30,000 = 55,000
        $this->assertSame(55000.0, (float) $s->fresh()->exchange_difference_krw);
    }
}
