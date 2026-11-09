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
use App\Services\Payout\PayoutBatchExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            $pending->holdPayout($manager, $sm->id, null, '확인');
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
            $batch->holdPayout($manager, $sm->id, null, '확인');
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
        $hold = $batch->holdPayout($manager, $sm->id, null, '확인');

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
        $batch->holdPayout($manager, $sm->id, null, '확인');
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
        $hold = $batch->holdPayout($manager, $sm->id, null, '확인');

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
        $c->call('holdPerson', $batch->id, $sm->id);
        $this->assertSame(0, PayoutHold::count(), '사유 없이는 보류 안 됨(jin 11-06)');
        $c->set('holdNote', '확인 뒤 지급')->call('holdPerson', $batch->id, $sm->id)->assertSeeHtml('data-hold-net')->assertDontSeeHtml('data-hold-button');

        $res = $this->get(route('erp.payout-batches.export', $batch));
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'hld').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $this->assertContains('⏸ 보류(송금 제외)', $rows[3], '보류가 있는 달만 열이 끼어든다(4행 머리)');
        $flat = array_merge(...$rows);
        $this->assertContains('실송금(보류·추가 반영)', $flat);
        $holdCol = array_search('⏸ 보류(송금 제외)', $rows[3], true);
        $personRow = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $sm->name);
        $this->assertSame(2_100_000, (int) $personRow[$holdCol]);
        $byLabel = collect($rows)->keyBy(2);
        $this->assertSame(2_100_000, (int) $byLabel['⏸ 보류 합(송금 제외)'][6]);
        $this->assertSame((int) $byLabel['송금 총액 (퇴직연금 몫 포함)'][6] - 2_100_000, (int) $byLabel['실송금(보류·추가 반영)'][6]);

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
        // 퇴사 정산 엑셀 — 급여 항목 탭에 사람이 0명(프리랜서만)이어도 열린다
        $res = $this->get(route('erp.payout-batches.export', $lb));
        $res->assertOk();
        $lp = tempnam(sys_get_temp_dir(), 'lvr').'.xlsx';
        file_put_contents($lp, $res->streamedContent());
        $lss = IOFactory::load($lp);
        $this->assertSame('퇴사 정산 2026년 10월 사람별 지급 내역', $lss->getSheetByName('사람별 지급')->getCell('B1')->getValue());
        // 프리랜서만 있는 배치 — 급여 항목 탭에 사람 0명이어도 깨지지 않는다(묶음 없음 → 바로 합계 줄)
        $svc = app(PayoutBatchExportService::class);
        $m = new \ReflectionMethod($svc, 'payrollItemsSheet');
        $empty = new Spreadsheet;
        $m->invoke($svc, $empty->getActiveSheet(), [['type' => 'freelance', 'name' => '프리', 'type_label' => '프리랜서']], '퇴사 정산 2026년 10월 급여 항목', '2026-10');
        $this->assertSame('합계', $empty->getActiveSheet()->getCell('B5')->getValue());
        $this->assertSame(0, $empty->getActiveSheet()->getCell('E5')->getValue());
    }

    /** jin 2026-11-06 «330만 중 150만 지급·180만 보류» — 금액칸으로 일부만 보류하면 나머지는 이번 달 그대로 송금된다. */
    public function test_partial_hold_from_the_card_leaves_the_rest_paid_and_rejects_bad_amounts(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $this->actingAs($manager);

        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->call('startHold', $batch->id, $sm->id, '2,100,000')->assertSeeHtml('data-hold-form')->assertSet('holdAmount', '2,100,000')->set('holdNote', '일부 확인');

        // 초과 · 부호 다름 · 0 은 거부(보류 0건 그대로)
        foreach (['2,500,000', '-100,000', '0'] as $bad) {
            $c->set('holdAmount', $bad)->call('holdPerson', $batch->id, $sm->id);
            $this->assertSame(0, PayoutHold::where('salesman_id', $sm->id)->count(), "잘못된 금액 {$bad} 은 거부");
        }

        $c->set('holdAmount', '1,800,000')->call('holdPerson', $batch->id, $sm->id)
            ->assertSet('holdTarget', null)
            ->assertSeeHtml('data-hold-net')
            ->assertSee('⏸ 보류 ₩1,800,000')->assertSee('/ 2,100,000')
            ->assertSeeHtml('data-hold-cancel')->assertDontSeeHtml('data-hold-button');   // 같은 자리 버튼이 「보류 취소」로(jin 11-06). 남은 몫은 모델로는 더 보류 가능
        $this->assertSame(1_800_000, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame([$sm->id => 1_800_000], $batch->heldAmountsBySalesman());

        // 남은 몫보다 크면 거부, 남은 몫 그대로는 통과 → 두 줄 합이 전액
        $c->call('startHold', $batch->id, $sm->id, '300,000')->set('holdNote', '나머지')->set('holdAmount', '300,001')->call('holdPerson', $batch->id, $sm->id);
        $this->assertSame(1, PayoutHold::where('salesman_id', $sm->id)->count());
        $c->call('startHold', $batch->id, $sm->id, '300,000')->set('holdNote', '나머지')->call('holdPerson', $batch->id, $sm->id)
            ->assertDontSeeHtml('data-hold-button')->assertSee('⏸ 보류 ₩2,100,000');
        // 「다음 월정산 대기」로 넘어간 줄도 아직 제출 전이면 취소된다
        PayoutHold::resolve($manager, $sm->id, 'queue', 300_000);
        $c->call('toggle', $batch->id)->call('toggle', $batch->id)->assertSeeHtml('data-hold-cancel');
        $this->assertSame(3, PayoutHold::where('salesman_id', $sm->id)->count(), '보류 2줄 + 쪼개진 대기 줄');
        $this->assertSame(2_100_000, PayoutHold::openBalanceFor($sm->id));

        // 엑셀 보류 열 = 두 줄의 합
        $res = $this->get(route('erp.payout-batches.export', $batch));
        $path = tempnam(sys_get_temp_dir(), 'hld').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $holdCol = array_search('⏸ 보류(송금 제외)', $rows[3], true);
        $personRow = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $sm->name);
        $this->assertSame(2_100_000, (int) $personRow[$holdCol]);

        // 보류 취소는 그 사람의 held 줄 전부
        $c->call('releaseHold', $batch->id, $sm->id);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id));
        $this->assertSame(3, PayoutHold::where('salesman_id', $sm->id)->where('status', PayoutHold::CANCELLED)->count(), '보류 중 2줄 + 쪼개진 대기 줄까지 전부 취소');
    }

    /** jin 2026-11-09 «지급추가한 금액 포함하여 지급보류» — 보류 한도 = 지급합계 + 이 배치의 지급추가. 합산 전액이면 실제 송금 0. */
    public function test_hold_limit_includes_the_extra_payout_of_the_same_batch(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $batch->addExtraPayout($manager, $sm->id, 500_000, '대표 승인 추가 인센티브');
        $transfer = (int) $batch->breakdownForDisplay()['totals']['transfer_total'];

        try {
            $batch->holdPayout($manager, $sm->id, 2_600_001, '초과');
            $this->fail('지급합계 + 추가를 넘는 보류는 거부');
        } catch (\DomainException) {
        }
        $this->assertSame(0, PayoutHold::where('kind', PayoutHold::KIND_HOLD)->count());

        // 기본(전액) = 2,600,000 — 카드 버튼이 넘기는 기본값도 같은 바탕
        $this->actingAs($manager);
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSeeHtml("startHold({$batch->id}, {$sm->id}, '2,600,000')");
        $hold = $batch->holdPayout($manager, $sm->id, null, '마이너스 처리 뒤 합산 보류');
        $this->assertSame(2_600_000, (int) $hold->amount_krw);
        $this->assertSame(2_600_000, PayoutHold::openBalanceFor($sm->id));
        // 실제 송금액 = 송금 총액 − 보류 + 추가 = 그 사람 몫 0 → 화면 합계 띠
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSee(number_format($transfer - 2_600_000 + 500_000));
        try {
            $batch->holdPayout($manager, $sm->id, 1, '더');
            $this->fail('이미 전액 보류');
        } catch (\DomainException) {
        }
    }

    /** jin 2026-11-09 «보류 1,321만과 추가 300만이 따로 서면 중복으로 보인다 — 최종 금액 하나만, 구성은 그 안에». 흡수된 추가는 ➕ 로 안 서고 ⏸ 구성에만. */
    public function test_extra_absorbed_into_the_hold_shows_as_composition_not_as_a_second_badge(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $batch->addExtraPayout($manager, $sm->id, 500_000, '대표 승인 추가 인센티브');

        // ① 보류가 지급합계 이하(1,000,000) — 추가는 흡수 안 됨 → ➕ 500,000 따로, 구성 줄 없음
        $batch->holdPayout($manager, $sm->id, 1_000_000, '일부');
        $this->assertSame(['held' => 1_000_000, 'held_payout' => 1_000_000, 'held_extra' => 0, 'extra' => 500_000, 'extra_outside' => 500_000], $batch->holdCompositionFor($sm->id));
        $this->actingAs($manager);
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->assertSeeHtml('data-extra-badge="'.$sm->id.'"')->assertDontSeeHtml('data-hold-composition')->assertDontSeeHtml('data-extra-in-hold');
        $this->assertSame(['hold' => 1_000_000, 'extra' => 500_000, 'extra_shown' => 500_000], PayoutHold::openBalances()[$sm->id]);

        // ② 나머지까지 전부 보류(합 2,600,000 = 지급 + 추가) — 추가 500,000 은 보류에 흡수
        $batch->holdPayout($manager, $sm->id, null, '합산 보류');
        $comp = $batch->holdCompositionFor($sm->id);
        $this->assertSame(['held' => 2_600_000, 'held_payout' => 2_100_000, 'held_extra' => 500_000, 'extra' => 500_000, 'extra_outside' => 0], $comp);
        // 카드 — ⏸ 하나 + 구성 「지급 2,100,000 + 추가 500,000」, ➕ 뱃지 없음, 「추가 500,000원은 보류에 포함」
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->assertSeeHtml('data-hold-composition="'.$sm->id.'"')->assertSee('지급 2,100,000 + 추가 500,000')
            ->assertDontSeeHtml('data-extra-badge="'.$sm->id.'"')->assertSeeHtml('data-extra-in-hold="'.$sm->id.'"');
        // 사내직원관리 — ⏸ 뱃지만(➕ 는 흡수돼 0), 드로어는 ⏸ 블록에 구성 줄 · ➕ 블록 없음
        $this->assertSame(['hold' => 2_600_000, 'extra' => 500_000, 'extra_shown' => 0], PayoutHold::openBalances()[$sm->id]);
        Volt::test('erp.salesmen.index')->assertSeeHtml('data-hold-badge="'.$sm->id.'"')->assertDontSeeHtml('data-extra-badge="'.$sm->id.'"')
            ->call('openEdit', $sm->id)->assertSeeHtml('data-hold-block="hold"')->assertSeeHtml('data-hold-composition="'.$sm->id.'"')
            ->assertSee('지급 2,100,000 + 추가 500,000')->assertDontSeeHtml('data-hold-block="extra"');
        // 숫자는 그대로 — 실제 송금 = 총액 − 2,600,000 + 500,000
        $transfer = (int) $batch->breakdownForDisplay()['totals']['transfer_total'];
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSee(number_format($transfer - 2_600_000 + 500_000));
    }

    /** jin 2026-11-09 «뱃지는 잘 나오는데 드로어 클릭하면 상세내용에 안 나온다» — 보류 기록 칸이 사내직원 급여 분기 안에 있어 프리랜서 드로어엔 안 그려졌다. */
    public function test_hold_panel_shows_in_the_drawer_for_freelancers_too(): void
    {
        $manager = $this->user();
        $this->user('admin', '관리', '대표');
        $free = Salesman::create(['name' => '프리'.++$this->n, 'type' => 'freelance', 'is_active' => true, 'settlement_ratio' => 50]);
        $this->confirmed($free);
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10');
        $batch->forceFill(['status' => SettlementPayoutBatch::STATUS_APPROVED])->save();
        $batch->fresh()->holdPayout($manager, $free->id, null, '마이너스 처리 뒤 보류');
        $this->assertNotSame(0, PayoutHold::openBalanceFor($free->id));

        $this->actingAs($manager);
        Volt::test('erp.salesmen.index')->assertSeeHtml('data-hold-badge="'.$free->id.'"')
            ->call('openEdit', $free->id)
            ->assertSeeHtml('data-hold-panel')->assertSeeHtml('data-hold-block="hold"')
            ->assertSeeHtml('data-hold-queue')->assertSeeHtml('data-hold-paid')->assertSee('마이너스 처리 뒤 보류');
    }

    /** 10월 +200만 · 11월 −50만처럼 부호가 섞인 보류를 전액 처리하면 두 줄이 다 옮겨져야 한다(금액으로 걷으면 합이 맞는 지점에서 멈춰 줄이 남는다). */
    public function test_full_resolve_moves_every_row_even_when_signs_are_mixed(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();
        $plus = $batch->holdPayout($manager, $sm->id, null, '확인');   // +2,100,000
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
        $hold = $batch->holdPayout($manager, $sm->id, null, '확인');   // 2,100,000 보류
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

    /**
     * ➕ 지급추가 (jin 2026-11-06) — 대표가 정해 기안자가 기입(결재 없음, 제출자·최고관리자), 사유 필수, +/− 둘 다.
     * 다음 월정산 줄(extra_release)은 송금에도 회사 부담 지급(payout_cost)에도 들어가 그 달 회사이익이 그만큼 준다. 반려되면 추가 대기로 돌아온다.
     */
    public function test_extra_payout_needs_a_reason_and_flows_to_the_next_batch_as_a_company_cost(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $transfer = (int) $batch->breakdownForDisplay()['totals']['transfer_total'];

        foreach ([[null, '성과'], [0, '성과'], [500_000, ''], [500_000, '   ']] as [$amt, $note]) {
            try {
                $batch->addExtraPayout($manager, $sm->id, $amt, $note);
                $this->fail('금액 0·사유 없음은 거부');
            } catch (\DomainException) {
            }
        }
        try {
            $batch->addExtraPayout($this->user('user', '재무'), $sm->id, 500_000, '성과');
            $this->fail('재무는 기입 권한이 없다');
        } catch (\DomainException) {
        }

        // 화면 — [➕ 지급추가] → 금액·사유 → 기록. 실제 송금액 = 송금 총액 + 추가
        $this->actingAs($manager);
        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSeeHtml('data-extra-button')
            ->call('startHold', $batch->id, $sm->id, '', 'extra')->assertSeeHtml('data-hold-form="extra"')
            ->set('holdAmount', '500,000')->set('holdNote', '대표 지시 — 10월 성과')->call('holdPerson', $batch->id, $sm->id)
            ->assertSeeHtml('data-extra-badge="'.$sm->id.'"')->assertSeeHtml('data-hold-net')->assertSee(number_format($transfer + 500_000));
        $extra = PayoutHold::where('salesman_id', $sm->id)->where('kind', PayoutHold::KIND_EXTRA)->first();
        $this->assertSame([PayoutHold::HELD, 500_000, '대표 지시 — 10월 성과'], [$extra->status, (int) $extra->amount_krw, $extra->note]);
        $this->assertSame(500_000, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA));
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id), '보류 잔액과 섞이지 않는다');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_extra_added', 'auditable_id' => $sm->id]);
        $this->assertSame($batch->breakdown_snapshot, $batch->fresh()->breakdown_snapshot, '박제는 안 건드린다');

        // 음수 추가도 되고(돌려받을 돈), 보류와 추가가 같이 있으면 실제 송금액 = 총액 − 보류 + 추가
        $batch->addExtraPayout($manager, $sm->id, -100_000, '과지급 회수');
        $batch->holdPayout($manager, $sm->id, 300_000, '확인 뒤');
        $this->assertSame(400_000, array_sum($batch->extraAmountsBySalesman()));
        $c->call('toggle', $batch->id)->call('toggle', $batch->id)->assertSee(number_format($transfer - 300_000 + 400_000));

        // 사내직원관리 — ➕ 뱃지 · 기록 섹션(보류·추가 둘 다, 사유 보임) · 추가 일부 「월정산 추가」
        $v = Volt::test('erp.salesmen.index')->assertSeeHtml('data-extra-badge="'.$sm->id.'"')->assertSeeHtml('data-hold-badge="'.$sm->id.'"')
            ->call('openEdit', $sm->id)->assertSeeHtml('data-hold-ledger')->assertSee('대표 지시 — 10월 성과')->assertSee('과지급 회수')
            ->assertSeeHtml('data-hold-block="extra"')->assertSeeHtml('data-hold-block="hold"');
        $v->set('extraResolveAmount', '300,000')->call('resolveHold', 'queue', 'extra');
        $this->assertSame(300_000, (int) PayoutHold::where('salesman_id', $sm->id)->where('kind', PayoutHold::KIND_EXTRA)->where('status', PayoutHold::QUEUED)->sum('amount_krw'));
        $this->assertSame(400_000, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA), '추가 대기 중에도 잔액에 남는다');
        $lines = SettlementPayoutBatch::extraReleaseLinesFor();
        $this->assertSame([300_000], array_column($lines, 'amount'));
        $this->assertStringContainsString('2026-10월 지급추가분', $lines[0]['reason']);
        $this->assertSame([], SettlementPayoutBatch::heldReleaseLinesFor(), '보류 줄과 섞이지 않는다');

        // 11월 제출 — extra_release 줄은 송금에도 회사 부담 지급에도 들어간다(보류분과 다르다)
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => '2026-11', 'label' => '기본급', 'amount' => 2_000_000, 'sort' => 1]);
        $other = $this->employee(1_000_000, '2026-11');
        $this->confirmed($other, '2026-11');
        $next = SettlementPayoutBatch::submitForMonth($manager, '2026-11');
        $adj = $next->adjustments()->where('kind', SettlementPayoutAdjustment::KIND_EXTRA_RELEASE)->first();
        $this->assertSame(300_000, (int) $adj->amount);
        $this->assertStringContainsString('지급추가분', $adj->reason);
        $p = collect(BatchPayoutBreakdown::forBatch($next)['people'])->firstWhere('salesman_id', $sm->id);
        $this->assertSame(300_000, $p['adj_extra']);
        $this->assertSame(2_300_000, $p['payout'], '송금 = 급여 200만 + 추가 30만');
        $this->assertSame(2_300_000, $p['payout_cost'], '회사 부담 지급에도 들어간다(보류분은 안 들어감)');
        $this->assertSame(0, $next->depositReturnTotal(), '회사이익 제외 묶음(예치금·보류분)엔 안 들어간다');
        $this->assertSame(PayoutHold::RELEASED_BATCH, PayoutHold::where('kind', PayoutHold::KIND_EXTRA)->where('status', PayoutHold::RELEASED_BATCH)->value('status'));
        $this->assertSame(100_000, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA), '남은 추가 = +500,000 − 100,000 − 300,000');

        $ceo = User::where('approval_title', '대표')->first();
        $next->fresh()->rejectBy($ceo, '다시');
        $this->assertSame(400_000, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA), '반려되면 추가 대기로 돌아온다');

        // 엑셀 — 「➕ 추가」 열과 실송금(보류·추가 반영)
        $res = $this->get(route('erp.payout-batches.export', $batch));
        $path = tempnam(sys_get_temp_dir(), 'ext').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $extraCol = array_search('➕ 추가(송금 더함)', $rows[3], true);
        $this->assertNotFalse($extraCol);
        $personRow = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $sm->name);
        $this->assertSame(400_000, (int) $personRow[$extraCol]);
        $byLabel = collect($rows)->keyBy(2);
        $this->assertSame($transfer - 300_000 + 400_000, (int) $byLabel['실송금(보류·추가 반영)'][6]);

        // 추가 취소 — 같은 자리 [➕ 추가 취소] 버튼. 「추가 대기」 줄도 제출 전이면 함께 취소. 기록 표엔 취소로 남는다
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSeeHtml('data-extra-cancel')->assertDontSeeHtml('data-extra-button')
            ->call('releaseHold', $batch->id, $sm->id, 'extra')->assertSeeHtml('data-extra-button');
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA));
        $this->assertSame(300_000, PayoutHold::openBalanceFor($sm->id), '보류는 그대로');
        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->assertSeeHtml('data-hold-ledger')->assertSee(__('payout_batch.hold.status.cancelled'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_extra_cancelled', 'auditable_id' => $sm->id]);
    }

    /**
     * ✅ 월정산 최종 확정 (jin 2026-11-06 «최종 확정 버튼으로 한 번에 종료») — 장부 잠금 + 확정본 엑셀(실제 송금 열·도장).
     * (가) 「지급(월정산 밖)」으로 준 추가금은 그 달 회사이익에서 빠진다(카드 합계 띠·엑셀 요약·승인 링크).
     */
    public function test_finalize_locks_the_ledger_and_the_excel_becomes_the_final_copy(): void
    {
        [$batch, $sm, $manager] = $this->approvedBatch();   // 지급합계 2,100,000
        $totals = $batch->breakdownForDisplay()['totals'];
        $this->actingAs($manager);

        $batch->holdPayout($manager, $sm->id, 600_000, '확인 뒤');
        $batch->addExtraPayout($manager, $sm->id, 400_000, '대표 지시');
        PayoutHold::resolve($manager, $sm->id, 'paid', null, PayoutHold::KIND_EXTRA);   // 월정산 밖으로 바로 지급
        $this->assertSame(400_000, $batch->extraPaidOutsideTotal());
        $batch->addExtraPayout($manager, $sm->id, 50_000, '하나 더');   // 열린 채로 둔다 → 최종 확정 때 이번 송금에 포함(지급 완료)

        // 확정 전 — 카드: 회사 순이익 반영값 = 박제 − 월정산 밖 추가 지급 · [최종 확정] 버튼 · 엑셀 제목 「확정 전」
        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->assertSeeHtml('data-finalize-button')->assertSeeHtml('data-company-net-final')
            ->assertSee(number_format(abs((int) $totals['company_net'] - 400_000)))->assertDontSeeHtml('data-finalized');
        $rows = $this->peopleRows($batch);
        $this->assertStringContainsString('(확정 전', (string) $rows[0][1]);

        try {
            $batch->finalize($this->user('user', '재무'));
            $this->fail('재무는 확정 권한이 없다');
        } catch (\DomainException) {
        }
        $c->call('finalizeBatch', $batch->id)->assertSeeHtml('data-finalized')->assertDontSeeHtml('data-finalize-button')
            ->assertSee(__('payout_batch.hold.status_held_final'))->assertSee(__('payout_batch.hold.status_extra_paid'))->assertDontSee(__('payout_batch.hold.status.held'))
            ->assertDontSeeHtml('data-hold-button')->assertDontSeeHtml('data-extra-button')->assertDontSeeHtml('data-hold-cancel')->assertDontSeeHtml('data-extra-cancel');
        $batch->refresh();
        $this->assertNotNull($batch->finalized_at);
        $this->assertSame($manager->id, $batch->finalized_by);
        $this->assertSame(0, PayoutHold::openBalanceFor($sm->id, PayoutHold::KIND_EXTRA), '열린 추가금은 이번 송금에 포함 → 지급 완료');
        $this->assertSame(450_000, $batch->extraPaidOutsideTotal(), '400,000 + 50,000 모두 이 달 회사이익 차감');
        $this->assertSame(600_000, PayoutHold::openBalanceFor($sm->id), '보류금은 그대로 남아 사내직원관리에서 처리');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_finalized', 'auditable_id' => $batch->id]);

        // 잠금 — 보류·추가·취소 전부 거부, 상태·박제·총액은 그대로
        foreach ([fn () => $batch->holdPayout($manager, $sm->id, 100_000, 'x'), fn () => $batch->addExtraPayout($manager, $sm->id, 100_000, 'x'),
            fn () => $batch->cancelHold($manager, $sm->id), fn () => $batch->finalize($manager)] as $blocked) {
            try {
                $blocked();
                $this->fail('최종 확정 뒤엔 장부를 못 건드린다');
            } catch (\DomainException) {
            }
        }
        $this->assertSame(SettlementPayoutBatch::STATUS_APPROVED, $batch->status);
        // 이미 사내직원관리로 넘어간 보류금 처리는 그대로 된다
        $this->assertSame(600_000, PayoutHold::resolve($manager, $sm->id, 'queue'));

        // 확정본 엑셀 — 제목 도장 · 「실제 송금」 열 = 지급합계 − 보류 + 추가 · 회사 순이익(월정산 밖 추가 지급 반영)
        $rows = $this->peopleRows($batch);
        $this->assertStringContainsString('최종 확정 '.now()->format('Y-m-d'), (string) $rows[0][1]);
        $hdr = $rows[3];
        $netCol = array_search('실제 송금', $hdr, true);
        $this->assertNotFalse($netCol);
        $personRow = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $sm->name);
        $this->assertSame(2_100_000 - 600_000 + 450_000, (int) $personRow[$netCol]);
        $byLabel = collect($rows)->keyBy(2);
        $this->assertSame(450_000, (int) $byLabel['추가 지급(월정산 밖, 회사이익 차감)'][6]);
        $this->assertSame((int) $totals['company_net'] - 450_000, (int) $byLabel['회사 순이익(월정산 밖 추가 지급 반영)'][6]);

        // 승인 링크도 같은 숫자
        $ceo = User::where('approval_title', '대표')->first();
        $this->actingAs($ceo)->get($batch->approvalLinkFor($ceo))
            ->assertOk()->assertSee('data-finalized', false)->assertSee(number_format((int) $totals['company_net'] - 450_000));
    }

    /** @return array<int, array<int, mixed>> 「사람별 지급」 탭 */
    private function peopleRows(SettlementPayoutBatch $batch): array
    {
        $res = $this->get(route('erp.payout-batches.export', $batch));
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'fin').'.xlsx';
        file_put_contents($path, $res->streamedContent());

        return IOFactory::load($path)->getSheetByName('사람별 지급')->toArray(null, true, false, false);
    }
}
