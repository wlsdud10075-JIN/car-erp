<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 월배치 수동 조정란 (jin 2026-07-08) — 정산 공식 밖의 담당자별 +/− 조정.
 * 배치 총액에만 반영, 개별 정산 무손상, pending에서만 편집, 사유 필수, 감사로그.
 */
class SettlementPayoutAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'email_verified_at' => now()]);
    }

    /** @return array{0: SettlementPayoutBatch, 1: Salesman, 2: User} */
    private function pendingBatch(): array
    {
        $salesman = Salesman::create(['name' => '김영업', 'is_active' => true, 'type' => 'employee']);
        for ($i = 0; $i < 2; $i++) {
            $v = Vehicle::create(['vehicle_number' => 'ADJ'.++$this->c, 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
            Settlement::create([
                'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
                'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
                'settlement_status' => 'confirmed', 'confirmed_at' => '2026-05-15',
            ]);
        }
        $gwanri = $this->user('user', '관리');
        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-05');

        return [$batch, $salesman, $gwanri];
    }

    public function test_adjustment_recomputes_batch_total(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $this->assertSame(200_000, (int) $batch->total_payout, '정산 2건 × 10만');

        $batch->addAdjustment($gwanri, $salesman->id, -729_250, '62두1461 6월 과지급 환수');

        $this->assertSame(1, $batch->adjustments()->count());
        // 음수라도 배치 총액은 max(0,...) — 20만 − 72.9만 = 0 바닥
        $this->assertSame(0, (int) $batch->fresh()->total_payout);
        $this->assertSame(1, AuditLog::where('action', 'payout_adjustment_added')->count());
    }

    public function test_negative_and_positive_net(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();   // 200,000
        $batch->addAdjustment($gwanri, $salesman->id, -50_000, '환수');
        $batch->addAdjustment($gwanri, $salesman->id, 30_000, '특별지급');

        $this->assertSame(180_000, (int) $batch->fresh()->total_payout);   // 200 − 50 + 30
    }

    public function test_remove_adjustment_restores_total(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $adj = $batch->addAdjustment($gwanri, $salesman->id, -50_000, '환수');
        $this->assertSame(150_000, (int) $batch->fresh()->total_payout);

        $batch->removeAdjustment($gwanri, $adj->id);
        $this->assertSame(200_000, (int) $batch->fresh()->total_payout);
        $this->assertSame(1, AuditLog::where('action', 'payout_adjustment_removed')->count());
    }

    public function test_reason_required_and_nonzero(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $this->expectException(\DomainException::class);
        $batch->addAdjustment($gwanri, $salesman->id, -50_000, '   ');
    }

    public function test_zero_amount_rejected(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $this->expectException(\DomainException::class);
        $batch->addAdjustment($gwanri, $salesman->id, 0, '사유');
    }

    public function test_locked_after_approved(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $manager = $this->user('manager');
        $admin = $this->user('admin');
        $batch->approveBy($manager);   // rank2
        $batch->approveBy($admin);     // rank3=TOP → paid
        $this->assertSame('approved', $batch->fresh()->status);

        $this->expectException(\DomainException::class);
        $batch->fresh()->addAdjustment($gwanri, $salesman->id, -50_000, '뒤늦은 조정');
    }

    public function test_sales_role_cannot_adjust(): void
    {
        [$batch, $salesman] = $this->pendingBatch();
        $sales = $this->user('user', '영업');
        $this->expectException(\DomainException::class);
        $batch->addAdjustment($sales, $salesman->id, -50_000, '권한없음');
    }

    public function test_individual_settlements_untouched(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $batch->addAdjustment($gwanri, $salesman->id, -50_000, '환수');
        // 조정은 배치 레벨 — 정산 row 의 상태·금액 불변
        foreach ($batch->settlements as $s) {
            $this->assertSame('confirmed', $s->settlement_status);
            $this->assertSame(100_000, (int) $s->actual_payout);
        }
    }

    // jin 2026-07-14 — 조정이 배치 총액뿐 아니라 담당자 개인 소계에도 반영돼 표시된다.
    public function test_ui_person_subtotal_reflects_adjustment(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();   // 김영업 2건 × 10만 = 20만
        $batch->addAdjustment($gwanri, $salesman->id, -50_000, '환수');   // net 15만
        $this->actingAs($gwanri);

        Volt::test('erp.payout-batches.index')
            ->call('toggle', $batch->id)                       // 드릴다운 펼침
            ->assertSee('150,000')                             // 개인 소계 = net (20만 − 5만)
            ->assertSee(__('payout_batch.adjust.reflected'))   // 조정 반영 표식
            ->assertDontSee('200,000');                        // 더 이상 gross 소계 아님
    }

    public function test_ui_add_adjustment_through_component(): void
    {
        [$batch, $salesman, $gwanri] = $this->pendingBatch();
        $this->actingAs($gwanri);

        Volt::test('erp.payout-batches.index')
            ->call('toggle', $batch->id)
            ->call('startAdjust', $batch->id)
            ->set('adjSalesmanId', (string) $salesman->id)
            ->set('adjAmount', '-729,250')
            ->set('adjReason', '62두1461 6월 과지급 환수')
            ->call('addAdjustment', $batch->id)
            ->assertDispatched('notify');

        $this->assertSame(1, $batch->adjustments()->count());
        $this->assertSame(-729_250, (int) $batch->adjustments()->first()->amount);   // 콤마·음수 파싱
    }
}
