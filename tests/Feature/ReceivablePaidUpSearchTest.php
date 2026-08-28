<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리에서 **완납 차를 검색하면 「완납」 탭으로 옮겨** 보여준다 (jin 2026-08-28).
 *
 * 🚨 종전엔 탭 pill 에 「완납 1」이 떠 있는데 **목록은 0건**이었다 — 기본 탭
 *    (채권 전체 = 미수 > 0)이 그 차를 떨어뜨리기 때문이다. 실무자에겐 「검색이 안 된다」로
 *    보인다. 숫자는 보이는데 행이 없으니 더 헷갈린다.
 *
 * ⚠️ 사용자가 탭을 **일부러 고른 상태**면 건드리지 않는다. 고른 조건을 검색 한 번에
 *    뺏으면 그게 또 다른 혼란이다.
 */
class ReceivablePaidUpSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** 미수가 남아 있는 차 */
    private function unpaid(string $num, array $attrs = []): Vehicle
    {
        $buyer = Buyer::create(['name' => 'BUYER-'.(++$this->seq), 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => 10_000,
            'sale_date' => now()->subDays(60)->toDateString(),
            'purchase_date' => now()->subMonths(3)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ], $attrs));
    }

    /** 완납 차 — 확정 잔금으로 전액 채운다(캐시 컬럼은 훅이 갱신). */
    private function paidUp(string $num, array $attrs = []): Vehicle
    {
        $v = $this->unpaid($num, $attrs);
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'type' => 'balance',
            'amount' => 10_000,
            'payment_date' => now()->subDays(30)->toDateString(),
            'confirmed_at' => now()->subDays(30),
        ]);
        $v->refresh();
        $this->assertLessThanOrEqual(0, (int) $v->sale_unpaid_amount, '표본이 완납이 아니다.');

        return $v;
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function page()
    {
        return Volt::actingAs($this->admin())->test('erp.receivables.index')
            ->set('dateFrom', '')
            ->set('dateTo', '');
    }

    public function test_searching_a_paid_up_vehicle_moves_to_the_paid_up_tab(): void
    {
        $paid = $this->paidUp('11가1111');

        $this->page()
            ->set('search', '11가1111')
            ->assertSet('classification', 'paid_up')
            ->assertSee($paid->vehicle_number);
    }

    public function test_a_vehicle_with_unpaid_balance_stays_on_the_default_tab(): void
    {
        $this->unpaid('22나2222');

        $this->page()
            ->set('search', '22나2222')
            ->assertSet('classification', '')
            ->assertSee('22나2222');
    }

    /** 사용자가 고른 탭은 검색이 뺏지 않는다. */
    public function test_an_explicitly_chosen_tab_is_never_overridden(): void
    {
        $this->paidUp('33다3333');

        $this->page()
            ->set('classification', 'before_shipping')
            ->set('search', '33다3333')
            ->assertSet('classification', 'before_shipping');
    }

    /**
     * `?search=` 로 **직접 들어오는 경로**도 같이 동작해야 한다.
     * `#[Url]` 하이드레이션은 `updatedSearch()` 를 안 태우므로 `mount()` 에도 같은 판정이 있다.
     */
    public function test_the_url_entry_point_also_jumps(): void
    {
        $paid = $this->paidUp('44라4444');

        Livewire::withQueryParams(['search' => '44라4444']);
        $component = Volt::actingAs($this->admin())->test('erp.receivables.index');
        Livewire::withQueryParams([]);

        $this->assertSame('paid_up', $component->instance()->classification);
        $component->assertSee($paid->vehicle_number);
    }

    /** 차대번호(VIN) 검색 — 미수 차는 채권 전체에서, 완납 차는 완납 탭에서 나와야 한다. */
    public function test_vin_search_works_on_both_sides(): void
    {
        $this->unpaid('55마5555', ['nice_reg_vin' => 'KMHXX00XX0X000111']);
        $paid = $this->paidUp('66바6666', ['nice_reg_vin' => 'JTDBB00BB0B000222']);

        $this->page()
            ->set('search', '000111')
            ->assertSet('classification', '')
            ->assertSee('55마5555');

        $this->page()
            ->set('search', '000222')
            ->assertSet('classification', 'paid_up')
            ->assertSee($paid->vehicle_number);
    }
}
