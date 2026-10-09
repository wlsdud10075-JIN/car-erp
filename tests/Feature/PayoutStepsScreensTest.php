<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🪜 월정산 v3 결재선 — 화면 (7일차, 2026-10-09).
 *   제출 모달(결재선 선택·인센티브 종류·급여 미입력 경고) → 월정산 화면(결재선 strip·결재 내역·인센티브 수정·노란 표시) → 폰 승인 링크(인센티브 추가·의견).
 */
class PayoutStepsScreensTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0109876'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now(),
        ]);
    }

    private function settlement(): Settlement
    {
        $sm = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $v = Vehicle::create([
            'vehicle_number' => 'SC'.++$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 20_000, 'sale_date' => '2026-10-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 20_000, 'exchange_rate' => 1400, 'payment_date' => '2026-10-10', 'confirmed_at' => now()]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ]);
    }

    public function test_submit_modal_offers_the_line_and_submits_steps_with_an_incentive(): void
    {
        $s = $this->settlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $this->actingAs($manager);

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->call('openSubmitModal');
        $c->assertSet('showSubmitModal', true)
            ->assertSeeHtml('data-approval-line')
            ->assertSet('submitLine.부장', (string) $bu->id)     // 직급에 한 명뿐이면 자동 선택
            ->assertSet('submitLine.대표', (string) $ceo->id)
            ->assertSeeHtml('data-payroll-missing-warn')          // 급여 미입력 경고(제출은 막지 않는다)
            ->assertSee('이영업');

        $c->set('newAdjSalesmanId', (string) $s->salesman_id)->set('newAdjKind', 'incentive')
            ->set('newAdjAmount', '500,000')->set('newAdjReason', '10월 실적 우수')->call('addSubmitAdjustment')
            ->assertSee(__('settlement.batch.adjust_kind_incentive'))
            ->call('submitPayoutBatch')->assertSet('showSubmitModal', false);

        $batch = SettlementPayoutBatch::where('month', '2026-10')->firstOrFail();
        $this->assertTrue($batch->isStepsMode());
        $this->assertSame(['부장', '대표'], $batch->steps->pluck('title')->all(), '전무는 사람이 없어 건너뜀');
        $this->assertSame('incentive', $batch->adjustments()->value('kind'));
        $this->assertSame([$bu->id], $batch->currentApprovers()->pluck('id')->all());
    }

    public function test_batch_screen_shows_steps_log_and_mid_flow_incentive_with_highlight(): void
    {
        $s = $this->settlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);

        // 부장 — 의견과 함께 결재
        $this->actingAs($bu);
        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id);
        $c->assertSeeHtml('data-approval-steps')->assertSeeHtml('data-step="1" data-step-status="pending"')
            ->assertSee(__('payout_batch.steps.next', ['who' => '부장 '.$bu->name]));
        $c->set('approveNote', '확인했습니다')->call('approve', $batch->id);
        $this->assertSame(2, (int) $batch->fresh()->current_step);

        // 대표 차례인데 부장이 인센티브를 더한다 → 노란 표시 + 결재 내역 + 포인터 불변
        $c = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->assertSeeHtml('data-incentive-form')->assertSeeHtml('data-step="1" data-step-status="approved"')->assertSee('확인했습니다')
            ->set('incSalesmanId', (string) $s->salesman_id)->set('incAmount', '300,000')->set('incReason', '신규 바이어')
            ->call('addIncentive', $batch->id);
        $c->assertSeeHtml('data-changed')->assertSeeHtml('data-approval-log')->assertSee(__('payout_batch.steps.change_incentive'));
        $this->assertSame(2, (int) $batch->fresh()->current_step, '수정해도 멈춘 단계부터 이어서');
        $this->assertSame(1, $batch->batchChanges()->count());

        // 대표에겐 결재 버튼이 있고, 결재선 밖 최고관리자에겐 없다
        // 「승인」 글자는 제목에도 있다 — 버튼 자체(wire:click)를 본다
        $this->actingAs($ceo);
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertSeeHtml('wire:click="approve('.$batch->id.')"');
        $this->actingAs($this->user('admin'));
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->assertDontSeeHtml('wire:click="approve('.$batch->id.')"');
    }

    public function test_phone_link_shows_steps_and_adds_an_incentive_without_moving_the_step(): void
    {
        $s = $this->settlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);

        $show = URL::temporarySignedRoute('payout.approve.show', now()->addDay(), ['batch' => $batch->id, 'u' => $bu->id]);
        $html = $this->get($show)->assertOk()->getContent();
        $this->assertStringContainsString('data-approval-steps', $html);
        $this->assertStringContainsString('data-incentive-form', $html, '부장은 폰에서 인센티브를 더할 수 있다');

        $decide = URL::temporarySignedRoute('payout.approve.decide', now()->addHour(), ['batch' => $batch->id, 'u' => $bu->id]);
        $page = $this->post($decide, ['action' => 'incentive', 'salesman_id' => $s->salesman_id, 'amount' => '300,000', 'reason' => '신규 바이어'])
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-incentive-notice', $page);
        $this->assertStringContainsString('data-changed', $page, '카드에 「변경됨」');
        $batch = $batch->fresh();
        $this->assertSame(1, (int) $batch->current_step, '폰에서 더해도 포인터 불변');
        $this->assertSame(300_000, (int) $batch->adjustments()->where('kind', 'incentive')->sum('amount'));

        // 그 뒤 의견과 함께 결재 → 대표 차례
        $this->post($decide, ['action' => 'approve', 'note' => '확인'])->assertOk();
        $batch = $batch->fresh();
        $this->assertSame(2, (int) $batch->current_step);
        $this->assertSame('확인', $batch->steps()->where('seq', 1)->value('note'));
    }

    public function test_rejected_batch_keeps_its_cards_on_screen(): void
    {
        $this->settlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);
        $batch->rejectBy($bu, '재검토');

        $this->actingAs($manager);
        Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)
            ->assertSeeHtml('data-rejected-kept')->assertSeeHtml('data-person-card=')->assertSee('이영업');
    }

    public function test_payroll_edit_during_approval_is_logged_on_the_batch(): void
    {
        $s = $this->settlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);

        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.salesmen.index')->call('openEdit', $s->salesman_id)->set('payrollMonth', '2026-10')
            ->set('payrollItems.0', '2,740,000')->call('save');

        $this->assertSame(2_740_000, PayrollEntry::totalFor($s->salesman_id, '2026-10'));
        $change = $batch->batchChanges()->first();
        $this->assertNotNull($change, '결재 중 급여 수정은 배치 변경 이력에 남아야 한다');
        $this->assertSame('payroll', $change->field);
        $this->assertSame(2_740_000, $change->after);
    }
}
