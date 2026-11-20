<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재고 화면 금액 카드 + 담당자 칩 (jin 2026-11-20 «재고탭에서 총금액 / 인원별 재고 금액 표시 · 칩은 이름만, 금액은 카드로 · 억원으로 줄여서»).
 *
 * 지키는 것: ①금액 = 매입가 합 ②지급 전 차는 재고가 아니라 안 센다 ③칩은 이름만·재고 없는 담당자는 안 그린다·줄바꿈 금지
 *            ④금액은 카드 4장(재고 총금액·지급대기·일반재고·선적전 재고), 억원 단위, 담당자를 고르면 그 사람 금액
 *            ⑤일반재고 = 바이어 미정 또는 판매가 없음(둘 다 있어야 빠진다) ⑥인원당 일반재고 2억을 넘으면 빨갛게.
 */
class InventoryStockAmountStripTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Salesman $sm, string $number, int $price, int $salePrice = 0, bool $paid = true): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $number, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_price' => $price, 'selling_fee' => 0,
            'purchase_date' => now()->subMonth()->toDateString(),
        ] + ($salePrice > 0 ? ['sale_price' => $salePrice, 'sale_date' => now()->subDays(3)->toDateString(),
            'buyer_id' => Buyer::firstOrCreate(['name' => 'STRIP BUYER'], ['is_active' => true])->id] : []));
        if ($paid) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                $v->purchaseBalancePayments()->create(['amount' => $price, 'type' => 'down',
                    'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }

        return $v->fresh();
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    private function eok(string $n): string
    {
        return $n.__('inventory.unit_eok_won');
    }

    public function test_cards_show_totals_and_switch_to_the_picked_salesman(): void
    {
        $this->admin();
        $kim = Salesman::create(['name' => '김재고', 'type' => 'employee', 'is_active' => true]);
        $lee = Salesman::create(['name' => '이재고', 'type' => 'employee', 'is_active' => true]);
        $none = Salesman::create(['name' => '박무재고', 'type' => 'employee', 'is_active' => true]);

        $this->stock($kim, '11가1001', 120_000_000);                       // 일반재고
        $this->stock($kim, '11가1002', 30_000_000, 40_000_000);            // 선적전 재고(바이어 + 판매가)
        $this->stock($lee, '11가1003', 25_000_000);                        // 일반재고
        $this->stock($lee, '11가1004', 99_000_000, 0, false);              // 지급 전 — 재고 아님

        $page = Volt::test('erp.inventory.index');
        $this->assertSame([
            'total' => ['cnt' => 3, 'amount' => 175_000_000],
            'awaiting' => ['cnt' => 1, 'amount' => 99_000_000],   // 지급 전 차 — 재고 총금액엔 안 들어간다
            'general' => ['cnt' => 2, 'amount' => 145_000_000],
            'pre_ship' => ['cnt' => 1, 'amount' => 30_000_000],
            'over_cap_people' => 0,
        ], $page->instance()->stockSummary);
        // 억원 단위로 줄여 적고, 원 단위 전체는 title 에
        $page->assertSeeHtml('data-stock-cards')
            ->assertSee($this->eok('1.75'))->assertSee($this->eok('0.99'))->assertSee($this->eok('1.45'))->assertSee($this->eok('0.3'))
            ->assertSeeHtml('title="175,000,000"');

        // 칩은 이름만 — 금액이 없고, 재고 없는 담당자는 칩이 없다(아래 드롭다운엔 남는다)
        $html = $page->html();
        $from = strpos($html, 'data-stock-by-salesman');
        $chips = substr($html, $from, strpos($html, 'data-stock-cards') - $from);
        $this->assertStringContainsString('김재고', $chips);
        $this->assertStringContainsString('이재고', $chips);
        $this->assertStringNotContainsString('박무재고', $chips);
        $this->assertStringNotContainsString(__('inventory.unit_eok_won'), $chips);

        // 담당자를 고르면 카드가 그 사람 금액으로
        $page->set('salesmanFilter', (string) $kim->id);
        $this->assertSame(['cnt' => 2, 'amount' => 150_000_000], $page->instance()->stockSummary['total']);
        $page->assertSee($this->eok('1.5'))->assertSee($this->eok('1.2'))->assertDontSee($this->eok('1.75'))
            ->assertSee(__('inventory.cap_room', ['amount' => $this->eok('0.8')]));

        // 재고 없는 담당자를 골라도 0 으로 그린다
        $page->set('salesmanFilter', (string) $none->id);
        $this->assertSame(['cnt' => 0, 'amount' => 0], $page->instance()->stockSummary['total']);
    }

    public function test_a_car_leaves_general_stock_only_with_both_a_buyer_and_a_sale_price(): void
    {
        $this->admin();
        $kim = Salesman::create(['name' => '김재고', 'type' => 'employee', 'is_active' => true]);
        $free = $this->stock($kim, '11가2001', 50_000_000);
        $held = $this->stock($kim, '11가2002', 70_000_000);
        // 바이어만 지정하고 판매가는 아직 — 아직 일반재고다 (jin 2026-11-20)
        $held->update(['buyer_id' => Buyer::create(['name' => 'HELD BUYER', 'is_active' => true])->id]);
        $sold = $this->stock($kim, '11가2003', 30_000_000, 40_000_000);   // 바이어 + 판매가 → 선적전 재고

        $this->assertEqualsCanonicalizing([$free->id, $held->id], Vehicle::query()->generalStock()->pluck('id')->all());
        $this->assertSame([$sold->id], Vehicle::query()->preShippingStock()->pluck('id')->all());
        $this->assertTrue($held->fresh()->isGeneralStock());
        $this->assertFalse($sold->isGeneralStock());
        $this->assertSame(120_000_000, Volt::test('erp.inventory.index')->instance()->stockSummary['general']['amount']);

        Volt::test('erp.inventory.index')->call('setCategory', 'general')
            ->assertSee('11가2001')->assertSee('11가2002')->assertDontSee('11가2003');
    }

    public function test_salesman_over_the_general_stock_cap_is_flagged(): void
    {
        $this->admin();
        $kim = Salesman::create(['name' => '김재고', 'type' => 'employee', 'is_active' => true]);
        $this->stock($kim, '11가3001', 150_000_000);
        // 팔린 차(바이어 + 판매가)는 상한에 안 센다 — 여기까지는 1.5억이라 표시 없음
        $this->stock($kim, '11가3002', 90_000_000, 100_000_000);
        Volt::test('erp.inventory.index')->assertDontSeeHtml('data-stock-over-cap');

        $this->stock($kim, '11가3003', 60_000_000);   // 일반재고 합 2.1억
        Volt::test('erp.inventory.index')->assertSeeHtml('data-stock-over-cap')
            ->assertSee(__('inventory.cap_over_people', ['n' => 1]))
            ->set('salesmanFilter', (string) $kim->id)
            ->assertSee(__('inventory.cap_over_by', ['amount' => $this->eok('0.1')]));
    }

    public function test_chips_never_wrap_inside_and_the_row_wraps_instead(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/inventory/index.blade.php'));
        $strip = substr($src, strpos($src, 'data-stock-by-salesman'), 2500);
        $this->assertStringContainsString('flex flex-wrap items-center gap-1.5', $strip);
        $this->assertStringContainsString('shrink-0 whitespace-nowrap rounded-md', $strip);
        $this->assertStringNotContainsString('rounded-full', $strip);
    }
}
