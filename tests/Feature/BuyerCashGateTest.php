<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 현금 원장 3단계 — 문지기 + 배분. 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🔑 **소진 시점 = 재무 확정 시점**(미수가 주는 시점과 같다). 생성 시점에 빼면 Draft 구간에서
 *    「현금은 줄었는데 미수는 그대로」가 되어 두 숫자를 대조할 수 없다.
 *    실측 근거 = `test_receivable_deposit_creates_a_draft_that_does_not_move_either_number`.
 *
 * 🚫 제외 경로는 **이유와 함께** 박제한다 — 다음 사람이 「구멍」으로 오해하고 막지 않게.
 */
class BuyerCashGateTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function sales(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function vehicle(?Buyer $buyer, string $currency = 'EUR'): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $buyer?->salesman_id ?? Salesman::create(['name' => 'X'.$this->n, 'is_active' => true])->id,
            'buyer_id' => $buyer?->id,
            'sale_price' => 50000,
            'sale_date' => now()->toDateString(),
        ]);
    }

    private function enable(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    private function cash(Buyer $buyer, float $amount, string $currency = 'EUR', string $date = '2026-09-01'): BuyerCashReceipt
    {
        return BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => $currency,
            'received_date' => $date, 'amount' => $amount,
        ]);
    }

    private function confirmedBalance(Vehicle $v, float $amount): FinalPayment
    {
        return FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => $amount,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);
    }

    // ── 문지기 ───────────────────────────────────────────────────

    /** 토글이 꺼진 회사는 종전 그대로 — 현금이 없어도 막히지 않는다. */
    public function test_gate_is_inert_while_the_toggle_is_off(): void
    {
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle($this->buyer());

        $fp = $this->confirmedBalance($vehicle, 4000);

        $this->assertNotNull($fp->id);
        $this->assertSame(0, BuyerCashAllocation::count(), '토글 OFF 인데 배분이 생겼다');
    }

    public function test_confirmed_balance_is_blocked_without_cash(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle($this->buyer());

        try {
            $this->confirmedBalance($vehicle, 4000);
            $this->fail('현금이 없는데 판매잔금이 들어갔다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('현금', $e->getMessage());
        }

        $this->assertSame(0, FinalPayment::count(), '막혔는데 잔금 행이 남았다');
    }

    public function test_confirmed_balance_consumes_cash_and_leaves_a_row(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = $this->cash($buyer, 10000);

        $fp = $this->confirmedBalance($vehicle, 4000);

        $this->assertSame(6000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $allocation = BuyerCashAllocation::sole();
        $this->assertSame($receipt->id, $allocation->receipt_id);
        $this->assertSame($fp->id, $allocation->final_payment_id);
        $this->assertSame($vehicle->id, $allocation->vehicle_id);
        $this->assertSame('4000.00', (string) $allocation->amount);
    }

    /** FIFO — 오래 받은 돈부터. 한 잔금이 두 입금에 걸치므로 배분이 2줄이 된다. */
    public function test_one_balance_spans_two_receipts_oldest_first(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $early = $this->cash($buyer, 3000, 'EUR', '2026-09-01');
        $late = $this->cash($buyer, 7000, 'EUR', '2026-09-02');

        $fp = $this->confirmedBalance($vehicle, 10000);

        $rows = BuyerCashAllocation::where('final_payment_id', $fp->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$early->id, $late->id], $rows->pluck('receipt_id')->all(), '오래된 입금부터 안 썼다');
        $this->assertSame(['3000.00', '7000.00'], $rows->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
    }

    /** 있는 것보다 조금이라도 더 쓰려 하면 막힌다 — 「그만큼만 쓴다」가 이 테스트다. */
    public function test_cannot_spend_more_than_received(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 1000);

        $this->expectException(\DomainException::class);
        $this->confirmedBalance($vehicle, 1000.01);
    }

    // ── Draft — 확정 시점에만 소진한다 ────────────────────────────

    /**
     * 영업이 넣은 Draft 는 막지 않고 현금도 안 준다.
     * ⚠️ 이게 「생성 시점 소진」과 갈리는 지점이다 — Draft 는 미수도 안 줄이므로,
     *    현금만 빼면 두 숫자가 어긋난 채 남는다.
     */
    public function test_draft_neither_blocks_nor_consumes(): void
    {
        $this->enable();
        $this->actingAs($this->sales());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);

        $fp = FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance',
            'amount' => 9999, 'payment_date' => '2026-09-04',
        ]);

        $this->assertNotNull($fp->id, '현금이 없어도 Draft 는 저장돼야 한다');
        $this->assertSame(0, BuyerCashAllocation::count());
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
    }

    /** 그 Draft 를 재무가 확정하는 순간 현금이 빠진다. */
    public function test_confirming_a_draft_consumes_cash(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 5000);

        $this->actingAs($this->sales());
        $fp = FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance',
            'amount' => 2000, 'payment_date' => '2026-09-04',
        ]);

        $finance = $this->finance();
        $this->actingAs($finance);
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);

        $this->assertSame(3000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(1, BuyerCashAllocation::where('final_payment_id', $fp->id)->count());
    }

    /** 현금이 모자라면 확정에서 막히고, Draft 는 그대로 남는다(적은 사실은 안 지운다). */
    public function test_confirming_without_cash_is_blocked(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);

        $this->actingAs($this->sales());
        $fp = FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance',
            'amount' => 2000, 'payment_date' => '2026-09-04',
        ]);

        $finance = $this->finance();
        $this->actingAs($finance);

        $this->expectException(\DomainException::class);
        try {
            app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);
        } finally {
            $this->assertNull($fp->fresh()->confirmed_at, '막혔는데 확정이 남았다');
        }
    }

    /**
     * 🔎 **실측 박제** — 채권관리 「입금」이 만드는 미러 잔금은 Draft 다.
     *    그래서 그 시점엔 미수도 현금도 안 움직인다. 이 사실이 「확정 시점 소진」의 근거다.
     */
    public function test_receivable_deposit_creates_a_draft_that_does_not_move_either_number(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 10000);
        $before = $vehicle->sale_unpaid_amount;

        $rh = ReceivableHistory::create([
            'vehicle_id' => $vehicle->id, 'collected_at' => '2026-09-04',
            'method' => 'deposit', 'amount' => 1000,
        ]);

        $mirror = FinalPayment::find($rh->fresh()->final_payment_id);
        $this->assertNotNull($mirror, '미러 잔금이 안 생겼다');
        $this->assertNull($mirror->confirmed_at, '미러 잔금이 Draft 가 아니다 — 소진 시점 전제가 깨진다');
        $this->assertSame($before, $vehicle->fresh()->sale_unpaid_amount, '미수가 움직였다');
        $this->assertSame(10000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'), '현금이 움직였다');
    }

    /** 그 미러 잔금을 재무가 확정하면 미수와 현금이 **같은 순간에** 움직인다. */
    public function test_confirming_the_receivable_mirror_moves_both_numbers_once(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 10000);

        $finance = $this->finance();
        $this->actingAs($finance);
        $rh = ReceivableHistory::create([
            'vehicle_id' => $vehicle->id, 'collected_at' => '2026-09-04',
            'method' => 'deposit', 'amount' => 1000,
        ]);
        $before = $vehicle->fresh()->sale_unpaid_amount;

        app(PaymentConfirmationService::class)->confirmPayment(
            FinalPayment::find($rh->fresh()->final_payment_id), $finance
        );

        // 미수 1,000 감소 · 현금 1,000 감소 — 각각 **한 번씩**만.
        $this->assertSame($before - 1000, $vehicle->fresh()->sale_unpaid_amount);
        $this->assertSame(9000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(1, BuyerCashAllocation::count(), '배분이 두 번 잡혔다(이중계상)');
    }

    // ── 정정·회수 ────────────────────────────────────────────────

    /** 확정분의 금액이 늘면 배분도 따라 늘어야 한다(안 그러면 현금과 잔금이 어긋난다). */
    public function test_raising_a_confirmed_amount_reallocates(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 10000);
        $fp = $this->confirmedBalance($vehicle, 3000);

        $fp->update(['amount' => 8000]);

        $this->assertSame(2000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(
            8000.0,
            (float) BuyerCashAllocation::where('final_payment_id', $fp->id)->sum('amount'),
        );
    }

    /** 늘리려는데 현금이 모자라면 막힌다 — 금액도 안 바뀐다. */
    public function test_raising_beyond_cash_is_blocked(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 5000);
        $fp = $this->confirmedBalance($vehicle, 3000);

        try {
            $fp->update(['amount' => 9000]);
            $this->fail('현금보다 큰 금액으로 늘어났다');
        } catch (\DomainException) {
            // 기대한 차단
        }

        $this->assertSame(3000.0, (float) $fp->fresh()->amount, '막혔는데 금액이 바뀌었다');
        $this->assertSame(2000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
    }

    /** 회수 = 그 잔금 행을 지우는 것. 배분이 cascade 로 사라지고 현금이 돌아온다(jin 확정 #8). */
    public function test_deleting_the_balance_returns_the_cash(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 10000);
        $fp = $this->confirmedBalance($vehicle, 4000);
        $this->assertSame(6000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));

        $fp->delete();

        $this->assertSame(0, BuyerCashAllocation::count());
        $this->assertSame(10000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
    }

    /**
     * 🚨 채권관리에서 **확정된** 입금의 금액을 고치면 `syncFinalPayment` 가
     *    `FinalPayment::where(...)->update()` 로 바꾼다 — **query-builder 라 모델 훅이 안 뜬다**.
     *    그대로 두면 금액만 바뀌고 현금은 그대로라 둘이 조용히 어긋난다(SKILLS §8 #66 의 그 자리).
     */
    public function test_editing_a_confirmed_receivable_amount_resyncs_the_cash(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 10000);

        $finance = $this->finance();
        $this->actingAs($finance);
        $rh = ReceivableHistory::create([
            'vehicle_id' => $vehicle->id, 'collected_at' => '2026-09-04',
            'method' => 'deposit', 'amount' => 1000,
        ]);
        app(PaymentConfirmationService::class)->confirmPayment(
            FinalPayment::find($rh->fresh()->final_payment_id), $finance
        );
        $this->assertSame(9000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));

        $rh->fresh()->update(['amount' => 2500]);

        $this->assertSame(7500.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'),
            '금액만 바뀌고 현금이 안 따라왔다 — raw update 경로의 재배분이 빠졌다');
        $this->assertSame(
            2500.0,
            (float) BuyerCashAllocation::where('final_payment_id', $rh->fresh()->final_payment_id)->sum('amount'),
        );
    }

    /** 그 경로로도 현금보다 많이는 못 쓴다. */
    public function test_editing_a_confirmed_receivable_beyond_cash_is_blocked(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 2000);

        $finance = $this->finance();
        $this->actingAs($finance);
        $rh = ReceivableHistory::create([
            'vehicle_id' => $vehicle->id, 'collected_at' => '2026-09-04',
            'method' => 'deposit', 'amount' => 1000,
        ]);
        app(PaymentConfirmationService::class)->confirmPayment(
            FinalPayment::find($rh->fresh()->final_payment_id), $finance
        );

        $this->expectException(\DomainException::class);
        $rh->fresh()->update(['amount' => 5000]);
    }

    // ── 막히기 전에 보인다 ───────────────────────────────────────

    /**
     * 판매 탭에 바이어 현금 잔액이 **저장 전에** 보여야 한다 — 막히고 나서 알려주는 것보다 낫다.
     * 🚫 조건 판정은 화면이 따로 하지 않는다(BuyerCashService::gated 단일 출처) — 갈리면
     *    「안내는 없는데 저장이 막히는」 형태가 된다.
     */
    public function test_sale_tab_shows_the_buyer_cash_before_saving(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 6000);

        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->html();

        $this->assertStringContainsString('6,000.00 EUR', $html, '현금 잔액이 판매 탭에 안 보인다');
    }

    /** 토글이 꺼져 있으면 아무것도 안 그린다 — 안 쓰는 회사 화면을 어지럽히지 않는다. */
    public function test_sale_tab_shows_nothing_while_the_toggle_is_off(): void
    {
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $this->cash($buyer, 6000);

        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->html();

        $this->assertStringNotContainsString('6,000.00 EUR', $html);
    }

    // ── 제외 경로 (이유와 함께 박제) ──────────────────────────────

    /** 🚨 KRW 차량은 안 막는다 — 국내 거래·원화 판매가 첫날부터 막히면 안 된다. */
    public function test_krw_vehicle_is_not_gated(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle($this->buyer(), 'KRW');

        $fp = $this->confirmedBalance($vehicle, 5_000_000);

        $this->assertNotNull($fp->id);
        $this->assertSame(0, BuyerCashAllocation::count());
    }

    /** 바이어가 없으면 뺄 지갑도 없다 — 막지 않는다. */
    public function test_vehicle_without_buyer_is_not_gated(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle(null);

        $this->assertNotNull($this->confirmedBalance($vehicle, 1000)->id);
    }

    /** 계약금·중도금·선수금·수수료는 jin 명시 제외 — type 이 balance 가 아니다. */
    public function test_non_balance_types_are_not_gated(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle($this->buyer());

        foreach (['deposit_down', 'interim', 'advance_1', 'fee'] as $type) {
            $fp = FinalPayment::create([
                'vehicle_id' => $vehicle->id, 'type' => $type, 'amount' => 1000,
                'payment_date' => '2026-09-04', 'confirmed_at' => now(),
            ]);
            $this->assertNotNull($fp->id, "{$type} 이 막혔다");
        }
        $this->assertSame(0, BuyerCashAllocation::count());
    }

    /** 콘솔 적재·시드(auth 없음)는 통과 — 기존 마감 가드와 같은 관례. */
    public function test_console_import_is_not_gated(): void
    {
        $this->enable();
        $vehicle = $this->vehicle($this->buyer());   // actingAs 없음

        $this->assertNotNull($this->confirmedBalance($vehicle, 1000)->id);
        $this->assertSame(0, BuyerCashAllocation::count());
    }

    /** 명시 우회 플래그 — 이미 받은 돈을 사후에 적는 복구 스크립트용. */
    public function test_skip_flag_bypasses_the_gate(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $vehicle = $this->vehicle($this->buyer());

        FinalPayment::$skipCashGate = true;
        try {
            $fp = $this->confirmedBalance($vehicle, 1000);
        } finally {
            FinalPayment::$skipCashGate = false;
        }

        $this->assertNotNull($fp->id);
        $this->assertSame(0, BuyerCashAllocation::count());
    }

    /** 통화가 다른 입금은 안 쓰인다 — EUR 차량이 USD 현금을 끌어다 쓰면 안 된다(확정 #3). */
    public function test_other_currency_cash_is_not_usable(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer, 'EUR');
        $this->cash($buyer, 10000, 'USD');

        $this->expectException(\DomainException::class);
        $this->confirmedBalance($vehicle, 1000);
    }

    /** 다른 바이어의 현금도 못 쓴다. */
    public function test_another_buyers_cash_is_not_usable(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $mine = $this->buyer();
        $other = $this->buyer();
        $vehicle = $this->vehicle($mine);
        $this->cash($other, 10000);

        $this->expectException(\DomainException::class);
        $this->confirmedBalance($vehicle, 1000);
    }
}
