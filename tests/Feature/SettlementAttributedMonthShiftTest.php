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
 * 📅 **귀속월 한 칸 이동** (jin 2026-10-06).
 *
 * 9월 판매 건의 자투리 수수료를 10월 초에 완납 처리하면 귀속월이 10월(= 11/10 지급)로 잡힌다.
 * 정산처리 드로어에서 9월(= 10/10 지급)로 당긴다. 결정: 양방향 · 직전/다음 달 한 칸만 · 대상 달 배치가 제출 중이면 차단 ·
 * 권한 = 정산 확정 범위(canConfirmFinance).
 */
class SettlementAttributedMonthShiftTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function settlement(string $month = '2026-10-01'): Settlement
    {
        $sm = Salesman::create(['name' => '담당', 'type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '12가3456', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => 10_000, 'sale_date' => '2026-09-05',
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'pending', 'attributed_month' => $month,
        ]);
    }

    private function batch(string $month, string $status): SettlementPayoutBatch
    {
        return SettlementPayoutBatch::forceCreate([
            'month' => $month, 'submitter_id' => $this->finance()->id, 'submitter_rank' => 1, 'current_level' => 2, 'status' => $status,
        ]);
    }

    /** ◀ 직전 달로 — 10월 귀속이 9월로, 감사로그에 old/new 가 남는다. */
    public function test_pull_back_to_previous_month_and_log_it(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->actingAs($this->finance());

        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'success' && str_contains($params['message'], '2026-09') && str_contains($params['message'], '2026-10-10'));

        $this->assertSame('2026-09-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $log = AuditLog::where('auditable_type', Settlement::class)->where('auditable_id', $s->id)->where('column_name', 'attributed_month')->first();
        $this->assertNotNull($log, '귀속월 변경은 감사로그에 남아야 한다');
        $this->assertStringContainsString('2026-10-01', (string) $log->old_value);
        $this->assertStringContainsString('2026-09-01', (string) $log->new_value);
    }

    /** 다음 달로 ▶ — 반대 방향도 된다. */
    public function test_push_forward_to_next_month(): void
    {
        $s = $this->settlement('2026-09-01');
        $this->actingAs($this->finance());

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', 1);

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 🚫 대상 달이 마감(지급 승인 배치 존재)이면 안 옮겨진다. */
    public function test_blocked_when_target_month_is_closed(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->batch('2026-09', SettlementPayoutBatch::STATUS_APPROVED);
        $this->actingAs($this->finance());

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error' && str_contains($params['message'], '마감'));

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $this->assertSame(0, AuditLog::where('column_name', 'attributed_month')->count(), '막혔으면 로그도 없다');
    }

    /** 🚫 (가) 대상 달 배치가 제출돼 승인 대기 중이면 막는다 — 옮기면 그 배치에서 빠져 지급이 안 된다. */
    public function test_blocked_when_target_month_batch_is_pending(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->batch('2026-09', SettlementPayoutBatch::STATUS_PENDING);
        $this->actingAs($this->finance());

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error' && str_contains($params['message'], '승인 대기'));

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 🚫 배치에 묶였거나 지급된 정산은 안 움직인다. */
    public function test_blocked_when_bound_to_a_batch_or_paid(): void
    {
        $this->actingAs($this->finance());

        $bound = $this->settlement('2026-10-01');
        $batch = $this->batch('2026-10', SettlementPayoutBatch::STATUS_PENDING);
        Settlement::whereKey($bound->id)->update(['payout_batch_id' => $batch->id]);
        Volt::test('erp.settlements.index')->call('openEdit', $bound->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error');
        $this->assertSame('2026-10-01', $bound->fresh()->attributed_month->format('Y-m-d'));

        $paid = $this->settlement('2026-10-01');
        Settlement::whereKey($paid->id)->update(['settlement_status' => 'paid', 'paid_at' => now()]);
        Volt::test('erp.settlements.index')->call('openEdit', $paid->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error');
        $this->assertSame('2026-10-01', $paid->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 한 칸씩만 — 두 달은 모델이 거부한다(1차 정산 뒤라 말이 안 된다, jin). */
    public function test_only_one_step_is_allowed(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->expectException(\InvalidArgumentException::class);
        $s->shiftAttributedMonth(-2);
    }

    /** 🚫 영업은 못 한다 — 정산 확정 범위만. */
    public function test_sales_role_is_forbidden(): void
    {
        $s = $this->settlement('2026-10-01');
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', -1)->assertForbidden();
        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }
}
