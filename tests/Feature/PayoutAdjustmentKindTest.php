<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 조정 종류(kind) — 월정산 v3 1일차 (2026-10-09). 사유 문자열 대신 칸으로 갈린다. */
class PayoutAdjustmentKindTest extends TestCase
{
    use RefreshDatabase;

    private function pendingBatch(): array
    {
        $manager = User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $batch = SettlementPayoutBatch::create([
            'month' => '2026-10', 'submitter_id' => $manager->id, 'submitter_rank' => 2, 'current_level' => 3,
            'status' => 'pending', 'total_payout' => 0, 'settlement_count' => 0, 'submitted_at' => now(),
        ]);

        return [$batch, $manager, $sm];
    }

    public function test_default_is_manual_and_loss_is_inferred_from_vehicle_ids(): void
    {
        [$batch, $by, $sm] = $this->pendingBatch();

        $a = $batch->addAdjustment($by, $sm->id, 100_000, '수기');
        $this->assertSame(SettlementPayoutAdjustment::KIND_MANUAL, $a->kind);

        $b = $batch->addAdjustment($by, $sm->id, -50_000, '매입취소 손실', [11, 12]);
        $this->assertSame(SettlementPayoutAdjustment::KIND_LOSS, $b->kind);

        $c = $batch->addAdjustment($by, $sm->id, 300_000, '10월 실적 우수', null, SettlementPayoutAdjustment::KIND_INCENTIVE);
        $this->assertSame(SettlementPayoutAdjustment::KIND_INCENTIVE, $c->fresh()->kind);
    }

    public function test_unknown_kind_is_refused(): void
    {
        [$batch, $by, $sm] = $this->pendingBatch();
        $this->expectException(\DomainException::class);
        $batch->addAdjustment($by, $sm->id, 100_000, '사유', null, 'bonus');
    }
}
