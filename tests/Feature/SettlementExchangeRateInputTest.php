<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 환율 수동 입력 검증.
 *
 * 사용자 결정:
 *   - 자동 + 수정 가능: ExchangeRateService default + 사용자 override
 *   - 2차 대기 중 언제든 입력 + 추후 수정
 *   - 환율 입력 → diff 자동 derive
 */
class SettlementExchangeRateInputTest extends TestCase
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

    private function makePendingSecondary(User $manager): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'EXR-'.++$this->counter,
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

        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
    }

    public function test_column_exists_and_fillable(): void
    {
        $this->assertTrue(Schema::hasColumn('settlements', 'exchange_rate_at_close'));
        $this->assertContains('exchange_rate_at_close', (new Settlement)->getFillable());
    }

    public function test_save_exchange_rate_stores_user_input(): void
    {
        $manager = $this->makeManager();
        $s = $this->makePendingSecondary($manager);

        $this->actingAs($manager);
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('exchange_rate_at_close_str', '1400')
            ->call('saveExchangeRate', $s->id);

        $this->assertSame('1400.0000', (string) $s->fresh()->exchange_rate_at_close);
    }

    public function test_save_exchange_rate_blocked_after_closed(): void
    {
        $manager = $this->makeManager();
        $s = $this->makePendingSecondary($manager);
        $s->update(['secondary_status' => 'closed', 'secondary_closed_at' => now()]);

        $this->actingAs($manager);
        $component = Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('exchange_rate_at_close_str', '9999')
            ->call('saveExchangeRate', $s->id);

        // closed 후 환율 변경 무시 (notify warning + DB 변경 없음)
        $this->assertNull($s->fresh()->exchange_rate_at_close);
    }

    public function test_close_uses_stored_rate_over_auto_fetch(): void
    {
        // 자동 환율 1380 — 사용자가 1500 으로 override
        Cache::put('exchange_rates', ['USD' => 1380.0], 60);

        $manager = $this->makeManager();
        $s = $this->makePendingSecondary($manager);

        $this->actingAs($manager);
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('exchange_rate_at_close_str', '1500')
            ->call('saveExchangeRate', $s->id)
            ->call('closeSecondarySettlement', $s->id);

        $fresh = $s->fresh();
        $this->assertSame('closed', $fresh->secondary_status);
        // diff = (400 × 1500) - (400 × 1350) = 600000 - 540000 = 60000  (사용자 입력 1500 우선)
        $this->assertSame(60000.0, (float) $fresh->exchange_difference_krw);
        $this->assertSame('1500.0000', (string) $fresh->exchange_rate_at_close);
    }

    public function test_close_falls_back_to_auto_when_no_stored_rate(): void
    {
        // 자동 환율 1380 — 사용자 입력 없음 → 자동 사용
        Cache::put('exchange_rates', ['USD' => 1380.0], 60);

        $manager = $this->makeManager();
        $s = $this->makePendingSecondary($manager);

        $this->actingAs($manager);
        // openEdit 가 자동값을 default 로 채우지만 saveExchangeRate 안 부르면 DB 미저장 → close 시 자동 fetch
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->call('closeSecondarySettlement', $s->id);

        $fresh = $s->fresh();
        $this->assertSame('closed', $fresh->secondary_status);
        // diff = (400 × 1380) - (400 × 1350) = 552000 - 540000 = 12000  (자동 fetch)
        $this->assertSame(12000.0, (float) $fresh->exchange_difference_krw);
        $this->assertSame('1380.0000', (string) $fresh->exchange_rate_at_close);
    }
}
