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
 * 회의확장씬 #9 회귀 — 차량 등록 시 기타비용 기본기재 (말소 24k / 면허 11k / 탁송 30k).
 *
 * 설계: UI default 만 (openCreate 시점). 마이그·saving 훅 X — unsignedBigInteger default(0)
 * 이라 saving 훅 효과 X, raw SQL 마이그 부담. UI default 가 사용자 의도 "기본기재" 충족.
 *
 * Phase 1-3 통합: secondary_status='pending' 동안 [관리]/[재무] 가 cost_* 수정 가능
 * (한 달 뒤 측정된 실제 비용으로 정정).
 */
class DefaultCostTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeUser(string $permission, ?string $role = null): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $role ?? '관리',
            'email_verified_at' => now(),
        ]);
    }

    public function test_open_create_pre_fills_default_costs(): void
    {
        $sales = $this->makeUser('user', '영업');
        $this->actingAs($sales);

        $component = Volt::test('erp.vehicles.index')->call('openCreate');

        $this->assertSame('24,000', $component->get('cost_deregistration_str'), '말소 default 24,000');
        $this->assertSame('11,000', $component->get('cost_license_str'), '면허 default 11,000');
        $this->assertSame('30,000', $component->get('cost_towing_str'), '탁송 default 30,000');
    }

    public function test_default_cost_overridable_to_zero(): void
    {
        // 운영자가 의도적으로 0 으로 비울 수 있음 (default 강제 X)
        $sales = $this->makeUser('user', '영업');
        $this->actingAs($sales);

        $component = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('cost_deregistration_str', '0');

        $this->assertSame('0', $component->get('cost_deregistration_str'));
    }

    public function test_secondary_pending_allows_manager_to_edit_cost(): void
    {
        // Phase 1-3 통합 — paid + secondary='pending' 동안 [관리] 가 cost_* 수정 가능
        $manager = $this->makeUser('user', '관리');
        $this->actingAs($manager);

        $v = Vehicle::create([
            'vehicle_number' => 'DCT-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_price' => 1_000_000,
            'purchase_date' => '2026-05-01',
            'cost_deregistration' => 24_000,
            'cost_license' => 11_000,
            'cost_towing' => 30_000,
        ]);

        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);
        // saving 훅 자동으로 secondary='pending' set

        // [관리] 가 cost_deregistration 50,000 으로 수정 (한 달 뒤 실측)
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('cost_deregistration_str', '50,000')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame(50_000, (int) $v->cost_deregistration, 'secondary_pending 동안 cost_* 수정 허용');
    }
}
