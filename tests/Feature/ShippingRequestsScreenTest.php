<?php

namespace Tests\Feature;

use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ShippingRequestsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function salesUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
    }

    private function batch(string $batchId, array $vehicleNumbers, string $status = 'requested'): void
    {
        foreach ($vehicleNumbers as $vn) {
            $v = Vehicle::create(['vehicle_number' => $vn, 'sales_channel' => 'export']);
            ShippingRequest::create([
                'batch_id' => $batchId,
                'vehicle_id' => $v->id,
                'shipping_method' => 'RORO',
                'requested_by_email' => 's@a.com',
                'status' => $status,
                'requested_at' => now(),
            ]);
        }
    }

    public function test_groups_requests_by_batch(): void
    {
        $this->batch('batch-A', ['11가1111', '22나2222', '33다3333']);
        $this->batch('batch-B', ['44라4444']);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->assertSee('11가1111')
            ->assertSee('33다3333')
            ->assertSee('44라4444')
            ->assertViewHas('batches', fn ($b) => $b->count() === 2);   // 3대·1대 = 2배치
    }

    public function test_done_transition_updates_whole_batch_and_resolves_alarm(): void
    {
        $this->batch('batch-A', ['11가1111', '22나2222']);
        // board store 가 만들 듯 연동 알람 부여
        foreach (ShippingRequest::all() as $r) {
            TaskAlarm::create(['type' => 'shipping_requested', 'vehicle_id' => $r->vehicle_id, 'target_role' => '수출통관', 'due_date' => now()]);
        }

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')->call('changeStatus', 'batch-A', 'done');

        // 배치 전체 done + processed_at
        $this->assertSame(2, ShippingRequest::where('batch_id', 'batch-A')->where('status', 'done')->whereNotNull('processed_at')->count());
        // 연동 알람 전부 resolve (벨/알림 카운트 정합)
        $this->assertSame(0, TaskAlarm::where('type', 'shipping_requested')->whereNull('resolved_at')->count());
    }

    public function test_status_filter(): void
    {
        $this->batch('batch-A', ['11가1111'], 'requested');
        $this->batch('batch-B', ['22나2222'], 'done');

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->call('setStatus', 'done')
            ->assertSee('22나2222')
            ->assertDontSee('11가1111');
    }

    public function test_non_clearance_user_forbidden(): void
    {
        $this->batch('batch-A', ['11가1111']);
        $this->actingAs($this->salesUser());

        // mount + transition 동일 게이트(canAccessClearance) — 영업은 진입 자체 403
        Volt::test('erp.shipping-requests.index')->assertStatus(403);
    }
}
