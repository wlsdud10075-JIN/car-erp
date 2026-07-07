<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 2 (jin 2026-07-07) — 월배치 정산지급 승인 사다리.
 *
 * [관리](1)→업무관리자(2)→대표(3) 순서 강제. 대표 최종 승인 시 일괄 paid. 직접 paid=대표만.
 */
class SettlementPayoutBatchTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'email_verified_at' => now()]);
    }

    private function confirmedSettlement(string $confirmedAt): Settlement
    {
        $v = Vehicle::create(['vehicle_number' => 'SPB'.++$this->c, 'sales_channel' => 'export']);

        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => $confirmedAt,
        ]);
    }

    public function test_rank_ladder(): void
    {
        $this->assertSame(4, $this->user('super')->approvalRank());
        $this->assertSame(3, $this->user('admin')->approvalRank());
        $this->assertSame(2, $this->user('manager')->approvalRank());
        $this->assertSame(1, $this->user('user', '관리')->approvalRank());
        $this->assertSame(0, $this->user('user', '영업')->approvalRank());
        $this->assertTrue($this->user('user', '관리')->canSubmitPayoutBatch());
        $this->assertTrue($this->user('manager')->canSubmitPayoutBatch());
        $this->assertFalse($this->user('admin')->canSubmitPayoutBatch());
        $this->assertFalse($this->user('user', '영업')->canSubmitPayoutBatch());
    }

    public function test_gwanri_batch_requires_manager_then_representative_in_order(): void
    {
        $this->confirmedSettlement('2026-05-15');
        $this->confirmedSettlement('2026-05-16');
        $gwanri = $this->user('user', '관리');
        $manager = $this->user('manager');
        $admin = $this->user('admin');

        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');
        $this->assertSame('pending', $batch->status);
        $this->assertSame(2, $batch->current_level, '[관리] 제출 → 다음 승인 rank=2(업무관리자)');
        $this->assertSame(2, $batch->settlement_count);
        $this->assertSame(2, Settlement::whereNotNull('payout_batch_id')->count());

        // 순서 강제 — 대표(3)는 아직 승인 불가(업무관리자 먼저).
        $this->assertFalse($batch->canDecide($admin));
        $this->assertTrue($batch->canDecide($manager));

        // 업무관리자 승인 → 다음 계단(3), 아직 미지급.
        $batch->approveBy($manager);
        $batch->refresh();
        $this->assertSame(3, $batch->current_level);
        $this->assertSame('pending', $batch->status);
        $this->assertSame(0, $batch->settlements()->where('settlement_status', 'paid')->count());

        // 대표 최종 승인 → 완료 + 일괄 paid.
        $batch->approveBy($admin);
        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame(2, $batch->settlements()->where('settlement_status', 'paid')->count());
        // paid 전환 시 2차 대기 자동 set (기존 훅).
        $this->assertSame(2, $batch->settlements()->where('secondary_status', 'pending')->count());
        $this->assertSame(2, $batch->approvals()->where('action', 'approved')->count());
    }

    public function test_manager_batch_needs_only_representative(): void
    {
        $this->confirmedSettlement('2026-05-15');
        $manager = $this->user('manager');
        $admin = $this->user('admin');

        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-05');
        $this->assertSame(3, $batch->current_level, '업무관리자 제출 → 대표만');

        $batch->approveBy($admin);
        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame(1, $batch->settlements()->where('settlement_status', 'paid')->count());
    }

    public function test_reject_releases_settlements_for_rebatch(): void
    {
        $this->confirmedSettlement('2026-05-15');
        $gwanri = $this->user('user', '관리');
        $manager = $this->user('manager');

        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');
        $batch->rejectBy($manager, '금액 확인 필요');
        $batch->refresh();

        $this->assertSame('rejected', $batch->status);
        $this->assertSame('금액 확인 필요', $batch->reject_reason);
        $this->assertSame(0, $batch->settlements()->count(), '반려 시 정산 배치 해제');
        $this->assertSame(1, Settlement::whereNull('payout_batch_id')->where('settlement_status', 'confirmed')->count(), '재배치 가능');
    }

    public function test_direct_paid_only_representative(): void
    {
        $s = $this->confirmedSettlement('2026-05-15');

        // manager 직접 paid → 차단
        $this->actingAs($this->user('manager'));
        try {
            $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);
            $this->fail('manager 직접 paid 는 차단돼야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settlement_status', $e->errors());
        }
        $this->assertSame('confirmed', $s->fresh()->settlement_status);

        // admin(대표) 직접 paid → 허용
        $this->actingAs($this->user('admin'));
        $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);
        $this->assertSame('paid', $s->fresh()->settlement_status);
    }

    public function test_empty_month_throws(): void
    {
        $gwanri = $this->user('user', '관리');
        $this->expectException(\DomainException::class);
        SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');
    }
}
