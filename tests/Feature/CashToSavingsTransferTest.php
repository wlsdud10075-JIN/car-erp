<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💳 **남은 현금 → 적립금 전환** (jin 2026-10-01, 기획 `buyer-cash-ledger.md` §6 확정 #10).
 *
 * jin: *「차량관리에서 적립금으로 158 을 적립했으면 … 현금탭에서는 들어온 돈이 0 원으로 떨어진 것으로
 *       표현되어야 하는데 이게 양측에 다 적립이 되버리는 문제」* → *「남은 현금이 없는데 어떻게 적립을 해?」*
 *
 * 규칙: 현금 원장을 쓰는 회사의 외화 바이어는 적립금이 **반드시 남은 현금에서** 나온다 —
 * 판매 탭 「적립금 적립」과 현금 탭 「적립금으로」 버튼이 같은 서비스를 불러 적립금 +N · 현금 −N 한 쌍을 만든다.
 * 현금이 모자라면 적립도 안 된다. 원장을 안 쓰는 회사(ssancarerp)·KRW 는 종전대로 적립만.
 */
class CashToSavingsTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function enableCashLedger(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    private function finance(): User
    {
        return User::factory()->create(['role' => '재무', 'email_verified_at' => now()]);
    }

    /** EASY DRIVE 재현 — 입금 4,554 중 4,396 을 운임비 4대에 배분한 뒤 158 이 남은 상태를 간단히: 입금 158 미배분. */
    private function buyerWithCash(float $remaining = 158.0, string $cur = 'EUR'): Buyer
    {
        $b = Buyer::create(['name' => 'EASY DRIVE', 'is_active' => true]);
        BuyerCashReceipt::create(['buyer_id' => $b->id, 'currency' => $cur, 'received_date' => '2026-10-01', 'amount' => $remaining]);

        return $b;
    }

    private function vehicle(Buyer $b, string $cur = 'EUR'): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '234고6217', 'sales_channel' => 'export', 'dhl_request' => false,
            'currency' => $cur, 'exchange_rate' => 1540, 'buyer_id' => $b->id,
            'sale_price' => 13347, 'transport_fee' => 1099, 'sale_date' => '2026-09-01',
        ]);
    }

    // ── 서비스 ─────────────────────────────────────────────────

    public function test_transfer_creates_savings_and_consumes_cash_as_one_pair(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(158);

        app(BuyerCashService::class)->transferToSavings($b->id, 'EUR', 158, null, '테스트');

        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'), '현금 남은 금액이 0 이어야 한다');
        $fee = BuyerCashFee::where('buyer_id', $b->id)->sole();
        $this->assertTrue($fee->isSavingsTransfer());
        $this->assertSame(158.0, (float) $fee->amount);
        $s = SavingsStatus::where('buyer_id', $b->id)->sole();
        $this->assertSame('EARNED', $s->transaction_type);
        $this->assertSame(158.0, (float) $s->savings);
        $this->assertNull($s->exchange_rate, '현금 탭 전환은 환율 NULL(기획 §6)');
    }

    /** 🚨 남은 현금이 모자라면 적립금도 생기지 않는다 — 「남은 돈을 적립」이 뜻이다. */
    public function test_transfer_is_refused_entirely_when_cash_is_short(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(100);

        try {
            app(BuyerCashService::class)->transferToSavings($b->id, 'EUR', 158, null, null);
            $this->fail('모자란데 통과했다');
        } catch (\DomainException) {
        }

        $this->assertSame(0, SavingsStatus::where('buyer_id', $b->id)->count(), '현금 없이 적립금이 생겼다');
        $this->assertSame(0, BuyerCashFee::where('buyer_id', $b->id)->count(), '실패한 전환 행이 남았다');
        $this->assertSame(100.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
    }

    // ── 판매 탭 ────────────────────────────────────────────────

    /** 판매 탭 「적립금 적립」 158 → 적립금 +158 · 현금 남은 금액 0 (EASY DRIVE 가 원했던 결과). */
    public function test_sales_tab_deposit_consumes_remaining_cash_when_ledger_is_on(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(158);
        $v = $this->vehicle($b);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('savings_deposit_str', '158')
            ->call('save')
            ->assertSet('savings_deposit_str', '');

        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'), '판매 탭 적립이 현금을 안 뺐다 — 양쪽에 다 적립된다');
        $s = SavingsStatus::where('buyer_id', $b->id)->sole();
        $this->assertSame(158.0, (float) $s->savings);
        $this->assertSame($v->id, $s->vehicle_id, '판매 탭 적립은 차량을 박제한다');
        $this->assertSame(1540.0, (float) $s->exchange_rate, '판매 탭 적립은 판매환율을 박제한다(종전과 동일)');
        $this->assertTrue(BuyerCashFee::where('buyer_id', $b->id)->sole()->isSavingsTransfer());
    }

    /** 현금이 모자라면 판매 탭에서도 적립되지 않고 부족액을 알린다. 차량 저장 자체는 된다. */
    public function test_sales_tab_deposit_is_blocked_when_cash_is_short(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(100);
        $v = $this->vehicle($b);

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('savings_deposit_str', '158')
            ->call('save');

        $this->assertSame(0, SavingsStatus::where('buyer_id', $b->id)->count(), '현금 없이 적립금이 생겼다');
        $this->assertSame(100.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
        $c->assertSet('savings_deposit_str', '158');   // 입력은 남겨 둔다 — 사람이 무엇을 넣었는지 보게
    }

    /** 대조군 — 현금 원장을 안 쓰는 회사(ssancarerp)는 종전 그대로: 적립금만, 현금 행 없음. */
    public function test_sales_tab_deposit_is_unchanged_when_ledger_is_off(): void
    {
        $this->actingAs($this->finance());
        $b = Buyer::create(['name' => 'NO LEDGER', 'is_active' => true]);
        $v = $this->vehicle($b);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('savings_deposit_str', '158')
            ->call('save');

        $this->assertSame(158.0, (float) SavingsStatus::where('buyer_id', $b->id)->sole()->savings);
        $this->assertSame(0, BuyerCashFee::where('buyer_id', $b->id)->count());
    }

    // ── 현금 탭 ────────────────────────────────────────────────

    /** 현금 탭 「적립금으로 전환」 — 비우면 남은 현금 전부. 판매 탭과 같은 결과. */
    public function test_cash_tab_button_moves_all_remaining_cash(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(158);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('transferCashToSavings', 'EUR')
            ->assertHasNoErrors();

        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
        $this->assertSame(158.0, (float) SavingsStatus::where('buyer_id', $b->id)->sole()->savings);
    }

    public function test_cash_tab_button_reports_shortfall_instead_of_partial_transfer(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(100);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->set('savings_transfer_amount.EUR', '158')
            ->call('transferCashToSavings', 'EUR')
            ->assertHasErrors('savings_transfer_amount.EUR');

        $this->assertSame(100.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
        $this->assertSame(0, SavingsStatus::where('buyer_id', $b->id)->count());
    }

    /** 🚫 전환 행은 현금 탭에서 지울 수 없다 — 현금만 돌아오고 적립금은 남는다. */
    public function test_savings_transfer_row_cannot_be_deleted_from_the_cash_tab(): void
    {
        $this->enableCashLedger();
        $this->actingAs($this->finance());
        $b = $this->buyerWithCash(158);
        $fee = app(BuyerCashService::class)->transferToSavings($b->id, 'EUR', 158, null, null);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('deleteCashFee', $fee->id);

        $this->assertNotNull($fee->fresh(), '적립금 전환 행이 지워졌다 — 이중 크레딧');
        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
    }
}
