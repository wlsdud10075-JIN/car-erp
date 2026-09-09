<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 과입금 정리 두 갈래 — 적립금(바이어 크레딧) / **잡손실(회사 몫)** (jin 2026-09-09).
 *
 * 🧭 왜 잡손실이 필요한가 — 바이어들이 송금 수수료 명목으로 조금씩 더 보내서 금액이 몇십 단위로
 *    남는다. 우리가 수수료를 떠안은 것도 있으니 그 잔돈은 돌려줄 돈이 아니라 회사 돈이다.
 *
 * 🚨 **같이 고친 버그** — 잔금을 감액하면 원장 배분도 줄어 현금이 바이어 지갑으로 **되돌아온다.**
 *    그런데 적립금까지 주고 있었어서 과입금 30 에 크레딧이 60 이 됐다(재현 실측).
 *    운영 피해는 0 이었다(전환 16건 전부 원장 밖 잔금) — 다음 과입금부터 터질 자리였다.
 */
class OverpayMiscLossTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function enableLedger(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    /**
     * 바이어가 10,030 보냈고(수수료 명목 30 더) 잔금도 10,030 확정 → 미수 −30.
     *
     * @param  bool  $withReceipt  원장에 대응 입금을 기재할지 — 원장 «안/밖» 두 경우를 만든다.
     */
    private function overpaidVehicle(bool $withReceipt): array
    {
        $sm = Salesman::create(['name' => 'S'.random_int(1000, 9999), 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'OVERPAY BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '99조9999', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-09-01', 'sale_price' => 10000,
        ]);
        if ($withReceipt) {
            BuyerCashReceipt::create([
                'buyer_id' => $buyer->id, 'currency' => 'EUR',
                'received_date' => '2026-09-01', 'amount' => 10030,
            ]);
        }
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10030,
            'payment_date' => '2026-09-02', 'confirmed_at' => now(),
        ]);

        return [$v->fresh(), $buyer];
    }

    // ── 잡손실 ───────────────────────────────────────────────────

    public function test_misc_loss_zeroes_the_receivable_and_gives_the_buyer_nothing(): void
    {
        [$v, $buyer] = $this->overpaidVehicle(withReceipt: false);
        $this->assertSame(-30.0, (float) $v->sale_unpaid_amount, '전제 — 과입금 30');

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToMiscLoss');

        $v->refresh();
        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '미수가 0 이 안 됐다');
        $this->assertSame(0.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'),
            '잡손실인데 바이어 적립금이 생겼다');
    }

    /**
     * 🚨 **회수이력에 항목으로 남는다** — 회계실사 때 엑셀로 매칭해야 한다(jin 2026-09-09).
     *    그리고 그 행은 **미수 계산에서 제외**돼야 한다 — 안 그러면 0 으로 만든 미수가 다시 −30 이 된다.
     */
    public function test_misc_loss_leaves_a_receivable_history_row_that_does_not_move_the_receivable(): void
    {
        [$v] = $this->overpaidVehicle(withReceipt: false);

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToMiscLoss');

        $row = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'misc_loss')->sole();
        $this->assertSame('30.00', (string) $row->amount);
        $this->assertContains('misc_loss', Vehicle::MIRRORED_RECEIVABLE_METHODS,
            '미수 제외 목록에 없으면 정리 직후 미수가 다시 음수가 된다');
        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount);
    }

    public function test_misc_loss_is_audited_with_its_own_action(): void
    {
        [$v] = $this->overpaidVehicle(withReceipt: false);

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToMiscLoss');

        $log = AuditLog::where('action', 'overpay_converted_to_misc_loss')->sole();
        $this->assertSame($v->id, $log->auditable_id);
        $this->assertStringContainsString('30', (string) $log->new_value);
        // 감사 화면이 영문 식별자를 그대로 찍지 않게 (SKILLS §8 #41)
        $this->assertSame('초과입금 → 잡손실 전환', config('column_labels.actions.overpay_converted_to_misc_loss'));
    }

    /** 🚫 손으로 넣지 못한다 — 잔금 감액 없이 행만 생기면 실사에서 「정리했다는데 돈은 그대로」가 된다. */
    public function test_misc_loss_cannot_be_picked_manually_in_the_history_form(): void
    {
        $this->assertNotContains('misc_loss', ReceivableHistory::MANUAL_METHODS);
        $this->assertContains('misc_loss', ReceivableHistory::METHODS, 'DB enum 대조 목록에는 있어야 한다');
    }

    // ── 🚨 이중 크레딧 (같이 고친 버그) ────────────────────────────

    /**
     * 원장에 물린 잔금을 감액하면 현금이 되돌아온다 — 그만큼 원장에서 **빼야** 한다.
     * 안 그러면 적립금 30 + 되돌아온 현금 30 = 크레딧 60 이 된다.
     */
    public function test_savings_conversion_does_not_double_credit_when_the_payment_is_tracked(): void
    {
        $this->enableLedger();
        // ⚠️ 잔금을 만들 때 로그인 상태여야 현금 게이트가 탄다(`gated()` 가 auth 를 본다).
        //    안 그러면 배분이 안 생겨 「원장 밖 잔금」이 되어 이 테스트가 아무것도 검사하지 않는다.
        $this->actingAs($this->finance());
        [$v, $buyer] = $this->overpaidVehicle(withReceipt: true);
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'), '전제 — 현금이 전액 배분됨');

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount);
        $this->assertSame(30.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'),
            '적립금 30 은 그대로 생겨야 한다');
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'),
            '되돌아온 현금 30 이 원장에 남아 있다 — 적립금과 이중 크레딧');
        $fee = BuyerCashFee::sole();
        $this->assertSame(BuyerCashFee::KIND_OVERPAY, $fee->kind, '과입금 정리로 기록돼야 한다(수수료 아님)');
        $this->assertSame('30.00', (string) $fee->amount);
    }

    /** 잡손실도 같다 — 현금이 되돌아오면 「회사 돈」이라는 말 자체가 거짓이 된다. */
    public function test_misc_loss_takes_the_returned_cash_out_of_the_ledger(): void
    {
        $this->enableLedger();
        // ⚠️ 잔금을 만들 때 로그인 상태여야 현금 게이트가 탄다(`gated()` 가 auth 를 본다).
        //    안 그러면 배분이 안 생겨 「원장 밖 잔금」이 되어 이 테스트가 아무것도 검사하지 않는다.
        $this->actingAs($this->finance());
        [$v, $buyer] = $this->overpaidVehicle(withReceipt: true);

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToMiscLoss');

        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'),
            '잡손실인데 현금이 바이어 지갑으로 돌아갔다');
        $this->assertSame(0.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'));
        $this->assertSame(BuyerCashFee::KIND_OVERPAY, BuyerCashFee::sole()->kind);
    }

    /**
     * 🔑 **원장 밖에서 확정된 잔금은 원장을 건드리지 않는다** — 2026-09-07 에 고친 규칙이 유지되는지.
     *    (토글을 켜기 전에 확정된 잔금은 배분 행이 없어 되돌아올 현금도 없다.)
     */
    public function test_conversion_touches_no_ledger_row_when_the_payment_lives_outside_it(): void
    {
        [$v] = $this->overpaidVehicle(withReceipt: false);   // 잔금 확정 시점엔 토글 OFF
        $this->enableLedger();                               // 그 뒤에 켠다

        Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToMiscLoss')
            ->assertHasNoErrors();

        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount, '원장 밖 잔금 정리가 막혔다');
        $this->assertSame(0, BuyerCashFee::count(), '되돌아온 현금이 없는데 원장 행이 생겼다');
    }

    /** 두 갈림이 **같은 게이트**를 쓴다 — 잡손실이 더 느슨하면 그쪽으로 우회한다. */
    public function test_both_paths_share_the_closed_settlement_gate(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/receivables/index.blade.php'));

        $this->assertSame(1, substr_count($src, 'private function resolveOverpay('),
            '두 버튼이 한 뼈대를 써야 게이트가 갈리지 않는다');
        $this->assertStringContainsString('$this->resolveOverpay(self::OVERPAY_TO_SAVINGS)', $src);
        $this->assertStringContainsString('$this->resolveOverpay(self::OVERPAY_TO_MISC_LOSS)', $src);
        // 래퍼는 얇아야 한다 — 거기에 게이트가 붙는 순간 두 경로가 갈린다.
        foreach (['convertOverpayToSavings', 'convertOverpayToMiscLoss'] as $m) {
            $i = strpos($src, "public function {$m}(): void");
            $body = substr($src, $i, strpos($src, "\n    }", $i) - $i);
            $this->assertStringNotContainsString('hasClosedSecondarySettlement', $body,
                "{$m} 에 게이트가 따로 붙었다 — 공용 뼈대만 판단해야 한다");
            $this->assertStringNotContainsString('canApprove', $body);
        }
    }

    /** 화면에 두 버튼이 다 있고, 무엇이 다른지 화면이 스스로 설명한다 (SKILLS §8 #60). */
    public function test_both_buttons_are_visible_with_an_explanation(): void
    {
        [$v] = $this->overpaidVehicle(withReceipt: false);

        // 패널은 showPanel 로 열린다 — openPanel 이 그 단일 진입점이다.
        $html = Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->html();

        $this->assertStringContainsString(__('receivable.overpay.btn'), $html);
        $this->assertStringContainsString(__('receivable.overpay.btn_misc_loss'), $html);
        $this->assertStringContainsString(__('receivable.overpay.hint_choice'), $html,
            '두 갈래 차이가 화면에 없으면 아무거나 누른다');
    }
}
