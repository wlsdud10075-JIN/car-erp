<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomesticFeeStatement;
use App\Models\PayoutHold;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\BatchPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ⏸ 지급보류 (jin 2026-11-06) — 대표까지 승인된 사내직원 월정산에서 사람별 전액(+/−) 보류 → 사내직원관리 보류금 →
 * 「지급」(월정산 밖) 또는 「월정산 추가」(다음 제출 때 「○월 지급보류분」 줄). 반려되면 되돌아온다. 회사이익엔 다시 안 들어간다.
 */
class PayoutHoldTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission = 'manager', string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0107777'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now()]);
    }

    private function employee(int $payroll = 2_000_000, string $month = '2026-10'): Salesman
    {
        $sm = Salesman::create(['name' => '직원'.++$this->n, 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => $month, 'label' => '기본급', 'amount' => $payroll, 'sort' => 1]);

        return $sm;
    }

    private function confirmed(Salesman $sm, string $month = '2026-10'): Settlement
    {
        $v = Vehicle::create(['vehicle_number' => 'HD'.++$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => $month.'-01', 'sale_price' => 20_000, 'sale_date' => $month.'-02']);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 20_000, 'exchange_rate' => 1400, 'payment_date' => $month.'-10', 'confirmed_at' => now()]);

        return Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_status' => 'confirmed', 'confirmed_at' => $month.'-15',
            'attributed_month' => $month.'-01', 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000]);
    }

    /** 승인된 10월 사내직원 월정산 + 사내직원 1명(급여 200만 + 정산 10만). */
    private function approvedBatch(): array
    {
        $manager = $this->user();
        $this->user('admin', '관리', '대표');
        $sm = $this->employee();
        $this->confirmed($sm);
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10');
        $batch->forceFill(['status' => SettlementPayoutBatch::STATUS_APPROVED])->save();

        return [$batch->fresh(), $sm, $manager];
    }

    public function test_hold_only_on_an_approved_month_batch_by_an_allowed_user_and_stores_the_signed_payout(): void
    {
        $manager = $this->user();
        $this->user('admin', '관리', '대표');
        $sm = $this->employee();
        $this->confirmed($sm);
        $pending = SettlementPayoutBatch::submitForMonth($manager, '2026-10');

        try {
            $pending->holdPayout($manager, $sm->id);
            $this->fail('결재 중엔 보류할 수 없다');
        } catch (\DomainException) {
        }

        $pending->forceFill(['status' => SettlementPayoutBatch::STATUS_APPROVED])->save();
        $batch = $pending->fresh();
        $expected = (int) collect($batch->breakdownForDisplay()['people'])->firstWhere('salesman_id', $sm->id)['payout'];
        $this->assertSame(2_100_000, $expected);

        try {
            $batch->holdPayout($this->user('user', '재무'), $sm->id);
            $this->fail('재무는 보류 권한이 없다');
        } catch (\DomainException) {
        }

        $hold = $batch->holdPayout($manager, $sm->id, null, '확인 뒤 지급');
        $this->assertSame(PayoutHold::HELD, $hold->status);
        $this->assertSame($expected, $hold->amount_krw);
        $this->assertSame($expected, PayoutHold::openBalanceFor($sm->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_held', 'auditable_id' => $sm->id]);
        $this->assertSame($batch->breakdown_snapshot, $batch->fresh()->breakdown_snapshot, '박제는 안 건드린다');
        $this->assertSame((int) $batch->total_payout, (int) $batch->fresh()->total_payout, '총액도 그대로');

        try {
            $batch->holdPayout($manager, $sm->id);
            $this->fail('두 번 보류 안 됨');
        } catch (\DomainException) {
        }

        // 사내직원관리 뱃지 · 패널
        $this->actingAs($manager);
        Volt::test('erp.salesmen.index')->assertSeeHtml('data-hold-badge="'.$sm->id.'"')
            ->call('openEdit', $sm->id)->assertSeeHtml('data-hold-panel')->assertSeeHtml('data-hold-queue');

        $batch->cancelHold($manager, $sm->id);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame(PayoutHold::CANCELLED, $hold->fresh()->status);
    }

    public function test_queued_hold_becomes_a_held_release_line_in_the_next_batch_outside_company_profit_and_reject_requeues(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $hold = $batch->holdPayout($manager, $sm->id);

        $this->actingAs($manager);
        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->call('resolveHold', 'queue');
        $this->assertSame(PayoutHold::QUEUED, $hold->fresh()->status);
        $this->assertSame($hold->amount_krw, PayoutHold::openBalanceFor($sm->id), '추가 대기 중에도 보류금 잔액에 남는다');

        // 11월 — 급여만(정산 없음). 제출 미리보기에 줄이 보이고, 제출하면 held_release 조정 줄이 된다.
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => '2026-11', 'label' => '기본급', 'amount' => 2_000_000, 'sort' => 1]);
        $other = $this->employee(1_000_000, '2026-11');
        $this->confirmed($other, '2026-11');
        $preview = Volt::test('erp.settlements.index')->set('monthFilter', '2026-11')->instance()->submitPreview;
        $this->assertSame([$sm->id], array_column($preview['held_releases'], 'salesman_id'));

        $next = SettlementPayoutBatch::submitForMonth($manager, '2026-11');
        $adj = $next->adjustments()->where('kind', SettlementPayoutAdjustment::KIND_HELD_RELEASE)->first();
        $this->assertNotNull($adj);
        $this->assertSame($hold->amount_krw, (int) $adj->amount);
        $this->assertStringContainsString('2026-10월 지급보류분', $adj->reason);
        $this->assertSame(PayoutHold::RELEASED_BATCH, $hold->fresh()->status);
        $this->assertSame($next->id, $hold->fresh()->released_batch_id);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));

        // 송금엔 들어가고 회사이익(payout_cost·company_contribution)엔 안 들어간다
        $bd = BatchPayoutBreakdown::forBatch($next);
        $p = collect($bd['people'])->firstWhere('salesman_id', $sm->id);
        $this->assertSame($hold->amount_krw, $p['adj_held']);
        $this->assertSame($p['payout_cost'], $p['payout'] - $p['adj_held'], '지급보류분은 송금에만');
        $this->assertSame(2_000_000, $p['payout_cost'], '회사 부담 지급 = 이번 달 급여만');
        $this->assertSame($next->depositReturnTotal(), $hold->amount_krw, '승인 화면 회사이익 계산도 같이 뺀다');

        $ceo = User::where('approval_title', '대표')->first();
        $next->fresh()->rejectBy($ceo, '다시');
        $this->assertSame(PayoutHold::QUEUED, $hold->fresh()->status, '반려되면 다시 추가 대기');
        $this->assertNull($hold->fresh()->released_batch_id);
    }

    public function test_domestic_batch_leaves_queued_holds_alone(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $batch->holdPayout($manager, $sm->id);
        PayoutHold::resolve($manager, $sm->id, 'queue');

        Setting::query()->updateOrCreate(['key' => 'domestic_payout_viewers'], ['value' => json_encode([$manager->id]), 'type' => 'string']);
        Setting::flushRiskParamMemo();
        $dom = Salesman::create(['name' => '내수1', 'type' => 'domestic_staff', 'is_active' => true]);
        DomesticFeeStatement::create(['salesman_id' => $dom->id, 'month' => '2026-11', 'headers' => ['건수', '총 평가사수수료'], 'values' => [3, 500_000], 'payout' => 500_000]);
        PayrollEntry::create(['salesman_id' => $dom->id, 'month' => '2026-11', 'label' => DomesticFeeStatement::PAYROLL_LABEL, 'amount' => 500_000, 'sort' => 0, 'is_custom' => true]);
        $ceo = User::where('approval_title', '대표')->first();

        $domBatch = SettlementPayoutBatch::submitForDomestic($manager, '2026-11', ['대표' => $ceo->id]);
        $this->assertSame(0, $domBatch->adjustments()->where('kind', SettlementPayoutAdjustment::KIND_HELD_RELEASE)->count());
        $this->assertSame(PayoutHold::QUEUED, PayoutHold::first()->status, '내수직원 월정산은 보류분을 가져가지 않는다');
    }

    public function test_paid_outside_settlement_zeroes_the_balance_and_unqueue_goes_back_to_held(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $hold = $batch->holdPayout($manager, $sm->id);

        PayoutHold::resolve($manager, $sm->id, 'queue');
        PayoutHold::resolve($manager, $sm->id, 'unqueue');
        $this->assertSame(PayoutHold::HELD, $hold->fresh()->status);

        try {
            PayoutHold::resolve($this->user('user', '재무'), $sm->id, 'paid');
            $this->fail('재무는 처리 권한이 없다');
        } catch (\DomainException) {
        }
        PayoutHold::resolve($manager, $sm->id, 'paid');
        $this->assertSame(PayoutHold::RELEASED_PAID, $hold->fresh()->status);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_hold_paid', 'auditable_id' => $sm->id]);
        $this->assertSame([], SettlementPayoutBatch::heldReleaseLinesFor(), '지급 처리된 보류는 다음 월정산에 안 들어간다');
    }

    public function test_screen_and_excel_show_the_hold_and_the_net_transfer(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $this->actingAs($manager);

        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSeeHtml('data-hold-button');
        $c->call('holdPerson', $batch->id, $sm->id)->assertSeeHtml('data-hold-net')->assertDontSeeHtml('data-hold-button');

        $res = $this->get(route('erp.payout-batches.export', $batch));
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'hld').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $this->assertContains('⏸ 보류(송금 제외)', $rows[0]);
        $flat = array_merge(...$rows);
        $this->assertContains('실송금(보류 제외)', $flat);
        $holdCol = array_search('⏸ 보류(송금 제외)', $rows[0], true);
        $personRow = collect($rows)->first(fn ($r) => ($r[1] ?? null) === $sm->name);
        $this->assertSame(2_100_000, (int) $personRow[$holdCol]);

        // 퇴사 정산·결재 중 카드에는 보류 버튼이 없다
        $leaver = Salesman::create(['name' => '프리퇴사', 'type' => 'freelance', 'is_active' => false, 'left_at' => '2026-10-12']);
        $v = Vehicle::create(['vehicle_number' => 'LVX1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400, 'salesman_id' => $leaver->id,
            'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-01', 'sale_price' => 20_000, 'sale_date' => '2026-10-02']);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 20_000, 'exchange_rate' => 1400, 'payment_date' => '2026-10-10', 'confirmed_at' => now()]);
        Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $leaver->id, 'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15',
            'attributed_month' => '2026-10-01', 'settlement_type' => 'ratio', 'settlement_ratio' => 50]);
        $lb = SettlementPayoutBatch::submitForLeaver($manager, $leaver);
        $lb->forceFill(['status' => SettlementPayoutBatch::STATUS_APPROVED])->save();
        try {
            $lb->fresh()->holdPayout($manager, $leaver->id);
            $this->fail('퇴사 정산은 보류 대상이 아니다');
        } catch (\DomainException) {
        }
    }

    /** jin 2026-11-06 «330만 중 150만 지급·180만 보류» — 금액칸으로 일부만 보류하면 나머지는 이번 달 그대로 송금된다. */
    public function test_partial_hold_from_the_card_leaves_the_rest_paid_and_rejects_bad_amounts(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $this->actingAs($manager);

        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->call('startHold', $batch->id, $sm->id, '2,100,000')->assertSeeHtml('data-hold-form')->assertSet('holdAmount', '2,100,000');

        // 초과 · 부호 다름 · 0 은 거부(보류 0건 그대로)
        foreach (['2,500,000', '-100,000', '0'] as $bad) {
            $c->set('holdAmount', $bad)->call('holdPerson', $batch->id, $sm->id);
            $this->assertSame(0, PayoutHold::where('salesman_id', $sm->id)->count(), "잘못된 금액 {$bad} 은 거부");
        }

        $c->set('holdAmount', '1,800,000')->call('holdPerson', $batch->id, $sm->id)
            ->assertSet('holdTarget', null)
            ->assertSeeHtml('data-hold-net')
            ->assertSee('⏸ 보류 ₩1,800,000')->assertSee('/ 2,100,000')
            ->assertSeeHtml('data-hold-button');   // 남은 30만은 더 보류할 수 있다
        $this->assertSame(1_800_000, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame([$sm->id => 1_800_000], $batch->heldAmountsBySalesman());

        // 남은 몫보다 크면 거부, 남은 몫 그대로는 통과 → 두 줄 합이 전액
        $c->call('startHold', $batch->id, $sm->id, '300,000')->set('holdAmount', '300,001')->call('holdPerson', $batch->id, $sm->id);
        $this->assertSame(1, PayoutHold::where('salesman_id', $sm->id)->count());
        $c->call('startHold', $batch->id, $sm->id, '300,000')->call('holdPerson', $batch->id, $sm->id)
            ->assertDontSeeHtml('data-hold-button')->assertSee('⏸ 보류 ₩2,100,000');
        $this->assertSame(2, PayoutHold::where('salesman_id', $sm->id)->count());
        $this->assertSame(2_100_000, PayoutHold::openBalanceFor($sm->id));

        // 엑셀 보류 열 = 두 줄의 합
        $res = $this->get(route('erp.payout-batches.export', $batch));
        $path = tempnam(sys_get_temp_dir(), 'hld').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $holdCol = array_search('⏸ 보류(송금 제외)', $rows[0], true);
        $personRow = collect($rows)->first(fn ($r) => ($r[1] ?? null) === $sm->name);
        $this->assertSame(2_100_000, (int) $personRow[$holdCol]);

        // 보류 취소는 그 사람의 held 줄 전부
        $c->call('releaseHold', $batch->id, $sm->id);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame(2, PayoutHold::where('salesman_id', $sm->id)->where('status', PayoutHold::CANCELLED)->count());
    }

    /** 10월 +200만 · 11월 −50만처럼 부호가 섞인 보류를 전액 처리하면 두 줄이 다 옮겨져야 한다(금액으로 걷으면 합이 맞는 지점에서 멈춰 줄이 남는다). */
    public function test_full_resolve_moves_every_row_even_when_signs_are_mixed(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $plus = $batch->holdPayout($manager, $sm->id);   // +2,100,000
        $minus = PayoutHold::create(['salesman_id' => $sm->id, 'payout_batch_id' => $batch->id, 'month' => '2026-11', 'amount_krw' => -500_000,
            'status' => PayoutHold::HELD, 'held_by' => $manager->id]);
        $this->assertSame(1_600_000, PayoutHold::openBalanceFor($sm->id));

        $this->assertSame(1_600_000, PayoutHold::resolve($manager, $sm->id, 'queue'));
        $this->assertSame(PayoutHold::QUEUED, $plus->fresh()->status);
        $this->assertSame(PayoutHold::QUEUED, $minus->fresh()->status, '음수 줄도 같이 옮겨진다');
        $this->assertSame(2, PayoutHold::where('salesman_id', $sm->id)->count(), '줄을 쪼개지 않는다');
        $this->assertSame(1_600_000, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame([1_600_000], array_column(SettlementPayoutBatch::heldReleaseLinesFor(), 'amount'));
        $this->assertStringContainsString('2026-10월·2026-11월 지급보류분', AuditLog::where('action', 'payout_hold_queue')->latest('id')->first()->old_value);

        // 일부(100만)는 부호 섞여도 합이 맞게 — 오래된 달(+210만)부터 쪼갠다
        PayoutHold::resolve($manager, $sm->id, 'unqueue');
        $this->assertSame(1_000_000, PayoutHold::resolve($manager, $sm->id, 'queue', 1_000_000));
        $this->assertSame(1_000_000, (int) PayoutHold::where('salesman_id', $sm->id)->where('status', PayoutHold::QUEUED)->sum('amount_krw'));
        $this->assertSame(600_000, (int) PayoutHold::where('salesman_id', $sm->id)->where('status', PayoutHold::HELD)->sum('amount_krw'));
    }

    /** jin 2026-11-06 «이월된 보류금에서도 나눠서» — 일부만 월정산 추가/지급하면 줄을 쪼개고 그 몫만 다음 월정산에 들어간다. */
    public function test_partial_resolve_splits_the_row_and_only_that_part_reaches_the_next_batch(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $hold = $batch->holdPayout($manager, $sm->id);   // 2,100,000 보류
        $this->actingAs($manager);

        // 150만만 월정산 추가 → queued 150만 + held 60만(원래 줄), 잔액은 그대로 210만
        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->assertSeeHtml('data-hold-amount')
            ->set('holdResolveAmount', '1,500,000')->call('resolveHold', 'queue')->assertSet('holdResolveAmount', '');
        $rows = PayoutHold::where('salesman_id', $sm->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([PayoutHold::HELD, 600_000], [$rows[0]->status, (int) $rows[0]->amount_krw], '원래 줄엔 남는 몫');
        $this->assertSame([PayoutHold::QUEUED, 1_500_000], [$rows[1]->status, (int) $rows[1]->amount_krw]);
        $this->assertSame(2_100_000, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame([1_500_000], array_column(SettlementPayoutBatch::heldReleaseLinesFor(), 'amount'));

        // 11월 제출 → 조정 줄 = 150만만, 60만은 보류 중으로 남는다
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => '2026-11', 'label' => '기본급', 'amount' => 2_000_000, 'sort' => 1]);
        $other = $this->employee(1_000_000, '2026-11');
        $this->confirmed($other, '2026-11');
        $next = SettlementPayoutBatch::submitForMonth($manager, '2026-11');
        $adj = $next->adjustments()->where('kind', SettlementPayoutAdjustment::KIND_HELD_RELEASE)->first();
        $this->assertSame(1_500_000, (int) $adj->amount);
        $this->assertSame(PayoutHold::RELEASED_BATCH, $rows[1]->fresh()->status);
        $this->assertSame(PayoutHold::HELD, $rows[0]->fresh()->status);
        $this->assertSame(600_000, PayoutHold::openBalanceFor($sm->id));

        // 남은 60만 중 20만만 월정산 밖 지급 → held 40만 + released_paid 20만. 잔액보다 크면 거부.
        try {
            PayoutHold::resolve($manager, $sm->id, 'paid', 700_000);
            $this->fail('잔액 초과는 거부');
        } catch (\DomainException) {
        }
        $this->assertSame(200_000, PayoutHold::resolve($manager, $sm->id, 'paid', 200_000));
        $this->assertSame(400_000, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame(1, PayoutHold::where('salesman_id', $sm->id)->where('status', PayoutHold::RELEASED_PAID)->where('amount_krw', 200_000)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_hold_paid', 'auditable_id' => $sm->id]);
        $this->assertStringContainsString('(잔액 600,000원 중)', AuditLog::where('action', 'payout_hold_paid')->latest('id')->first()->old_value);

        // 빈칸(전액) = 남은 40만 전부
        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('holdResolveAmount', '')->call('resolveHold', 'paid');
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));
    }
}
