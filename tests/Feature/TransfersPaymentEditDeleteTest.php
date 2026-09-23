<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 재무처리 잔금 2탭 「수정·삭제」 (jin 2026-09-22 «재무처리에 … 금액을 수정하고, 삭제할 수 있는 기능이 없어»).
 *
 * jin 09-23 결정: 범위 = 미확정 + 확정(2차 마감 전) · 미수 부활 시 확정 전 정산 자동 삭제 · 이체 탭은 「거부」로 충분.
 *
 * 화면 버튼이 아니라 **메서드를 직접 불러** 서버 판정을 본다(§8 #26 — 노출은 권한이 아니다).
 * 기능 테스트가 원리상 못 잡는 자리 = 미러 고아 · 감사 무기록 · pending 정산 잔존 — 전부 화면은 정상이다.
 */
class TransfersPaymentEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function soldVehicle(string $currency = 'KRW', int $price = 10_000_000): Vehicle
    {
        $salesman = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $salesman->id]);

        return Vehicle::create([
            'vehicle_number' => '77가'.str_pad((string) (1000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => $currency, 'exchange_rate' => $currency === 'KRW' ? 1 : 1400,
            'dhl_request' => false, 'sale_price' => $price, 'sale_date' => '2026-09-01',
            'purchase_price' => 5_000_000, 'purchase_date' => '2026-08-01',
            'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    private function screen(string $tab)
    {
        return Volt::test('erp.transfers.index')->set('tabType', $tab)->set('statusFilter', 'all');
    }

    // ── 판매 잔금 ──────────────────────────────────────────────

    /** 미확정 판매 잔금: 금액·날짜 수정 → 채권관리 미러가 같이 움직이고, 모델 훅이 안 남기는 감사도 화면이 남긴다. */
    public function test_editing_an_unconfirmed_sale_balance_updates_the_mirror_and_audits(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 4_000_000, 'payment_date' => '2026-09-10']);
        $mirror = ReceivableHistory::where('final_payment_id', $fp->id)->first();
        $this->assertNotNull($mirror, '픽스처: 미러가 안 생겼다');

        $this->screen('sale_payment')
            ->call('openEditPaymentModal', $fp->id)
            ->assertSet('editAmountStr', '4000000')
            ->set('editAmountStr', '3500000')->set('editDate', '2026-09-12')->set('editNote', '정정')
            ->call('saveEditedPayment')
            ->assertHasNoErrors()
            ->assertSet('showEditPaymentModal', false);

        $fp->refresh();
        $this->assertSame(3_500_000.0, (float) $fp->amount);
        $this->assertSame('2026-09-12', $fp->payment_date->format('Y-m-d'));
        $this->assertNull($fp->confirmed_at, '수정이 확정 여부를 건드렸다');
        $this->assertSame(3_500_000.0, (float) $mirror->fresh()->amount, '채권관리 미러가 옛 금액을 보여준다');
        $this->assertSame('2026-09-12', $mirror->fresh()->collected_at->format('Y-m-d'));

        $audit = AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fp->id)->where('column_name', 'amount')->get();
        $this->assertCount(1, $audit, '미확정 행 정정이 감사에 안 남았다(모델 훅은 미확정을 건너뛴다)');
        $this->assertEquals(4_000_000, (float) $audit->first()->old_value);
        $this->assertEquals(3_500_000, (float) $audit->first()->new_value);
    }

    /** 확정(마감 전) 판매 잔금도 수정된다 — 감사는 모델 훅 한 번만(이중 기록 없음). */
    public function test_a_confirmed_but_not_closed_sale_balance_can_be_edited_once_audited(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 4_000_000, 'payment_date' => '2026-09-10', 'confirmed_at' => now()]);

        $this->screen('sale_payment')
            ->call('openEditPaymentModal', $fp->id)
            ->set('editAmountStr', '3000000')->set('editDate', '2026-09-10')
            ->call('saveEditedPayment')
            ->assertHasNoErrors();

        $this->assertSame(3_000_000.0, (float) $fp->fresh()->amount);
        $this->assertNotNull($fp->fresh()->confirmed_at);
        $this->assertSame(1, AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fp->id)->where('column_name', 'amount')->count(), '확정 행 정정 감사가 0 이거나 이중이다');
    }

    /** 2차 마감된 차량은 버튼도 없고, 메서드를 직접 불러도 거부된다. */
    public function test_a_closed_vehicle_is_refused_even_when_the_method_is_called_directly(): void
    {
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-09-10', 'confirmed_at' => now()]);
        Settlement::create([   // auth 없이 만들어 paid 전환 가드를 안 탄다
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id, 'settlement_status' => 'paid',
            'secondary_status' => 'closed', 'attributed_month' => '2026-09-01',
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
        ]);

        $this->actingAs($this->finance());
        $c = $this->screen('sale_payment');
        $c->assertDontSee('deletePayment('.$fp->id.')');
        $c->call('deletePayment', $fp->id)
            ->assertDispatched('notify', type: 'error');
        $c->call('openEditPaymentModal', $fp->id)
            ->assertSet('showEditPaymentModal', false);

        $this->assertNotNull(FinalPayment::find($fp->id), '마감 차량의 잔금이 지워졌다');
    }

    /** 판매 잔금 삭제 = 채권관리 미러가 먼저 지워져 짝 잃은 「입금」 줄이 안 남는다. */
    public function test_deleting_a_sale_balance_leaves_no_orphan_receivable_history(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 4_000_000, 'payment_date' => '2026-09-10']);
        $mirrorId = ReceivableHistory::where('final_payment_id', $fp->id)->value('id');
        $this->assertNotNull($mirrorId);

        $this->screen('sale_payment')->call('deletePayment', $fp->id)->assertDispatched('notify', type: 'success');

        $this->assertNull(FinalPayment::find($fp->id));
        $this->assertNull(ReceivableHistory::find($mirrorId), '채권관리에 고아 「입금」 줄이 남았다');
        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->whereNull('final_payment_id')->where('method', 'deposit')->count());
        $this->assertSame(1, AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fp->id)->where('action', 'deleted')->count(), '삭제 감사로그가 없다');
    }

    /** 현금 원장 회사: 확정 잔금을 현금보다 크게 올리면 게이트가 막고 행은 그대로다(감액은 통과). */
    public function test_cash_gate_blocks_an_increase_beyond_available_cash_but_allows_a_decrease(): void
    {
        Setting::updateOrCreate(['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        $this->actingAs($this->finance());
        $v = $this->soldVehicle('EUR', 50_000);
        BuyerCashReceipt::create(['buyer_id' => $v->buyer_id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 1_000]);
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 1_000, 'payment_date' => '2026-09-04', 'confirmed_at' => now()]);

        $c = $this->screen('sale_payment')->call('openEditPaymentModal', $fp->id);
        $c->set('editAmountStr', '1500')->call('saveEditedPayment')->assertDispatched('notify', type: 'error');
        $this->assertSame(1_000.0, (float) $fp->fresh()->amount, '현금이 없는데 증액이 저장됐다');

        $c->set('editAmountStr', '600')->call('saveEditedPayment')->assertDispatched('notify', type: 'success');
        $this->assertSame(600.0, (float) $fp->fresh()->amount, '감액 정정이 막혔다');
    }

    /** 완납으로 자동 생긴 pending 정산은 잔금을 지워 미수가 되살아나면 같이 지워진다(재완납 시 재생성). */
    public function test_pending_settlement_is_removed_when_unpaid_revives_and_recreated_on_repayment(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-09-10', 'confirmed_at' => now()]);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '픽스처: 완납인데 정산이 안 생겼다');
        $this->assertSame('pending', Settlement::where('vehicle_id', $v->id)->value('settlement_status'));

        $this->screen('sale_payment')->call('deletePayment', $fp->id)->assertDispatched('notify', type: 'warning');

        $this->assertGreaterThan(0, (float) $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0, Settlement::where('vehicle_id', $v->id)->count(), '미수가 되살아났는데 pending 정산이 남아 있다');

        // 다시 완납 → 자동 재생성(소프트삭제 행은 already_exists 에 안 잡힌다)
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-09-11', 'confirmed_at' => now()]);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '재완납 후 정산이 재생성되지 않았다');
    }

    /** confirmed/paid 정산은 건드리지 않는다 — 미수만 되살리고 경고. */
    public function test_a_paid_settlement_is_left_alone_with_a_warning(): void
    {
        $v = $this->soldVehicle();
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-09-10', 'confirmed_at' => now()]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id, 'settlement_status' => 'paid',
            'secondary_status' => 'pending', 'attributed_month' => '2026-09-01',
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
        ]);

        $this->actingAs($this->finance());
        $this->screen('sale_payment')->call('deletePayment', $fp->id)->assertDispatched('notify', type: 'warning');

        $this->assertNull(FinalPayment::find($fp->id), 'paid 정산(마감 전)이 있어도 잔금 삭제 자체는 된다');
        $this->assertNotNull(Settlement::find($s->id), 'paid 정산이 지워졌다 — secondary_status=pending 을 확정 전으로 오인했다');
    }

    // ── 매입 잔금 ──────────────────────────────────────────────

    /** 미확정 매입 잔금(63보5172 오입력 같은 것) 수정·삭제 — 미지급이 그대로 되살아나고 감사가 남는다. */
    public function test_purchase_balance_can_be_edited_and_deleted(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $pbp = $v->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 11_511_000, 'payment_date' => '2026-09-18']);

        $c = $this->screen('purchase_payment');
        $c->call('openEditPaymentModal', $pbp->id)->set('editAmountStr', '11518650')->call('saveEditedPayment')->assertHasNoErrors();
        $this->assertSame(11_518_650.0, (float) $pbp->fresh()->amount);
        $this->assertSame(1, AuditLog::where('auditable_type', PurchaseBalancePayment::class)->where('auditable_id', $pbp->id)->where('column_name', 'amount')->count());

        $c->call('deletePayment', $pbp->id)->assertDispatched('notify', type: 'success');
        $this->assertNull(PurchaseBalancePayment::find($pbp->id));
        $this->assertSame(1, AuditLog::where('auditable_type', PurchaseBalancePayment::class)->where('auditable_id', $pbp->id)->where('action', 'deleted')->count());
    }

    /** 이체(선지급)로 생긴 매입 잔금은 화면에 버튼이 없고 직접 불러도 거부된다. */
    public function test_a_transfer_linked_purchase_balance_is_refused(): void
    {
        $finance = $this->finance();
        $this->actingAs($finance);
        $v = $this->soldVehicle();
        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $v->id, 'target_vehicle_id' => $v->id, 'buyer_id' => $v->buyer_id,
            'amount' => 1_000_000, 'currency' => 'KRW', 'status' => 'executed', 'requester_id' => $finance->id,
        ]);
        $pbp = $v->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 1_000_000, 'payment_date' => '2026-09-10', 'transfer_id' => $transfer->id]);

        $c = $this->screen('purchase_payment');
        $c->assertDontSee('deletePayment('.$pbp->id.')');
        $c->call('deletePayment', $pbp->id)->assertDispatched('notify', type: 'error');
        $this->assertNotNull(PurchaseBalancePayment::find($pbp->id));
    }

    /** 입력 검증 — 0 이하 금액·빈 날짜는 저장되지 않는다. */
    public function test_validation_rejects_zero_amount(): void
    {
        $this->actingAs($this->finance());
        $v = $this->soldVehicle();
        $pbp = $v->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 1_000_000, 'payment_date' => '2026-09-10']);

        $this->screen('purchase_payment')
            ->call('openEditPaymentModal', $pbp->id)
            ->set('editAmountStr', '0')->set('editDate', '')
            ->call('saveEditedPayment')
            ->assertHasErrors(['editAmountStr', 'editDate']);
        $this->assertSame(1_000_000.0, (float) $pbp->fresh()->amount);
    }
}
