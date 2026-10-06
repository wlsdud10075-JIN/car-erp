<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📅 **귀속월 이동 — 드롭박스로 목적지를 고른다** (jin 2026-10-06 1차 한 칸 버튼 → 같은 날 2차 드롭박스).
 *
 * 1차 「한 칸」은 목적지마다 마감을 봐서 **승인된 달을 건너뛸 수 없었다** — 실사례 ssancarerp 14더3753:
 * 07 귀속 → 09(10/10 지급)로 가야 하는데 08 이 마감이라 두 번 눌러도 막혔다. jin: *「월을 선택할 수 있게 해주면
 * 2달을 건너뛰든 할 수 있을 것 같은데」*. 결정: 목적지 하나만 검사 · 마감/제출중 달은 선택지에서 뺌 ·
 * 창 = 오늘 기준 뒤 6개월·앞 1개월 · 권한 = 정산 확정 범위(canConfirmFinance) · 감사로그 한 줄.
 */
class SettlementAttributedMonthShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 창(오늘 ±)이 달력에 묶이지 않도록 「오늘」을 고정한다 — 2026-10 기준 창 = 2026-04 ~ 2026-11.
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    private function move(Settlement $s, string $ym)
    {
        return Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('shiftTargetYm', $ym)
            ->call('moveAttributedMonth');
    }

    /** 직전 달로 — 10월 귀속이 9월로, 감사로그에 old/new 가 남는다. */
    public function test_pull_back_to_previous_month_and_log_it(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->actingAs($this->finance());

        $this->move($s, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'success' && str_contains($params['message'], '2026-09') && str_contains($params['message'], '2026-10-10'));

        $this->assertSame('2026-09-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $log = AuditLog::where('auditable_type', Settlement::class)->where('auditable_id', $s->id)->where('column_name', 'attributed_month')->first();
        $this->assertNotNull($log, '귀속월 변경은 감사로그에 남아야 한다');
        $this->assertStringContainsString('2026-10-01', (string) $log->old_value);
        $this->assertStringContainsString('2026-09-01', (string) $log->new_value);
    }

    /** 다음 달로 — 반대 방향도 된다. */
    public function test_push_forward_to_next_month(): void
    {
        $s = $this->settlement('2026-09-01');
        $this->actingAs($this->finance());

        $this->move($s, '2026-10');

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /**
     * 🔑 **마감된 달을 건너뛴다** — 이 테스트가 2차의 존재 이유다. 07 귀속, 08 은 지급 승인(마감), 목적지 09.
     *    1차 한 칸 이동은 08 에서 막혔다. 목적지만 보므로 넘어가고, 로그는 07→09 한 줄이다.
     */
    public function test_can_jump_over_a_closed_month(): void
    {
        $s = $this->settlement('2026-07-01');
        $this->batch('2026-08', SettlementPayoutBatch::STATUS_APPROVED);
        $this->actingAs($this->finance());

        $this->move($s, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'success' && str_contains($params['message'], '2026-09'));

        $this->assertSame('2026-09-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $this->assertSame(1, AuditLog::where('auditable_id', $s->id)->where('column_name', 'attributed_month')->count(), '한 번 옮기면 로그도 한 줄');
    }

    /** 드롭박스 선택지 = 창 안에서 마감·제출중·지금 달을 뺀 것. 값은 그 달의 지급일. */
    public function test_movable_months_exclude_closed_pending_and_current(): void
    {
        $this->batch('2026-08', SettlementPayoutBatch::STATUS_APPROVED);
        $this->batch('2026-06', SettlementPayoutBatch::STATUS_PENDING);

        $months = Settlement::movableMonths(null, '2026-07');

        $this->assertSame(['2026-04', '2026-05', '2026-09', '2026-10', '2026-11'], array_keys($months));
        $this->assertSame('2026-10-10', $months['2026-09'], '값은 지급일(다음 달 10일)');

        // 화면에도 같은 목록이 그려진다 — 마감된 08 은 옵션으로 안 나온다
        $s = $this->settlement('2026-07-01');
        $this->actingAs($this->finance());
        $html = Volt::test('erp.settlements.index')->call('openEdit', $s->id)->html();
        $this->assertMatchesRegularExpression('/data-shift-month[\s\S]*?<option value="2026-09">/u', $html, '09 옵션이 없다');
        $this->assertDoesNotMatchRegularExpression('/data-shift-month[\s\S]*?<option value="2026-08">/u', $html, '마감된 08 이 옵션에 있다');
    }

    /** 🚫 대상 달이 마감(지급 승인 배치 존재)이면 안 옮겨진다 — 옵션에 없어도 값을 밀어 넣을 수 있으므로 모델이 다시 본다. */
    public function test_blocked_when_target_month_is_closed(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->batch('2026-09', SettlementPayoutBatch::STATUS_APPROVED);
        $this->actingAs($this->finance());

        $this->move($s, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error' && str_contains($params['message'], '마감'));

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $this->assertSame(0, AuditLog::where('column_name', 'attributed_month')->count(), '막혔으면 로그도 없다');
    }

    /** 🚫 대상 달 배치가 제출돼 승인 대기 중이면 막는다 — 옮기면 그 배치에서 빠져 지급이 안 된다. */
    public function test_blocked_when_target_month_batch_is_pending(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->batch('2026-09', SettlementPayoutBatch::STATUS_PENDING);
        $this->actingAs($this->finance());

        $this->move($s, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error' && str_contains($params['message'], '승인 대기'));

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 🚫 창 밖(오늘 기준 6개월 전보다 더 과거 · 2개월 뒤)·같은 달·형식 오류는 거부. 오타 한 번에 2030년으로 가지 않는다. */
    public function test_blocked_outside_the_window_or_same_month(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->actingAs($this->finance());

        foreach (['2026-03', '2026-12', '2030-01', '2026-10', 'garbage'] as $bad) {
            $this->move($s, $bad)->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error');
            $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'), "{$bad} 로 옮겨졌다");
        }
        $this->assertSame(0, AuditLog::where('column_name', 'attributed_month')->count());
    }

    /** 🚫 배치에 묶였거나 지급된 정산은 안 움직인다 — 드롭박스 자체가 안 그려지고, 밀어 넣어도 모델이 막는다. */
    public function test_blocked_when_bound_to_a_batch_or_paid(): void
    {
        $this->actingAs($this->finance());

        $bound = $this->settlement('2026-10-01');
        $batch = $this->batch('2026-10', SettlementPayoutBatch::STATUS_PENDING);
        Settlement::whereKey($bound->id)->update(['payout_batch_id' => $batch->id]);
        $this->move($bound, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error')
            ->assertDontSee('wire:model="shiftTargetYm"', false);   // 잠기면 안내 상자만 남고 셀렉트는 없다
        $this->assertSame('2026-10-01', $bound->fresh()->attributed_month->format('Y-m-d'));

        $paid = $this->settlement('2026-10-01');
        Settlement::whereKey($paid->id)->update(['settlement_status' => 'paid', 'paid_at' => now()]);
        $this->move($paid, '2026-09')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error');
        $this->assertSame('2026-10-01', $paid->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 1차의 한 칸 메서드는 래퍼로 남는다(돈 흐름 E2E 가 그걸 부른다) — 같은 가드를 탄다. */
    public function test_one_step_wrapper_still_delegates_to_the_same_guards(): void
    {
        $s = $this->settlement('2026-10-01');
        $this->batch('2026-09', SettlementPayoutBatch::STATUS_APPROVED);

        $this->expectException(\DomainException::class);
        $s->shiftAttributedMonth(-1);
    }

    /** 🚫 영업은 못 한다 — 정산 확정 범위만. */
    public function test_sales_role_is_forbidden(): void
    {
        $s = $this->settlement('2026-10-01');
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        $this->move($s, '2026-09')->assertForbidden();
        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }
}
