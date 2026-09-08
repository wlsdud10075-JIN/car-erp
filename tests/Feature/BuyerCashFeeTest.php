<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 현금 **수수료** — 남은 잔돈을 0 으로 터는 통로 (jin 2026-09-08).
 *
 * 배경: 바이어가 보낸 돈을 쓰다 보면 **한참 뒤에 송금 수수료가 잡혀** 실제 들어온 돈이
 * 기재액보다 적었던 것으로 드러난다. 그러면 영영 안 없어지는 잔돈이 남는다.
 *
 * 🔑 여기서 지키는 것:
 *   ① 수수료는 **입금과 같은 뺄셈**에 들어간다 — `remaining_amount`·`balanceFor`·`availableFor`
 *      가 손대지 않아도 따라온다(뺄셈이 두 곳으로 갈리면 「잔액 0 인데 쓸 수 있다」가 생긴다)
 *   ② 남은 현금보다 크면 **통째로 거절** — 일부만 털고 「됐다」로 보이면 안 된다
 *   ③ 취소하면 **현금이 그대로 돌아온다**
 *   ④ 입금을 지워도 수수료는 **남은 다른 입금에서 다시** 나간다
 */
class BuyerCashFeeTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // 토글 키는 **회사별**이다 — `buyer_cash_enabled` 만 넣으면 안 켜진다.
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        return Buyer::create(['name' => 'FEE BUYER', 'is_active' => true]);
    }

    private function receipt(Buyer $b, float $amount, string $date, string $cur = 'USD'): BuyerCashReceipt
    {
        return BuyerCashReceipt::create([
            'buyer_id' => $b->id, 'currency' => $cur,
            'received_date' => $date, 'amount' => $amount,
        ]);
    }

    private function fee(Buyer $b, float $amount, string $cur = 'USD'): BuyerCashFee
    {
        return BuyerCashFee::create([
            'buyer_id' => $b->id, 'currency' => $cur,
            'charged_date' => '2026-03-01', 'amount' => $amount, 'note' => '중계은행 수수료',
        ]);
    }

    private function vehicle(Buyer $b, float $salePrice, string $cur = 'USD'): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'FE'.++$this->counter.'가2222',
            'sales_channel' => 'export', 'currency' => $cur, 'exchange_rate' => 1300,
            'dhl_request' => false, 'buyer_id' => $b->id,
            'sale_price' => $salePrice, 'sale_date' => '2026-02-01',
        ]);
    }

    /** ① 수수료는 오래된 입금부터(FIFO) 갉아먹고, 잔액이 **저절로** 줄어든다. */
    public function test_fee_eats_the_oldest_receipt_first_and_balance_follows(): void
    {
        $b = $this->buyer();
        $old = $this->receipt($b, 100, '2026-01-01');
        $new = $this->receipt($b, 50, '2026-02-01');

        app(BuyerCashService::class)->chargeFee($this->fee($b, 30));

        $this->assertSame(70.0, round($old->fresh()->remaining_amount, 2), '오래된 입금부터 안 갉아먹었다');
        $this->assertSame(50.0, round($new->fresh()->remaining_amount, 2));
        // 🔑 잔액 계산은 손댄 적이 없다 — 같은 뺄셈에 얹혔기 때문에 따라온다.
        $this->assertSame(120.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
    }

    /** ① 한 수수료가 여러 입금에 걸쳐도 된다. */
    public function test_fee_spans_multiple_receipts(): void
    {
        $b = $this->buyer();
        $r1 = $this->receipt($b, 20, '2026-01-01');
        $r2 = $this->receipt($b, 20, '2026-02-01');

        app(BuyerCashService::class)->chargeFee($this->fee($b, 30));

        $this->assertSame(0.0, round($r1->fresh()->remaining_amount, 2));
        $this->assertSame(10.0, round($r2->fresh()->remaining_amount, 2));
        $this->assertSame(10.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
    }

    /** ① 통화가 다르면 안 건드린다 — USD 수수료가 EUR 현금을 갉으면 안 된다. */
    public function test_fee_never_crosses_currency(): void
    {
        $b = $this->buyer();
        $eur = $this->receipt($b, 100, '2026-01-01', 'EUR');
        $this->receipt($b, 100, '2026-01-01', 'USD');

        app(BuyerCashService::class)->chargeFee($this->fee($b, 30, 'USD'));

        $this->assertSame(100.0, round($eur->fresh()->remaining_amount, 2), 'EUR 현금이 USD 수수료에 갉였다');
        $this->assertSame(100.0, BuyerCashReceipt::balanceFor($b->id, 'EUR'));
        $this->assertSame(70.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
    }

    /**
     * ② 남은 현금보다 크면 **통째로 거절**한다.
     * 🚫 「있는 만큼만 털고 성공」은 안 된다 — 사람이 적은 금액과 원장이 어긋난 채로 끝난다.
     */
    public function test_fee_larger_than_remaining_cash_is_rejected_whole(): void
    {
        $b = $this->buyer();
        $r = $this->receipt($b, 40, '2026-01-01');
        $fee = $this->fee($b, 100);

        try {
            app(BuyerCashService::class)->chargeFee($fee);
            $this->fail('남은 현금보다 큰 수수료가 통과했다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('60', $e->getMessage(), '얼마가 모자란지 안 알려준다');
        }

        // 🚨 「막혔다」로 끝내지 말 것 — 행이 안 남았는지까지 본다(부분 차감이 남으면 조용한 오류다).
        $this->assertSame(0, BuyerCashAllocation::where('fee_id', $fee->id)->count(), '부분 차감이 남았다');
        $this->assertSame(40.0, round($r->fresh()->remaining_amount, 2));
    }

    /** ③ 수수료를 취소하면 현금이 그대로 돌아온다. */
    public function test_cancelling_a_fee_returns_the_cash(): void
    {
        $b = $this->buyer();
        $r = $this->receipt($b, 100, '2026-01-01');
        $fee = $this->fee($b, 30);
        app(BuyerCashService::class)->chargeFee($fee);
        $this->assertSame(70.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));

        $fee->delete();

        $this->assertSame(100.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
        $this->assertSame(100.0, round($r->fresh()->remaining_amount, 2));
        $this->assertSame(0, BuyerCashAllocation::count(), '배분 행이 cascade 로 안 사라졌다');
    }

    /**
     * ① 수수료로 현금을 털면 **판매잔금이 쓸 수 있는 현금도 그만큼 준다**.
     * 이게 「뺄셈이 한 곳」의 실제 증거다 — 게이트 코드를 한 줄도 안 고쳤는데 따라온다.
     */
    public function test_fee_reduces_what_a_sale_balance_can_consume(): void
    {
        $b = $this->buyer();
        $this->receipt($b, 100, '2026-01-01');
        $v = $this->vehicle($b, 1_000);
        $service = app(BuyerCashService::class);

        $this->assertSame(100.0, $service->availableFor($v));

        $service->chargeFee($this->fee($b, 30));

        $this->assertSame(70.0, $service->availableFor($v), '수수료가 게이트 잔액에 반영되지 않았다');
    }

    /**
     * ④ 입금을 지워도 수수료는 **남은 다른 입금에서 다시** 나간다.
     * 안 그러면 수수료 행은 남았는데 현금은 도로 늘어난 상태가 된다 — 화면엔 아무 표시도 안 뜬다.
     */
    public function test_deleting_a_receipt_recharges_the_fee_from_what_is_left(): void
    {
        $this->actingAs($this->finance());
        $b = $this->buyer();
        $first = $this->receipt($b, 50, '2026-01-01');
        $this->receipt($b, 50, '2026-02-01');
        $fee = $this->fee($b, 30);
        app(BuyerCashService::class)->chargeFee($fee);

        $this->assertSame(70.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));

        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('deleteCashReceipt', $first->id);

        // 남은 입금 50 에서 수수료 30 이 다시 나간다 ⇒ 20
        $this->assertSame(20.0, BuyerCashReceipt::balanceFor($b->id, 'USD'), '수수료가 되살아나지 않았다');
        $this->assertSame(30.0, (float) BuyerCashAllocation::where('fee_id', $fee->id)->sum('amount'));
    }

    /** ④-b 남은 현금으로 수수료를 못 덮으면 입금 삭제가 통째로 막힌다. */
    public function test_receipt_deletion_is_blocked_when_the_fee_cannot_be_recharged(): void
    {
        $this->actingAs($this->finance());
        $b = $this->buyer();
        $only = $this->receipt($b, 50, '2026-01-01');
        app(BuyerCashService::class)->chargeFee($this->fee($b, 30));

        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->call('deleteCashReceipt', $only->id);

        $this->assertNotNull($only->fresh(), '수수료를 못 덮는데 입금이 지워졌다');
        $this->assertSame(20.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
    }

    /** 화면 경로 — 재무가 수수료를 넣으면 잔액이 줄고, 모자라면 그 칸에 사유가 뜬다. */
    public function test_screen_charges_and_reports_shortage_on_the_field(): void
    {
        $this->actingAs($this->finance());
        $b = $this->buyer();
        $this->receipt($b, 40, '2026-01-01');

        $c = Volt::test('erp.buyers.index')->call('openEdit', $b->id)
            ->set('fee_currency', 'USD')->set('fee_date', '2026-03-01');

        // 모자란 금액 → 그 칸에 에러, 원장 무변화
        $c->set('fee_amount', '100')->call('addCashFee')->assertHasErrors('fee_amount');
        $this->assertSame(40.0, BuyerCashReceipt::balanceFor($b->id, 'USD'));
        $this->assertSame(0, BuyerCashFee::count(), '거절됐는데 수수료 행이 남았다');

        // 되는 금액
        $c->set('fee_amount', '12.35')->call('addCashFee')->assertHasNoErrors();
        $this->assertSame(27.65, BuyerCashReceipt::balanceFor($b->id, 'USD'));
    }

    /** 판매잔금 배분과 수수료 배분이 **같은 테이블에서 구분**된다. */
    public function test_fee_rows_are_distinguishable_from_payment_rows(): void
    {
        // ⚠️ 판매잔금 배분은 `gated()` 가 `auth()->check()` 를 보므로 로그인 상태여야 돈다.
        $this->actingAs($this->finance());
        $b = $this->buyer();
        $this->receipt($b, 1_000, '2026-01-01');
        $v = $this->vehicle($b, 500);
        $service = app(BuyerCashService::class);

        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 500,
            'payment_date' => '2026-02-05', 'confirmed_at' => now(),
        ]);
        $service->allocate($fp->fresh());
        $service->chargeFee($this->fee($b, 20));

        $rows = BuyerCashAllocation::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->isFee(), '판매잔금 배분이 수수료로 잡혔다');
        $this->assertTrue($rows[1]->isFee(), '수수료 배분이 판매잔금으로 잡혔다');
        $this->assertNull($rows[1]->vehicle_id);
        $this->assertNull($rows[1]->final_payment_id);
    }
}
