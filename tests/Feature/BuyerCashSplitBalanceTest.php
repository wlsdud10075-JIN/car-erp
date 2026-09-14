<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔗 **한 잔금이 입금 여러 건에 걸칠 때** — 두 화면이 같은 이야기를 해야 한다 (jin 2026-09-14 제보).
 *
 * 실사고: AUTO SCOUT `05두6299` 잔금 **9,040 EUR** 이 FIFO 로
 *   09-07 입금의 남은 돈 **2,280** + 09-14 입금에서 **6,760** 으로 나뉘어 빠졌다.
 * 바이어 정산현황은 **입금별로 묶여 있어** 같은 차가 두 줄로 보이는데 총액 표시가 없었다
 * ⇒ jin 이 *「2,280 이 한 번 더 찍힌 것 같다 / 뭔가 꼬인 것 같다」* 로 읽었다. **금액은 정확했다.**
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 배분도 금액도 정상이고 두 화면 다 정상 렌더된다.
 *    달라지는 건 「사람이 읽을 수 있는가」뿐이라 **렌더 결과에서 문자열을 직접 센다**.
 *
 * ⚠️ 특히 `BuyerAccountService::cashUsage` 의 `finalPayment:` **부분 select** 를 조심할 것 —
 *    거기서 `amount` 가 빠지면 총액이 늘 0 이 되어 이 표기가 **통째로 사라진다**(§8 #83 의 그 형태).
 */
class BuyerCashSplitBalanceTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function vehicle(Buyer $buyer, float $price): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1400,
            'dhl_request' => false, 'salesman_id' => $buyer->salesman_id, 'buyer_id' => $buyer->id,
            'sale_price' => $price, 'sale_date' => '2026-09-01',
        ]);
    }

    private function enable(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    private function receipt(Buyer $b, string $date, float $amount): BuyerCashReceipt
    {
        return BuyerCashReceipt::create([
            'buyer_id' => $b->id, 'currency' => 'EUR', 'received_date' => $date, 'amount' => $amount,
        ]);
    }

    private function balance(Vehicle $v, string $date, float $amount): FinalPayment
    {
        return FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => $amount,
            'payment_date' => $date, 'confirmed_at' => now(),
        ]);
    }

    /**
     * 05두6299 의 모양을 그대로 재현한다 (금액만 줄여서).
     *   09-07 입금 913 → 다른 차가 685 가져감 → 남은 228
     *   09-14 입금 950 → 대상 차 잔금 904 = **228(09-07) + 676(09-14)**
     *
     * @return array{0:Buyer, 1:Vehicle, 2:FinalPayment}
     */
    private function splitScenario(): array
    {
        // ⚠️ `BuyerCashService::allocate` 는 `auth()->check()` 가 아니면 통째로 건너뛴다
        //    (시드·artisan 대량 유입 차단). 로그인 없이 만들면 배분이 0 이라 시나리오가 안 선다.
        $this->actingAs($this->finance());

        $buyer = $this->buyer();
        $other = $this->vehicle($buyer, 685);
        $target = $this->vehicle($buyer, 904);

        $this->receipt($buyer, '2026-09-07', 913);
        $this->balance($other, '2026-09-07', 685);     // 09-07 입금에서 685 → 228 남음
        $this->receipt($buyer, '2026-09-14', 950);
        $fp = $this->balance($target, '2026-09-14', 904);  // 228 + 676

        // 전제 확인 — 깨지면 아래 단언은 아무것도 검사하지 않는다.
        //   ⚠️ FinalPayment 에는 allocations 관계가 없다 — 배분 모델에서 직접 읽는다.
        $allocs = BuyerCashAllocation::where('final_payment_id', $fp->id)
            ->orderBy('id')->get();
        $this->assertCount(2, $allocs, '잔금이 입금 둘에 걸치지 않았다 — 시나리오가 성립하지 않는다');
        $this->assertEqualsWithDelta(904.0, (float) $allocs->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(228.0, (float) $allocs[0]->amount, 0.01, 'FIFO 가 오래된 입금부터 안 썼다');

        return [$buyer, $target, $fp];
    }

    // ── 바이어 정산현황 ──────────────────────────────────────────

    /**
     * 쪼개진 줄엔 **「잔금 904.00 중」**이 붙어야 한다. 안 붙으면 같은 차가 두 줄로만 보여
     * 「두 번 냈다」로 읽힌다 — 그게 이번 제보의 원인이다.
     */
    public function test_split_rows_say_which_balance_they_belong_to(): void
    {
        $this->enable();
        [$buyer] = $this->splitScenario();

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertSame(2, substr_count($html, __('buyer_account.used_of_balance', ['total' => '904.00'])),
            '쪼개진 두 줄 모두에 「잔금 904.00 중」이 붙어야 한다');
    }

    /** 🚫 한 입금으로 전액을 낸 줄엔 안 붙인다 — 모든 줄에 붙으면 노이즈가 되어 아무도 안 읽는다. */
    public function test_a_fully_covered_balance_gets_no_extra_label(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, 100);
        $this->receipt($buyer, '2026-09-01', 500);
        $this->balance($v, '2026-09-05', 100);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertStringNotContainsString(__('buyer_account.used_of_balance', ['total' => '100.00']), $html,
            '입금 하나로 전액을 낸 줄에까지 총액을 붙이고 있다');
    }

    /**
     * ⚠️ `cashUsage` 의 부분 select 에서 `amount` 가 빠지면 총액이 0 이 되어 표기가 사라진다.
     *    관계를 실제로 읽어 확인한다 — 이게 §8 #83 의 그 함정 자리다.
     */
    public function test_the_eager_load_actually_carries_the_balance_amount(): void
    {
        $this->enable();
        [$buyer] = $this->splitScenario();

        $rows = app(BuyerAccountService::class)->cashUsage($buyer, 10);
        $amounts = $rows->flatMap->allocations
            ->map(fn ($a) => (float) ($a->finalPayment?->amount ?? 0));

        $this->assertNotContains(0.0, $amounts->all(),
            'eager load 에 amount 가 안 실려 잔금 총액이 0 이다 — 표기가 통째로 사라진다');
    }

    // ── 차량 판매탭 ─────────────────────────────────────────────

    /** 판매탭 「현금에서 차감」 줄은 **합계**를 먼저 보여준다 — 조각만 나열하면 대조가 안 된다. */
    public function test_sale_tab_shows_the_total_when_one_balance_spans_receipts(): void
    {
        $this->enable();
        [, $target] = $this->splitScenario();

        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->html();

        $this->assertStringContainsString(
            __('vehicle.panel.cash_drawn_sum', ['sum' => '904.00', 'count' => 2]), $html,
            '판매탭이 쪼개진 차감의 합계를 안 보여준다');
    }

    /** 🚫 입금 하나로 끝난 잔금엔 합계 줄을 안 붙인다(같은 숫자가 두 번 보이면 그것도 헷갈린다). */
    public function test_sale_tab_omits_the_total_for_a_single_receipt(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, 100);
        $this->receipt($buyer, '2026-09-01', 500);
        $this->balance($v, '2026-09-05', 100);

        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->html();

        $this->assertStringNotContainsString(
            __('vehicle.panel.cash_drawn_sum', ['sum' => '100.00', 'count' => 1]), $html);
    }

    /** 두 화면이 **같은 조각 금액**을 말하는지 — 갈리면 대조하다 또 「꼬였다」가 된다. */
    public function test_both_screens_agree_on_the_same_pieces(): void
    {
        $this->enable();
        [$buyer, $target] = $this->splitScenario();

        $account = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)->html();
        $panel = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $target->id)->html();

        foreach (['228.00', '676.00'] as $piece) {
            $this->assertStringContainsString($piece, $account, "바이어 정산현황에 {$piece} 가 없다");
            $this->assertStringContainsString($piece, $panel, "판매탭에 {$piece} 가 없다");
        }
    }
}
