<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #8 회귀 — 2차 정산 (secondary_status enum + 자동 전환 + 회계 잠금 분기).
 */
class SecondarySettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'SST-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_price' => 1_000_000,
            'purchase_date' => '2026-05-01',   // 자동 PBP Draft payment_date 동기화 (fb333d7)
        ], $overrides));
    }

    private function makeUser(string $permission, ?string $role = null): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $role ?? '관리',   // users.role NOT NULL — admin 도 default '관리' 채움
            'email_verified_at' => now(),
        ]);
    }

    public function test_settlements_has_secondary_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('settlements', 'secondary_status'));
        $this->assertTrue(Schema::hasColumn('settlements', 'secondary_closed_at'));
    }

    public function test_secondary_statuses_constant_has_two_keys(): void
    {
        $this->assertSame(['pending', 'closed'], array_keys(Settlement::SECONDARY_STATUSES));
    }

    public function test_paid_transition_auto_sets_secondary_pending(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->assertSame('pending', $s->fresh()->secondary_status, 'paid 전환 시 secondary_status=pending 자동 set');
    }

    public function test_explicit_secondary_closed_is_preserved(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->assertSame('closed', $s->fresh()->secondary_status, '이미 secondary set 된 경우 saving 훅 우회');
    }

    public function test_close_secondary_settlement_action_by_manager(): void
    {
        $admin = $this->makeUser('admin');
        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $manager = $this->makeUser('user', '관리');
        $this->actingAs($manager);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $s->refresh();
        $this->assertSame('closed', $s->secondary_status);
        $this->assertNotNull($s->secondary_closed_at);
    }

    public function test_close_secondary_settlement_blocked_for_sales(): void
    {
        $admin = $this->makeUser('admin');
        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $sales = $this->makeUser('user', '영업');
        $this->actingAs($sales);

        Volt::test('erp.settlements.index')
            ->call('closeSecondarySettlement', $s->id)
            ->assertStatus(403);

        $this->assertSame('pending', $s->fresh()->secondary_status, '영업은 closeSecondary 차단');
    }

    public function test_financial_lock_released_during_secondary_pending(): void
    {
        // 회의확장씬 #8: paid + secondary='pending' 상태에서 [관리]/[재무]/admin 회계 컬럼 수정 가능
        // [관리] role user 시나리오 (사용자 명세 "[관리]/[재무]가 차량수정에서 수정")
        // claudereview B — 관리는 본인 팀 차량만 편집 → 차량을 관리의 부하 영업에 배정.
        $manager = $this->makeUser('user', '관리');
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id, 'email_verified_at' => now()]);
        $salesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'employee']);
        $this->actingAs($manager);

        $v = $this->makeVehicle(['purchase_price' => 1_000_000, 'salesman_id' => $salesman->id]);
        Settlement::$allowBatchPayout = true;   // Phase 2 — setup paid 가드 우회
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);
        Settlement::$allowBatchPayout = false;
        // saving 훅이 자동으로 secondary='pending' set

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '2000000')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame(2_000_000, (int) $v->purchase_price, 'secondary_pending 동안 회계 수정 허용');
    }

    public function test_financial_lock_restored_after_secondary_closed(): void
    {
        // 회의확장씬 #8: secondary='closed' 후 회계 잠금 복귀
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $v = $this->makeVehicle(['purchase_price' => 1_000_000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'secondary_closed_at' => now(),
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '2000000')
            ->call('save')
            ->assertHasErrors(['purchase_price_str']);

        $v->refresh();
        $this->assertSame(1_000_000, (int) $v->purchase_price, 'secondary_closed 후 회계 잠금 복귀');
    }
}
