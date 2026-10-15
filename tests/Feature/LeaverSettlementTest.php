<?php

namespace Tests\Feature;

use App\Models\CarryoverClearance;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\BatchPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚪 퇴사자 정산 (jin 2026-10-15 「퇴사자 사용 씬」 · «이중으로 정산되지 않게만 잘 설계해줘»).
 *
 * - 퇴사일은 사내직원관리 칸(`salesmen.left_at`). 사용자 관리 미러가 활성을 되살리지 않는다.
 * - 프리랜서 퇴사자 = 정산관리 「퇴사자」에서 즉시 정산 배치(`kind=leaver`). 월정산 후보에서 빠진다.
 * - 사내직원·급여형 퇴사자 = 종전대로 월정산(비활성이어도 마지막 달 급여 줄이 남는다).
 * - 퇴사 배치는 달을 닫지 않고(`isMonthClosed`), 월당 pending 1건 검사와 무관하다.
 * - 이중 지급 금지 — 같은 정산이 두 배치에 못 들어가고, 이월은 한 번만 흡수된다.
 */
class LeaverSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission = 'manager', string $role = '관리'): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role,
            'phone' => '0101234'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now(),
        ]);
    }

    private function freelancer(?string $leftAt = '2026-10-12'): Salesman
    {
        return Salesman::create(['name' => '프리'.++$this->n, 'type' => 'freelance', 'is_active' => $leftAt === null, 'left_at' => $leftAt]);
    }

    private function employee(?string $leftAt = null): Salesman
    {
        return Salesman::create(['name' => '직원'.++$this->n, 'type' => 'employee', 'is_active' => $leftAt === null, 'left_at' => $leftAt, 'per_unit_tier_enabled' => false]);
    }

    /** 완납 차량 + 확정 정산(귀속월 = $month). 프리랜서는 ratio, 사내직원은 per_unit 10만. */
    private function confirmed(Salesman $sm, string $month = '2026-10', bool $paidUp = true): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'LV'.++$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => $month.'-01',
            'sale_price' => 20_000, 'sale_date' => $month.'-02',
        ]);
        if ($paidUp) {
            $v->finalPayments()->create(['type' => 'balance', 'amount' => 20_000, 'exchange_rate' => 1400, 'payment_date' => $month.'-10', 'confirmed_at' => now()]);
        }

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_status' => 'confirmed', 'confirmed_at' => $month.'-15', 'attributed_month' => $month.'-01',
        ], $sm->type === 'freelance'
            ? ['settlement_type' => 'ratio', 'settlement_ratio' => 50]
            : ['settlement_type' => 'per_unit', 'per_unit_amount' => 100_000]));
    }

    // ── 퇴사일 칸 ─────────────────────────────────────────────────────────

    public function test_user_admin_save_does_not_revive_a_leaver(): void
    {
        $admin = $this->user('admin');
        $u = User::factory()->create(['permission' => 'user', 'role' => '영업', 'type' => 'freelance', 'phone' => '01011112222']);
        $sm = Salesman::create(['user_id' => $u->id, 'name' => $u->name, 'type' => 'freelance', 'is_active' => false, 'left_at' => '2026-10-12']);

        $this->actingAs($admin);
        Volt::test('admin.users.index')->call('openEdit', $u->id)->set('phone', '01099998888')->call('save')->assertHasNoErrors();

        $sm->refresh();
        $this->assertFalse($sm->is_active, '전화번호만 고쳤는데 퇴사가 풀리면 안 된다');
        $this->assertSame('2026-10-12', $sm->left_at->format('Y-m-d'));

        // 재직자는 종전대로 활성으로 되살아난다(미러 규칙 그대로)
        $u2 = User::factory()->create(['permission' => 'user', 'role' => '영업', 'type' => 'employee', 'phone' => '01011113333']);
        Salesman::create(['user_id' => $u2->id, 'name' => $u2->name, 'type' => 'employee', 'is_active' => false]);
        Volt::test('admin.users.index')->call('openEdit', $u2->id)->call('save')->assertHasNoErrors();
        $this->assertTrue(Salesman::where('user_id', $u2->id)->first()->is_active);
    }

    public function test_roster_panel_sets_left_at_and_turns_inactive(): void
    {
        $this->actingAs($this->user('admin'));
        $sm = $this->freelancer(null);

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('left_at', '2026-10-12')->call('save')->assertHasNoErrors();
        $sm->refresh();
        $this->assertSame('2026-10-12', $sm->left_at->format('Y-m-d'));
        $this->assertFalse($sm->is_active, '퇴사일을 적으면 활성이 꺼진다');
        $this->assertTrue($sm->settlesImmediatelyOnLeave());

        // 비우면 퇴사 해제(활성은 손대지 않는다 — 사람이 체크로)
        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('left_at', '')->set('is_active', true)->call('save')->assertHasNoErrors();
        $sm->refresh();
        $this->assertNull($sm->left_at);
        $this->assertTrue($sm->is_active);
    }

    // ── 월정산 후보에서 프리랜서 퇴사자 제외, 사내직원 퇴사자는 그대로 ───────────────

    public function test_monthly_batch_skips_freelance_leavers_but_keeps_employee_leavers(): void
    {
        $leaverFree = $this->freelancer();
        $leaverEmp = $this->employee('2026-10-12');
        $staying = $this->freelancer(null);
        $sF = $this->confirmed($leaverFree);
        $sE = $this->confirmed($leaverEmp);
        $sS = $this->confirmed($staying);
        PayrollEntry::replaceFor($leaverEmp->id, '2026-10', [['label' => '기본급', 'amount' => 1_500_000]]);

        $ids = SettlementPayoutBatch::eligibleSettlementIds('2026-10');
        $this->assertEqualsCanonicalizing([$sE->id, $sS->id], $ids->all(), '프리랜서 퇴사자 정산만 빠진다');
        $this->assertTrue($sF->fresh()->isReservedForLeaverBatch());
        $this->assertFalse($sE->fresh()->isReservedForLeaverBatch(), '사내직원 퇴사자는 월정산');

        $batch = SettlementPayoutBatch::submitForMonth($this->user(), '2026-10');
        $people = collect(BatchPayoutBreakdown::forBatch($batch)['people'])->keyBy('salesman_id');
        $this->assertSame(1_500_000, $people[$leaverEmp->id]['payroll'], '비활성이어도 퇴사 사내직원의 마지막 달 급여 줄이 남는다');
        $this->assertArrayNotHasKey($leaverFree->id, $people->all());
        $this->assertNull($sF->fresh()->payout_batch_id);
    }

    public function test_monthly_payroll_line_still_drops_plain_inactive_rows(): void
    {
        $inactive = Salesman::create(['name' => '옛계정', 'type' => 'employee', 'is_active' => false]);   // 퇴사일 없음 = 종전 비활성
        PayrollEntry::replaceFor($inactive->id, '2026-10', [['label' => '기본급', 'amount' => 1_000_000]]);
        $this->confirmed($this->employee());
        $batch = SettlementPayoutBatch::submitForMonth($this->user(), '2026-10');
        $this->assertArrayNotHasKey($inactive->id, collect(BatchPayoutBreakdown::forBatch($batch)['people'])->keyBy('salesman_id')->all());
    }

    // ── 퇴사 배치 ────────────────────────────────────────────────────────

    public function test_leaver_batch_pays_only_that_person_and_never_closes_the_month(): void
    {
        $leaver = $this->freelancer('2026-10-12');
        $other = $this->freelancer(null);
        $s1 = $this->confirmed($leaver, '2026-09');
        $s2 = $this->confirmed($leaver, '2026-10');
        $sOther = $this->confirmed($other, '2026-10');
        $manager = $this->user();
        $admin = $this->user('admin');

        $batch = SettlementPayoutBatch::submitForLeaver($manager, $leaver, [['amount' => -300_000, 'reason' => '예치금 반환 상계', 'kind' => 'manual']]);

        $this->assertSame(SettlementPayoutBatch::KIND_LEAVER, $batch->kind);
        $this->assertSame($leaver->id, (int) $batch->salesman_id);
        $this->assertSame('2026-10', $batch->month, '퇴사월');
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $batch->settlements()->pluck('id')->all(), '달과 무관하게 그 사람 것 전부');
        $this->assertNull($sOther->fresh()->payout_batch_id);
        $this->assertSame($leaver->id, (int) $batch->adjustments()->first()->salesman_id, '조정은 그 사람으로 고정');

        // 같은 달 월정산은 그대로 올라간다(월당 pending 1건은 월정산끼리만)
        $monthly = SettlementPayoutBatch::submitForMonth($manager, '2026-10');
        $this->assertSame([$sOther->id], $monthly->settlements()->pluck('id')->all(), '퇴사자 정산은 이미 퇴사 배치 소속 — 두 번 안 들어간다');

        $batch->approveBy($admin);
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('paid', $s1->fresh()->settlement_status);
        $this->assertFalse(SettlementPayoutBatch::isMonthClosed('2026-10'), '퇴사 배치 승인은 달을 닫지 않는다');
        $this->assertFalse(SettlementPayoutBatch::isMonthClosed('2026-09'));

        $monthly->approveBy($admin);
        $this->assertTrue(SettlementPayoutBatch::isMonthClosed('2026-10'), '월정산 승인이 닫는다');
    }

    public function test_leaver_batch_guards(): void
    {
        $manager = $this->user();
        $emp = $this->employee('2026-10-12');
        $this->confirmed($emp);
        try {
            SettlementPayoutBatch::submitForLeaver($manager, $emp);
            $this->fail('사내직원 퇴사자는 퇴사 배치 대상이 아니다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('프리랜서', $e->getMessage());
        }

        $free = $this->freelancer();
        try {
            SettlementPayoutBatch::submitForLeaver($manager, $free);
            $this->fail('올릴 것이 없으면 거부');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('올릴 것이 없습니다', $e->getMessage());
        }

        $this->confirmed($free);
        $held = $this->confirmed($free, '2026-10', paidUp: false);   // 미수 → 보류
        try {
            SettlementPayoutBatch::submitForLeaver($manager, $free);
            $this->fail('미수 보류가 남아 있으면 못 올린다(jin «전부 정리되어야 정산»)');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('미수 보류 1건', $e->getMessage());
        }
        $this->assertSame(['unconfirmed' => 0, 'held' => 1], SettlementPayoutBatch::leaverBlockers($free));
        // 「예외 대상」(gate override)으로 등록하면 보류가 풀려 올라간다
        $held->forceFill(['gate_override_at' => now(), 'gate_override_by' => $manager->id])->saveQuietly();
        $this->assertSame(['unconfirmed' => 0, 'held' => 0], SettlementPayoutBatch::leaverBlockers($free));
        $batch = SettlementPayoutBatch::submitForLeaver($manager, $free);
        $this->assertSame(2, $batch->settlement_count, '예외 등록된 미수 건은 같이 올라간다');
        try {
            SettlementPayoutBatch::submitForLeaver($manager, $free);
            $this->fail('결재 중이면 또 못 올린다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('이미 승인 대기', $e->getMessage());
        }

        // 반려 → 정산이 풀리고 다시 올릴 수 있다(종전 규칙 그대로)
        $batch->rejectBy($this->user('admin'), '다시');
        $this->assertSame(2, SettlementPayoutBatch::leaverEligibleSettlementIds($free)->count());
    }

    public function test_exception_is_registered_from_the_leaver_table(): void
    {
        $this->actingAs($this->user('admin'));
        $leaver = $this->freelancer();
        $held = $this->confirmed($leaver, '2026-10', paidUp: false);

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->call('toggleLeavers');
        $c->assertSeeHtml('data-leaver-exception="'.$held->id.'"')->assertSeeHtml('data-leaver-blocked')
            ->call('startGateOverrideForSettlement', $held->id)->set('gateReason', '퇴사 정산 — 미수는 별도 회수')->call('confirmGateOverride')->assertHasNoErrors();

        $this->assertTrue($held->fresh()->hasGateOverride());
        $c->assertDontSeeHtml('data-leaver-blocked')->assertSee('예외 대상');
        $this->assertSame(['unconfirmed' => 0, 'held' => 0], SettlementPayoutBatch::leaverBlockers($leaver));
    }

    public function test_bulk_confirm_skips_freelance_leavers(): void
    {
        $this->actingAs($this->user('admin'));
        $leaver = $this->freelancer();
        $emp = $this->employee('2026-10-12');
        $staying = $this->freelancer(null);
        $sL = $this->confirmed($leaver);
        $sE = $this->confirmed($emp);
        $sS = $this->confirmed($staying);
        Settlement::whereIn('id', [$sL->id, $sE->id, $sS->id])->update(['settlement_status' => 'pending', 'confirmed_at' => null]);

        Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->call('confirmMonth');

        $this->assertSame('pending', $sL->fresh()->settlement_status, '프리랜서 퇴사자 건은 일괄확정에서 빠진다(퇴사자 정산에서 하나씩)');
        $this->assertSame('confirmed', $sE->fresh()->settlement_status, '사내직원 퇴사자는 월정산 흐름 그대로');
        $this->assertSame('confirmed', $sS->fresh()->settlement_status);
    }

    public function test_carryover_is_absorbed_once_across_leaver_and_monthly_batches(): void
    {
        $manager = $this->user();
        $leaver = $this->freelancer();
        $closed = $this->confirmed($leaver, '2026-08');
        $closed->forceFill(['settlement_status' => 'paid', 'secondary_status' => 'closed', 'carryover_out_krw' => 120_000])->saveQuietly();
        $this->assertSame(120_000, $leaver->fresh()->unconsumed_carryover);
        $this->confirmed($this->freelancer(null), '2026-10');   // 월정산용 남의 정산

        // 월정산은 프리랜서 퇴사자 이월을 건너뛴다
        $this->assertSame([], SettlementPayoutBatch::carryoverLinesFor(collect()));

        $batch = SettlementPayoutBatch::submitForLeaver($manager, $leaver);   // 정산 0건 + 이월만
        $this->assertSame(0, $batch->settlement_count);
        $this->assertSame(120_000, (int) $batch->total_payout);
        $this->assertSame(0, $leaver->fresh()->unconsumed_carryover, '제출 시 청산 기록으로 0');
        $this->assertSame(1, CarryoverClearance::where('payout_batch_id', $batch->id)->count());

        $monthly = SettlementPayoutBatch::submitForMonth($manager, '2026-10');
        $this->assertSame(0, $monthly->adjustments()->where('salesman_id', $leaver->id)->count(), '월정산이 같은 이월을 다시 가져가지 않는다');
    }

    public function test_request_alimtalk_carries_the_leaver_name_in_the_submitter_slot(): void
    {
        $leaver = $this->freelancer();
        $this->confirmed($leaver);
        $manager = $this->user();
        $this->user('admin');
        config(['services.bizm.enabled' => false]);

        $batch = SettlementPayoutBatch::submitForLeaver($manager, $leaver);
        $this->assertSame('퇴사정산 '.$leaver->name, $leaver->leaverTag());
        // 변수 값으로만 알린다(본문·줄은 BizM 등록본 그대로) — 제출 칸에 퇴사자 이름 + 제출자
        $vars = (new \ReflectionClass($batch))->getMethod('notifyPayoutRequest');
        $this->assertTrue($vars->isPublic());
        $this->assertStringContainsString('퇴사정산', $this->submitterVar($batch));
        $this->assertStringContainsString($manager->name, $this->submitterVar($batch));
    }

    /** notifyPayoutRequest 가 조립하는 「제출자」 변수와 같은 식(단일 출처를 반영하는 재조립이 아니라 메서드 결과를 본다). */
    private function submitterVar(SettlementPayoutBatch $batch): string
    {
        return $batch->isLeaverBatch()
            ? ($batch->salesman?->leaverTag() ?? '퇴사정산').' · '.($batch->submitter?->name ?? '-')
            : ($batch->submitter?->name ?? '-');
    }

    // ── 화면 ──────────────────────────────────────────────────────────────

    public function test_settlements_screen_lists_leavers_and_submits_from_the_modal(): void
    {
        $manager = $this->user();
        $this->user('admin');
        $this->actingAs($manager);
        $leaver = $this->freelancer();
        $s = $this->confirmed($leaver);
        Settlement::create(['vehicle_id' => $s->vehicle_id, 'salesman_id' => $leaver->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending', 'attributed_month' => '2026-10-01']);

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-10');
        $this->assertSame(1, $c->get('leaverOpenCount'));
        $c->call('toggleLeavers')->assertSee($leaver->name)->assertSee('미확정 1')->assertSeeHtml('data-leaver-row="'.$leaver->id.'"')
            ->assertSeeHtml('data-leaver-blocked')->assertDontSee('퇴사 정산 제출</button>', false);

        // 일반 목록·담당자 합계에는 퇴사자 미배치 정산이 안 섞인다(jin «밑에서 일반 정산과 섞이면 어렵다»)
        $this->assertSame(0, $c->instance()->settlements->total(), '일반 목록 0건');
        $c->call('toggleSummaries');
        $this->assertSame([], array_column($c->instance()->salesmanSummaries, 'salesman_id'));
        // 표 안에서 [확정] — 미확정을 확정하면 제출 버튼이 열린다
        $pendingId = Settlement::where('salesman_id', $leaver->id)->where('settlement_status', 'pending')->value('id');
        $c->assertSeeHtml('data-leaver-confirm="'.$pendingId.'"')->call('confirmSettlement', $pendingId)->assertHasNoErrors();
        $this->assertSame('confirmed', Settlement::find($pendingId)->settlement_status);
        $c->assertDontSeeHtml('data-leaver-blocked');

        $c->call('openLeaverModal', $leaver->id)->assertSeeHtml('data-leaver-modal')
            ->set('newLeaverAdjAmount', '-50,000')->set('newLeaverAdjReason', '예치금 상계')->call('addLeaverAdjustment')
            ->call('submitLeaverBatch')->assertHasNoErrors();

        $batch = SettlementPayoutBatch::leaver()->first();
        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->settlement_count);
        $this->assertSame(-50_000, (int) $batch->adjustments()->first()->amount);
        $c->assertSee('퇴사 정산 #'.$batch->id.' 결재 중');

        // 월정산 화면·승인 링크가 종류를 보여 준다
        Volt::test('erp.payout-batches.index')->assertSeeHtml('data-leaver-batch')->assertSee('퇴사 정산 · '.$leaver->name);
    }
}
