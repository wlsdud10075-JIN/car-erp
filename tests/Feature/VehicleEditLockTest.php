<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 동시 편집 잠금 (2026-06-30) — [관리1] 매입 → [관리2] 이후 처리로 일을 나눌 때
 * 같은 차량을 두 사람이 동시에 열어 덮어쓰는 사고 방지.
 *
 * 캐시 TTL 잠금 + wire:poll 하트비트. 2번째로 연 사람은 읽기 전용(저장·승인·삭제 서버 423 차단).
 */
class VehicleEditLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeAdmin(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'permission' => 'admin',  // 스코프 우회 — 잠금만 검증
            'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private int $counter = 0;

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'LOCK-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_price' => 1_000_000,
            'purchase_date' => '2026-05-01',
        ]);
    }

    private function lockKey(int $id): string
    {
        return "vehicle-edit-lock:{$id}";
    }

    public function test_second_user_opens_read_only_when_locked_by_another(): void
    {
        $userA = $this->makeAdmin('관리일');
        $vehicle = $this->makeVehicle();

        // A 가 이미 잠금 보유 중인 상황을 시뮬레이션.
        Cache::put($this->lockKey($vehicle->id), ['user_id' => $userA->id, 'name' => '관리일'], 90);

        $userB = $this->makeAdmin('관리이');
        $this->actingAs($userB);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $vehicle->id);

        $component->assertSet('editLockedByOther', true);
        $component->assertSet('editLockOwnerName', '관리일');
    }

    public function test_locked_second_user_cannot_save(): void
    {
        $userA = $this->makeAdmin('관리일');
        $vehicle = $this->makeVehicle();
        Cache::put($this->lockKey($vehicle->id), ['user_id' => $userA->id, 'name' => '관리일'], 90);

        $this->actingAs($this->makeAdmin('관리이'));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->call('save')
            ->assertStatus(423);
    }

    public function test_locked_second_user_cannot_delete(): void
    {
        $userA = $this->makeAdmin('관리일');
        $vehicle = $this->makeVehicle();
        Cache::put($this->lockKey($vehicle->id), ['user_id' => $userA->id, 'name' => '관리일'], 90);

        $this->actingAs($this->makeAdmin('관리이'));

        Volt::test('erp.vehicles.index')
            ->call('delete', $vehicle->id)
            ->assertStatus(423);
    }

    public function test_owner_acquires_lock_on_open_and_releases_on_close(): void
    {
        $userA = $this->makeAdmin('관리일');
        $vehicle = $this->makeVehicle();
        $this->actingAs($userA);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $vehicle->id);

        $component->assertSet('editLockedByOther', false);
        $lock = Cache::get($this->lockKey($vehicle->id));
        $this->assertSame($userA->id, $lock['user_id'] ?? null, '열면 본인 잠금 획득');

        $component->call('close');
        $this->assertNull(Cache::get($this->lockKey($vehicle->id)), '닫으면 잠금 해제');
    }

    public function test_owner_can_reopen_own_lock_without_block(): void
    {
        $userA = $this->makeAdmin('관리일');
        $vehicle = $this->makeVehicle();
        // 본인 잠금이 이미 있는 상태 — 막히지 않고 갱신.
        Cache::put($this->lockKey($vehicle->id), ['user_id' => $userA->id, 'name' => '관리일'], 90);
        $this->actingAs($userA);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->assertSet('editLockedByOther', false);
    }
}
