<?php

namespace Tests\Feature;

use App\Models\ExternalSettlementPay;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\BatchPayoutBreakdown;
use App\Services\Payout\PersonPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 월정산 빈틈 3가지 (jin 2026-10-21 «정산씬에 대해서 완벽히 만들려면»).
 *
 * ① 제출 뒤 바뀐 정산 — ssancarerp 196다2669: 10-07 제출 때 20만 → 10-12 판매가 9,947→9,317 → 10-14 승인 10만. 표시가 없었다.
 * ② 사내직원 2차 마감 재평가 — 지급 뒤 총마진이 등급을 넘으면(20만↔10만) 차액을 다음 달로 자동 이월(jin «(나) — 사람이 넣으면 누락»).
 * ③ 급여대장 판매수당·헤이맨 정산금·퇴직연금 1/12 — 판매수당은 정산금과 같은 돈(이중 계산 제거) + 대조로 실수를 잡는다.
 *    기준값 = 9월 실측: 존 (2,809,642 + 3,300,000 + 1,000,000) ÷ 12 = 592,470 → 판매수당 3,707,530(급여대장과 같음)
 *                      무사백 (2,600,000 + 6,400,000 + 2,800,000) ÷ 12 = 983,333 → ERP 8,216,667 ↔ 급여대장 8,528,333 (311,666 차이 — 엑셀 실수)
 */
class PayoutGapsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'email_verified_at' => now()]);
    }

    /** KRW 차 — 매입 1천만, 판매가 $sale, 전액 확정 입금. 총마진 = ((판매가 − 1천만) + 90만) × 0.9 */
    private function krwVehicle(Salesman $sm, int $sale, string $month = '2026-10'): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'PG'.++$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => $month.'-01',
            'sale_price' => $sale, 'sale_date' => $month.'-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => $sale, 'payment_date' => $month.'-05', 'confirmed_at' => now()]);

        return $v->fresh();
    }

    private function confirmed(Vehicle $v, Salesman $sm, ?int $perUnit = null): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => $perUnit,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ]);
    }

    private function tierEmployee(string $name = '무사백'): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => true]);
    }

    // ── ① 제출 뒤 바뀐 정산 ──────────────────────────────────────────────

    public function test_submission_freezes_the_payout_and_a_later_sale_price_change_is_listed(): void
    {
        $sm = $this->tierEmployee();
        $v = $this->krwVehicle($sm, 12_000_000);              // 총마진 2,610,000 → 20만
        $s = $this->confirmed($v, $sm);
        $this->assertSame(200_000, $s->fresh()->actual_payout);

        $batch = SettlementPayoutBatch::submitForMonth($this->user('user', '관리'), '2026-10');
        $this->assertSame(200_000, $s->fresh()->payout_submitted_krw, '제출 때 실지급이 박제돼야 한다');
        $this->assertSame([], $batch->fresh()->settlementPayoutChanges(), '바뀐 게 없으면 목록은 비어 있다');

        $v->update(['sale_price' => 10_000_000]);           // 총마진 810,000 → 10만 (196다2669 의 그 모양)

        $changes = $batch->fresh()->settlementPayoutChanges();
        $this->assertCount(1, $changes);
        $this->assertSame(200_000, $changes[0]['before']);
        $this->assertSame(100_000, $changes[0]['after']);
        $this->assertStringContainsString('판매가', implode(' ', $changes[0]['hints']), '무엇이 바뀌었는지 감사로그로 붙는다');
        $this->assertContains('settlement_pay', $batch->fresh()->changedFieldsBySalesman($batch->fresh()->breakdownForDisplay())[$sm->id] ?? []);

        // 월정산 탭에만 보이고, 대표 알림톡 승인 링크엔 안 보인다(jin 2026-10-21)
        $admin = $this->user('super');
        $html = $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('payout.approve.show', now()->addHour(), ['batch' => $batch->id, 'u' => $admin->id]))->getContent();
        $this->assertStringNotContainsString('data-payout-changes', $html);
        $this->assertStringNotContainsString('data-submitted-changed', $html);
        $this->actingAs($admin);
        $tab = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->html();
        $this->assertStringContainsString('data-payout-changes', $tab);
        $this->assertMatchesRegularExpression('/200,000 → <b>100,000<\/b>/u', $tab);
    }

    public function test_final_approval_re_measures_the_total_and_keeps_the_change_in_history(): void
    {
        $sm = $this->tierEmployee();
        $v = $this->krwVehicle($sm, 12_000_000);
        $this->confirmed($v, $sm);
        $batch = SettlementPayoutBatch::submitForMonth($this->user('user', '관리'), '2026-10');
        $this->assertSame(200_000, (int) $batch->total_payout);

        $v->update(['sale_price' => 10_000_000]);
        $batch->fresh()->approveBy($this->user('super'));

        $batch = $batch->fresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame(100_000, (int) $batch->total_payout, '완료 알림톡 「총액」 = 실제로 나간 돈');
        $this->assertCount(1, $batch->settlementPayoutChanges(), '끝난 배치(박제)에서도 제출 뒤 변경이 남는다');
    }

    public function test_rejection_clears_the_submitted_amount(): void
    {
        $sm = $this->tierEmployee();
        $s = $this->confirmed($this->krwVehicle($sm, 12_000_000), $sm);
        $batch = SettlementPayoutBatch::submitForMonth($this->user('user', '관리'), '2026-10');
        $batch->rejectBy($this->user('super'), '다시');

        $this->assertNull($s->fresh()->payout_submitted_krw, '반려로 풀린 정산은 다음 제출 때 다시 박제한다');
    }

    // ── ② 사내직원 2차 마감 재평가 ────────────────────────────────────────

    private function paidEmployeeSettlement(Salesman $sm, int $sale, ?int $perUnit = null): array
    {
        $v = $this->krwVehicle($sm, $sale);
        Settlement::$allowBatchPayout = true;
        $s = $this->confirmed($v, $sm, $perUnit);
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        return [$v, $s->fresh()];
    }

    public function test_close_re_tiers_an_auto_per_unit_and_carries_the_difference(): void
    {
        $sm = $this->tierEmployee();
        [$v, $s] = $this->paidEmployeeSettlement($sm, 12_000_000);
        $this->assertSame(200_000, (int) $s->per_unit_amount, '지급 때 20만으로 고정');
        $this->assertTrue($s->confirmed_snapshot['per_unit_auto'], '자동 등급이었다는 표시');

        $v->update(['sale_price' => 10_000_000]);            // 지급 뒤 총마진 810,000 (100만 미만)
        $this->assertSame(200_000, $s->fresh()->actual_payout, '마감 전엔 고정값 그대로');

        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $s = $s->fresh();
        $this->assertSame('closed', $s->secondary_status);
        $this->assertSame(100_000, (int) $s->per_unit_amount, '마감 때 새 등급');
        $this->assertSame(-100_000, (int) $s->carryover_out_krw, '차액 −10만이 이월로');
        $this->assertSame(-100_000, (int) $sm->fresh()->unconsumed_carryover, '다음 월정산 「미청산 이월」 줄로 나간다');
    }

    public function test_close_does_not_touch_a_manual_per_unit_override(): void
    {
        $sm = $this->tierEmployee();
        [$v, $s] = $this->paidEmployeeSettlement($sm, 12_000_000, 150_000);   // 재무가 직접 15만
        $this->assertFalse($s->confirmed_snapshot['per_unit_auto']);

        $v->update(['sale_price' => 10_000_000]);
        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $s = $s->fresh();
        $this->assertSame(150_000, (int) $s->per_unit_amount);
        $this->assertNull($s->carryover_out_krw, '직접 넣은 금액은 다시 매기지 않는다');
    }

    public function test_old_snapshot_without_the_flag_is_judged_by_the_tier_at_payment(): void
    {
        $sm = $this->tierEmployee();
        [$v, $s] = $this->paidEmployeeSettlement($sm, 12_000_000);
        $snap = $s->confirmed_snapshot;
        unset($snap['per_unit_auto']);                       // 배포 전 지급분(키 없음)
        $s->forceFill(['confirmed_snapshot' => $snap])->saveQuietly();

        $v->update(['sale_price' => 10_000_000]);
        $this->assertSame(100_000, $s->fresh()->retieredPerUnitAtClose(), '지급 때 값 = 그때 자동 등급이면 자동으로 본다');
    }

    /** heymanerp 실측(2026-10-21): 6월 귀속 · 7/8 지급 무사백 6대 — 입력은 그대로인데 8/6 환율 규칙 변경으로만 총마진이 달라졌다. 다시 매기지 않는다. */
    public function test_paid_before_the_receipt_rate_rule_is_not_re_tiered(): void
    {
        $sm = $this->tierEmployee();
        [$v, $s] = $this->paidEmployeeSettlement($sm, 12_000_000);
        $snap = $s->confirmed_snapshot;
        unset($snap['per_unit_auto']);
        $s->forceFill(['confirmed_snapshot' => $snap, 'paid_at' => '2026-07-08 10:00:00'])->saveQuietly();

        $v->update(['sale_price' => 10_000_000]);
        $this->assertNull($s->fresh()->retieredPerUnitAtClose());
    }

    // ── ③ 판매수당 · 헤이맨 정산금 · 퇴직연금 1/12 ──────────────────────────

    public function test_musabaek_september_numbers(): void
    {
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'pension_split_enabled' => true]);
        PayrollEntry::replaceFor($sm->id, '2026-10', [
            ['label' => '기본급', 'amount' => 2_400_000], ['label' => '식대', 'amount' => 200_000],
            ['label' => '복리', 'amount' => 240_000], ['label' => '전월소급', 'amount' => -240_000],
            ['label' => '판매수당', 'amount' => 8_528_333],   // 엑셀이 640을 650으로 · 전월소급 누락
        ]);
        ExternalSettlementPay::create(['salesman_id' => $sm->id, 'month' => '2026-10', 'amount' => 2_800_000]);
        $s = $this->confirmed($this->krwVehicle($sm, 12_000_000), $sm, 6_400_000);

        $bd = BatchPayoutBreakdown::forMonthPreview('2026-10', Settlement::whereKey($s->id)->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get());
        $p = collect($bd['people'])->firstWhere('salesman_id', $sm->id);

        $this->assertSame(2_600_000, $p['payroll'], '판매수당은 급여 합계에서 빠진다(정산금과 같은 돈)');
        $this->assertSame(2_800_000, $p['heyman_pay']);
        $this->assertSame(983_333, $p['pension'], '(2,600,000 + 6,400,000 + 2,800,000) ÷ 12');
        $this->assertSame(8_216_667, $p['sales_commission_expected']);
        $this->assertSame(311_666, $p['sales_commission_diff'], '급여대장 실수가 차이로 드러난다');
        $this->assertSame(10_816_667, $p['payout'], '그 사람 통장 = 급여 + 정산금 + 헤이맨 − 연금');
        $this->assertSame((int) $p['total_margin'] - 9_000_000, $p['company_contribution'], '헤이맨 몫·연금 분할은 회사이익에 안 들어간다');
        $this->assertSame(11_800_000, $bd['totals']['transfer_total'], '송금 총액엔 연금 몫까지 — 통장에서 나가는 돈');
        $this->assertSame(983_333, $bd['totals']['pension_total']);

        // 급여대장 업로드 미리보기의 대조도 같은 값
        $check = PersonPayoutBreakdown::commissionCheckFor('2026-10', [$sm->id => ['기본급' => 2_400_000, '식대' => 200_000, '복리' => 240_000, '전월소급' => -240_000, '판매수당' => 8_528_333]]);
        $this->assertSame(['sheet' => 8_528_333, 'expected' => 8_216_667, 'diff' => 311_666, 'pension_on' => true, 'pension' => 983_333], $check[$sm->id]);
    }

    public function test_john_september_matches_the_sheet_to_the_won(): void
    {
        $sm = Salesman::create(['name' => '존', 'type' => 'employee', 'is_active' => true, 'pension_split_enabled' => true]);
        PayrollEntry::replaceFor($sm->id, '2026-10', [['label' => '기본급', 'amount' => 2_809_642], ['label' => '판매수당', 'amount' => 3_707_530]]);
        $s = $this->confirmed($this->krwVehicle($sm, 12_000_000), $sm, 3_300_000);

        $bd = BatchPayoutBreakdown::forMonthPreview('2026-10', Settlement::whereKey($s->id)->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get(),
            [['salesman_id' => $sm->id, 'amount' => 1_000_000, 'kind' => 'incentive', 'reason' => '악성재고 판매 추가 인센티브']]);
        $p = collect($bd['people'])->firstWhere('salesman_id', $sm->id);

        $this->assertSame(592_470, $p['pension']);
        $this->assertSame(3_707_530, $p['sales_commission_expected']);
        $this->assertSame(0, $p['sales_commission_diff']);
    }

    public function test_without_the_toggle_commission_equals_settlement_plus_adjustments(): void
    {
        $sm = Salesman::create(['name' => '임윤태', 'type' => 'employee', 'is_active' => true]);
        PayrollEntry::replaceFor($sm->id, '2026-10', [['label' => '기본급', 'amount' => 2_000_000], ['label' => '판매수당', 'amount' => 7_260_000]]);
        $s = $this->confirmed($this->krwVehicle($sm, 12_000_000), $sm, 3_100_000);

        $bd = BatchPayoutBreakdown::forMonthPreview('2026-10', Settlement::whereKey($s->id)->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get(),
            [['salesman_id' => $sm->id, 'amount' => 4_160_000, 'kind' => 'incentive', 'reason' => '마진이익 추가 인센티브']]);
        $p = collect($bd['people'])->firstWhere('salesman_id', $sm->id);

        $this->assertSame(0, $p['pension']);
        $this->assertSame(0, $p['sales_commission_diff']);
        $this->assertSame(2_000_000 + 3_100_000 + 4_160_000, $p['payout'], '판매수당 7,260,000 을 한 번 더 더하지 않는다');
    }

    public function test_monthly_closing_payroll_scope_drops_employee_commission_only(): void
    {
        $e = Salesman::create(['name' => 'E', 'type' => 'employee', 'is_active' => true]);
        $i = Salesman::create(['name' => 'I', 'type' => 'inspector', 'is_active' => true]);
        PayrollEntry::replaceFor($e->id, '2026-10', [['label' => '기본급', 'amount' => 1_000], ['label' => '판매수당', 'amount' => 500]]);
        PayrollEntry::replaceFor($i->id, '2026-10', [['label' => '기본급', 'amount' => 2_000], ['label' => '판매수당', 'amount' => 70]]);

        $this->assertSame(3_070, (int) PayrollEntry::query()->where('month', '2026-10')->countedInPay()->sum('amount'),
            '사내직원 판매수당만 빠진다(검차직원의 같은 이름 항목은 그대로)');
    }

    public function test_salesman_screen_saves_toggle_and_heyman_pay_with_audit(): void
    {
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $this->actingAs($this->user('admin'));

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)
            ->set('payrollMonth', '2026-10')
            ->set('pension_split_enabled', true)
            ->set('heymanPayStr', '2,800,000')
            ->call('save');

        $this->assertTrue($sm->fresh()->pension_split_enabled);
        $this->assertSame(2_800_000, ExternalSettlementPay::heymanFor($sm->id, '2026-10'));
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $sm->id, 'column_name' => 'pension_split_enabled']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $sm->id, 'column_name' => 'heyman_pay_2026-10']);

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('payrollMonth', '2026-10')
            ->assertSet('heymanPayStr', '2800000')->set('heymanPayStr', '')->call('save');
        $this->assertNull(ExternalSettlementPay::heymanFor($sm->id, '2026-10'), '비우면 지운다');
    }
}
