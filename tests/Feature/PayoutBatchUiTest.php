<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Phase 2 UI — 월배치 제출(정산화면) + 승인큐(승인/반려).
 */
class PayoutBatchUiTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'email_verified_at' => now()]);
    }

    private function confirmed(string $at): Settlement
    {
        $v = Vehicle::create(['vehicle_number' => 'PBU'.++$this->c, 'sales_channel' => 'export']);

        return Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => $at,
        ]);
    }

    public function test_submit_batch_from_settlements_screen(): void
    {
        $this->confirmed('2026-05-15');
        $this->confirmed('2026-05-16');
        $this->actingAs($this->user('user', '관리'));

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('submitPayoutBatch')
            ->assertHasNoErrors();

        $batch = SettlementPayoutBatch::first();
        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->settlement_count);
        $this->assertSame('pending', $batch->status);
    }

    public function test_queue_approve_chain_and_reject(): void
    {
        $this->confirmed('2026-05-15');
        $gwanri = $this->user('user', '관리');
        $manager = $this->user('manager');
        $admin = $this->user('admin');
        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');

        // 업무관리자 승인 → 다음 계단
        $this->actingAs($manager);
        Volt::test('erp.payout-batches.index')->call('approve', $batch->id)->assertHasNoErrors();
        $this->assertSame(3, $batch->fresh()->current_level);
        $this->assertSame('pending', $batch->fresh()->status);

        // 대표 최종 승인 → paid
        $this->actingAs($admin);
        Volt::test('erp.payout-batches.index')->call('approve', $batch->id)->assertHasNoErrors();
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame(1, $batch->settlements()->where('settlement_status', 'paid')->count());
    }

    public function test_queue_reject_releases(): void
    {
        $this->confirmed('2026-05-15');
        $gwanri = $this->user('user', '관리');
        $manager = $this->user('manager');
        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');

        $this->actingAs($manager);
        Volt::test('erp.payout-batches.index')
            ->call('startReject', $batch->id)
            ->set('rejectReason', '금액 재확인')
            ->call('confirmReject')
            ->assertHasNoErrors();

        $this->assertSame('rejected', $batch->fresh()->status);
        $this->assertSame(1, Settlement::whereNull('payout_batch_id')->where('settlement_status', 'confirmed')->count());
    }

    public function test_wrong_level_approver_gets_error(): void
    {
        $this->confirmed('2026-05-15');
        $gwanri = $this->user('user', '관리');
        $admin = $this->user('admin');
        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');   // current_level=2 (업무관리자 차례)

        // 대표(3)가 업무관리자 단계(2)를 건너뛰고 승인 시도 → 에러 토스트, 상태 불변
        $this->actingAs($admin);
        Volt::test('erp.payout-batches.index')->call('approve', $batch->id);
        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->current_level);
    }
}
