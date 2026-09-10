<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🗓️ 현금 배분 줄의 **날짜** — 바이어탭 「현금」 · 바이어 정산현황 양쪽 (jin 2026-09-10 제보).
 *
 * 제보: 「기록되는 날짜나 수정사항이 반영되는 게 이상하게 보인다」. 금액은 맞았다(실측 검산 일치).
 * 빠진 것은 **각 줄이 언제 것인지**였다.
 *
 * 두 가지가 겹쳐 있었다:
 *  ① 바이어탭 「쓴 내역」에 날짜가 아예 없었다 — 원장 수수료는 라벨조차 없어 `- 6.00` 으로 찍혔다.
 *  ② `allocate()` 는 잔금이 바뀔 때마다 배분을 지우고 FIFO 로 다시 깐다. 먼저 받은 돈을 다른 차가
 *     차지하면 그 잔금은 **나중 입금**으로 밀리고, 화면엔 「09-10 에 받은 돈이 09-09 에 쓰였다」로만
 *     보인다(실측 heymanerp: 09-10 입금이 368머4746 의 09-09 잔금 0.49 를 메웠다).
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는 부류다** — 배분도 금액도 정상이고 화면도 정상 렌더된다.
 *    그래서 렌더 결과에서 **날짜 문자열과 뱃지를 직접 센다**.
 */
class BuyerCashAllocationDateTest extends TestCase
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

    private function vehicle(Buyer $buyer): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $buyer->salesman_id,
            'buyer_id' => $buyer->id,
            'sale_price' => 10000,
            'sale_date' => '2026-09-01',
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
            'buyer_id' => $b->id, 'currency' => 'EUR',
            'received_date' => $date, 'amount' => $amount,
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
     * 운영에서 실제로 벌어진 순서를 그대로 재현한다.
     *   09-09 입금 10 → (그 돈을 09-10 잔금이 먼저 가져간다) → 09-10 입금 10
     *   → 09-09 잔금을 뒤늦게 기입 → 남은 건 09-10 입금뿐이라 **거기서 메운다**.
     *
     * @return array{0:Buyer, 1:Vehicle, 2:Vehicle, 3:BuyerCashReceipt}
     */
    private function backfillScenario(): array
    {
        $buyer = $this->buyer();
        $early = $this->vehicle($buyer);     // 수금일 09-09 (나중에 기입)
        $late = $this->vehicle($buyer);      // 수금일 09-10 (먼저 기입)

        $first = $this->receipt($buyer, '2026-09-09', 10);
        $this->balance($late, '2026-09-10', 10);            // 09-09 입금을 다 가져간다
        $second = $this->receipt($buyer, '2026-09-10', 10);
        $this->balance($early, '2026-09-09', 10);           // 남은 건 09-10 입금뿐 → 역행

        // 전제 확인 — 이게 깨지면 아래 단언은 아무것도 검사하지 않는다.
        $alloc = $second->allocations()->first();
        $this->assertNotNull($alloc, '09-10 입금이 안 쓰였다 — 시나리오가 성립하지 않는다');
        $this->assertSame($early->id, $alloc->vehicle_id,
            '09-10 입금이 09-09 잔금을 메우지 않았다 — FIFO 전제가 깨졌다');
        $this->assertTrue($alloc->isBackfillFor($second->received_date), '역행 판정이 안 선다');
        $this->assertFalse($first->allocations()->first()->isBackfillFor($first->received_date),
            '정상 줄까지 역행으로 잡힌다');

        return [$buyer, $early, $late, $second];
    }

    // ── 날짜가 화면에 있는가 ─────────────────────────────────────

    /** 바이어탭 「쓴 내역」 줄은 **잔금의 수금일**을 보여준다 — 입금일이 아니다. */
    public function test_buyer_tab_shows_the_payment_date_of_each_allocation(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer);
        $this->actingAs($this->finance());
        $this->receipt($buyer, '2026-09-01', 100);
        $this->balance($v, '2026-09-05', 100);   // 입금일과 다른 날

        $rows = Volt::actingAs($this->finance())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->get('cashReceiptList');

        $uses = $rows[0]['uses'];
        $this->assertSame('2026-09-05', $uses[0]['date'],
            '배분 줄이 잔금 수금일을 안 보여준다 (입금일이나 created_at 을 쓰고 있다)');
        $this->assertSame('09-05', $uses[0]['date_short']);
        $this->assertStringContainsString('2026-09-05', $rows[0]['uses_title'],
            '호버 전문에도 날짜가 있어야 표와 뜻이 같다');
    }

    /** 그 날짜가 화면에 실제로 그려지는가 — 배열만 맞고 렌더가 빠지면 사람은 못 본다. */
    public function test_buyer_tab_renders_the_date(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer);
        $this->actingAs($this->finance());
        $this->receipt($buyer, '2026-09-01', 100);
        $this->balance($v, '2026-09-05', 100);

        $html = Volt::actingAs($this->finance())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->html();

        $this->assertStringContainsString('09-05', $html, '쓴 내역 줄에 날짜가 안 그려진다');
    }

    /** 정산현황 화면도 같은 출처(`usedDate`)를 쓴다 — 두 화면이 다른 날짜를 말하면 더 헷갈린다. */
    public function test_account_screen_shows_the_same_date(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer);
        $this->actingAs($this->finance());
        $this->receipt($buyer, '2026-09-01', 100);
        $this->balance($v, '2026-09-05', 100);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertStringContainsString('2026-09-05', $html, '정산현황이 잔금 수금일을 안 보여준다');
    }

    // ── 나중 입금이 메운 줄 ──────────────────────────────────────

    /** 🔁 역행 줄엔 뱃지가 붙는다 — 없으면 「09-10 돈이 09-09 에 쓰였다」로 읽힌다. */
    public function test_backfilled_row_is_marked_on_the_buyer_tab(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        [$buyer] = $this->backfillScenario();

        $c = Volt::actingAs($this->finance())->test('erp.buyers.index')->call('openEdit', $buyer->id);
        $rows = collect($c->get('cashReceiptList'));

        $second = $rows->firstWhere('received_date', '2026-09-10');
        $this->assertTrue($second['uses'][0]['is_backfill'], '나중 입금이 메운 줄에 표시가 없다');
        $first = $rows->firstWhere('received_date', '2026-09-09');
        $this->assertFalse($first['uses'][0]['is_backfill'], '정상 줄에도 표시가 붙었다');

        // 뱃지 **태그 안**만 센다 — 호버 전문(title 속성)에도 같은 낱말이 들어가므로
        //   문자열만 세면 2 가 나온다(그건 정상이다).
        $html = $c->html();
        $this->assertSame(1, substr_count($html, '>'.__('buyer.cash.backfill_badge').'<'),
            '역행 뱃지가 정확히 한 줄에만 붙어야 뜻이 있다');
    }

    /** 정산현황 화면에도 같은 표시 — jin 이 실제로 본 화면이 이쪽이다. */
    public function test_backfilled_row_is_marked_on_the_account_screen(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        [$buyer] = $this->backfillScenario();

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertSame(1, substr_count($html, '>'.__('buyer.cash.backfill_badge').'<'),
            '정산현황에 역행 표시가 없거나 정상 줄까지 붙었다');
    }

    /**
     * 판매탭의 「바이어 현금에서 차감」 줄도 같은 표시를 쓴다 — 세 화면 중 둘만 설명하면
     * 나머지 한 곳에서 같은 혼란이 그대로 재현된다(jin 2026-09-10 «그래야 덜 헷갈린다»).
     */
    public function test_backfilled_row_is_marked_on_the_vehicle_sale_tab(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        [, $early] = $this->backfillScenario();

        $rows = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $early->id)
            ->get('finalPayments');

        $cash = collect($rows)->pluck('cash')->flatten(1)->filter();
        $this->assertNotEmpty($cash, '판매탭에 현금 차감 줄이 없다 — 시나리오가 성립하지 않는다');
        $this->assertTrue($cash->first()['is_backfill'],
            '나중 입금이 메운 줄인데 판매탭엔 표시가 없다');

        // 배열만 맞고 렌더가 빠지면 사람은 못 본다 — 화면에 실제로 그려지는지 센다.
        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')
            ->call('openEdit', $early->id)
            ->html();
        $this->assertSame(1, substr_count($html, '>'.__('buyer.cash.backfill_badge').'<'),
            '판매탭 현금 줄에 역행 뱃지가 안 그려진다');
    }

    // ── 라벨 · 출처 구분 ─────────────────────────────────────────

    /** 원장 수수료 배분은 차량이 없다 — 라벨이 없으면 `- 6.00` 으로 찍혀 잔금처럼 보인다. */
    public function test_ledger_fee_allocation_has_a_label(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->actingAs($this->finance());
        $this->receipt($buyer, '2026-09-09', 100);
        $fee = BuyerCashFee::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'charged_date' => '2026-09-10', 'amount' => 6,
        ]);
        app(BuyerCashService::class)->chargeFee($fee);

        $rows = Volt::actingAs($this->finance())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->get('cashReceiptList');

        $use = $rows[0]['uses'][0];
        $this->assertSame(__('buyer.cash.ledger_fee'), $use['label'], '원장 수수료 줄에 이름이 없다');
        $this->assertSame('2026-09-10', $use['date'], '원장 수수료는 수수료일을 보여줘야 한다');
        $this->assertTrue($use['is_ledger_fee']);
    }

    /**
     * 같은 날 입금이 여러 건이면 수수료 「나간 입금」이 날짜만으론 구분이 안 된다.
     * 실측 R.S.H: 09-09 에 입금 3건, 수수료 3건이 전부 한 건에서 나갔는데 `09/09 6.00` 세 줄로 보였다.
     */
    public function test_fee_source_is_distinguishable_when_two_receipts_share_a_date(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->actingAs($this->finance());
        $this->receipt($buyer, '2026-09-09', 10);      // 먼저 — FIFO 가 여기서 뺀다
        $this->receipt($buyer, '2026-09-09', 5000);
        $fee = BuyerCashFee::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'charged_date' => '2026-09-09', 'amount' => 6,
        ]);
        app(BuyerCashService::class)->chargeFee($fee);

        $fees = Volt::actingAs($this->finance())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->get('cashFeeList');

        $this->assertStringContainsString('10.00', $fees[0]['from'],
            '어느 입금에서 나갔는지 금액으로 구분되지 않는다 — 같은 날 입금이 여럿이면 날짜만으론 못 가린다');
    }

    // ── 정적 가드 ────────────────────────────────────────────────

    /**
     * 🚫 배분 줄의 날짜로 `created_at` 을 쓰면 안 된다 — 배분 행은 재배분마다 새로 생기므로
     *    그 값은 「FIFO 가 마지막으로 돌아간 시각」이다(운영 실측: 09-09 잔금의 행이 09-10 생성).
     *    되돌아가도 화면은 정상 렌더되므로 정적으로 막는다.
     */
    public function test_no_screen_uses_created_at_as_the_allocation_date(): void
    {
        foreach ([
            'resources/views/livewire/erp/buyers/index.blade.php',
            'resources/views/livewire/erp/buyer-account/index.blade.php',
            'app/Services/BuyerAccountService.php',
        ] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression(
                '/\$a->created_at|allocation.{0,20}->created_at/',
                $src,
                $file.' 가 배분 행의 created_at 을 날짜로 쓰고 있다 — 재배분마다 갱신되는 값이다',
            );
        }
    }

    /** 두 화면이 같은 판정을 쓰는가 — 각자 비교식을 적으면 한쪽만 표시된다(SKILLS §8 #44). */
    public function test_both_screens_use_the_model_for_the_backfill_check(): void
    {
        foreach ([
            'resources/views/livewire/erp/buyers/index.blade.php',
            'resources/views/livewire/erp/buyer-account/index.blade.php',
        ] as $file) {
            $this->assertStringContainsString('isBackfillFor(', file_get_contents(base_path($file)),
                $file.' 가 역행 판정을 모델 단일 출처로 하지 않는다');
        }
    }
}
