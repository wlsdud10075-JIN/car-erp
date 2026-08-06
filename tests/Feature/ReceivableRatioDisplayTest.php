<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 KPI 미수율·입금률 % 표시 (jin 2026-08-06).
 *
 * ⚠️ 분모는 SKILLS §13 집계식 — Σ(sale_total_amount × 환율). summary() 가 이미 그렇게 구하므로
 * 그 값을 나누기만 한다. 새 분모(예: sale_price 기준)를 만들면 분자와 모집단이 어긋나 의미가 없어진다.
 * ⚠️ 결제대기(grace)는 base 밖의 별도 모수라 % 를 붙이지 않는다.
 */
class ReceivableRatioDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 채권으로 잡히는 차(선적 후 = 출고일 있음, grace 아님) */
    private function sold(string $num, int $salePrice, int $paid, string $currency = 'KRW', float $rate = 1): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.$num, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => $salePrice,
            'sale_date' => now()->subDays(30)->toDateString(),
            'purchase_date' => now()->subMonths(2)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'warehouse_out_date' => now()->subDays(5)->toDateString(),
        ]);
        if ($paid > 0) {
            $v->finalPayments()->create(['amount' => $paid, 'type' => 'balance', 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    public function test_kpi_shows_unpaid_and_paid_ratio(): void
    {
        $this->actingAs($this->admin());
        $this->sold('11가1111', 10_000_000, 4_000_000);   // 미수 600만 / 판매 1000만 = 60%

        $c = Volt::test('erp.receivables.index');
        $s = $c->instance()->summary();

        $this->assertSame(10_000_000, $s['total_sale_krw']);
        $this->assertSame(6_000_000, $s['total_unpaid_krw']);
        $this->assertSame(60.0, $s['unpaid_ratio_pct']);
        $this->assertSame(40.0, $s['paid_ratio_pct']);

        $c->assertSee('60%');
    }

    /** 분모는 판매가가 아니라 판매총액(부대비용 포함) — §13 위반 회귀 가드. */
    public function test_denominator_is_sale_total_not_sale_price(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold('22가2222', 10_000_000, 0);
        $v->update(['transport_fee' => 2_000_000]);        // 판매총액 = 1200만
        $v->refreshCaches();

        $s = Volt::test('erp.receivables.index')->instance()->summary();

        $this->assertSame(12_000_000, $s['total_sale_krw'], '분모가 판매총액이 아니다');
        $this->assertSame(100.0, $s['unpaid_ratio_pct'], '전액 미수면 100% 여야 한다');
    }

    /** 판매가 없으면 0으로 나누지 않고 null → 화면에 % 를 아예 안 그린다. */
    public function test_no_ratio_when_nothing_sold(): void
    {
        $this->actingAs($this->admin());

        $s = Volt::test('erp.receivables.index')->instance()->summary();

        $this->assertSame(0, $s['total_sale_krw']);
        $this->assertNull($s['unpaid_ratio_pct']);
        $this->assertNull($s['paid_ratio_pct']);
    }

    /**
     * 통화 필터를 걸면 그 통화 원금액끼리의 비율이 된다(jin 2026-08-06 "그대로 보여준다").
     * 원화 기준으로 오해하지 않게 화면에 기준 표기가 함께 나와야 한다.
     */
    public function test_currency_filter_ratio_uses_that_currency_and_labels_basis(): void
    {
        $this->actingAs($this->admin());
        $this->sold('33가3333', 10_000, 2_500, 'USD', 1400);   // USD 미수 7,500 / 10,000 = 75%

        $c = Volt::test('erp.receivables.index')->set('displayCurrency', 'USD');
        $s = $c->instance()->summary();

        $this->assertSame('USD', $s['currency']);
        $this->assertSame(75.0, $s['unpaid_ratio_pct']);
        $c->assertSee('USD 기준');
    }

    /**
     * 통화 선택 줄은 외화가 **1종**이어도 떠야 한다 (jin 2026-08-06 제보).
     * 「전체(₩)」는 원화 환산 합계고 「USD」는 달러 원금액이라 값이 다르다 —
     * 구 조건(외화 2종 이상)에서는 USD 만 쓰는 회사에서 줄 자체가 안 보였다.
     */
    public function test_currency_pills_show_even_with_single_foreign_currency(): void
    {
        $this->actingAs($this->admin());
        $this->sold('44가4444', 10_000, 0, 'USD', 1400);
        $this->sold('55가5555', 5_000_000, 0, 'KRW', 1);

        $c = Volt::test('erp.receivables.index');

        $this->assertSame(['USD'], $c->instance()->currencyOptions(), 'KRW 는 전체(₩)가 겸하므로 pill 목록에서 빠진다');
        $c->assertSee(__('receivable.currency_all'))
            ->assertSee('USD');
    }
}
