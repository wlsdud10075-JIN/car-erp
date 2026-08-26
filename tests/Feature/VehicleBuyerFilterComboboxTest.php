<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량목록 바이어 필터 — select → combobox (jin 2026-08-26).
 *
 * 바이어가 많아 스크롤로는 못 찾는다는 제보. 재고관리가 쓰던 `x-erp.combobox` 를 그대로 붙였다.
 *
 * 🔑 combobox 는 `<select>` 와 달리 **`$wire.set()`** 으로 값을 넣는다. 그래도 Livewire
 *    `updated*` 훅은 똑같이 탄다 — 그 훅이 없으면 **페이지가 안 돌아가** 3페이지에서 바이어를
 *    바꿨을 때 빈 목록이 뜬다. 전환 전에는 `updatedBuyerId` 가 **아예 없었다**.
 */
class VehicleBuyerFilterComboboxTest extends TestCase
{
    use RefreshDatabase;

    private Salesman $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = Salesman::create(['name' => '영업', 'type' => 'employee', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    private function buyer(string $name): Buyer
    {
        return Buyer::create(['name' => $name, 'is_active' => true, 'salesman_id' => $this->sm->id]);
    }

    private function cars(Buyer $b, int $n, string $prefix): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Vehicle::create([
                'vehicle_number' => $prefix.sprintf('가%04d', 1000 + $i),
                'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
                'dhl_request' => false, 'salesman_id' => $this->sm->id, 'buyer_id' => $b->id,
                'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
            ]);
        }
    }

    /** 🚨 이게 전환의 핵심 — 훅이 없으면 목록이 조용히 빈다. */
    public function test_changing_buyer_resets_pagination(): void
    {
        $a = $this->buyer('ALPHA TRADING');
        $b = $this->buyer('BRAVO MOTORS');
        $this->cars($a, 25, '11');   // 여러 페이지
        $this->cars($b, 2, '22');    // 1페이지에도 안 참

        $page = Volt::test('erp.vehicles.index')->set('perPage', 10)->call('gotoPage', 3);
        $page->assertSeeText('11가1021');   // 3페이지에 A 의 차가 보이는 상태

        $page->set('buyerId', (string) $b->id);

        $page->assertSeeText('총 2대');
        // 1페이지로 안 돌아가면 3페이지엔 아무것도 없어 이 줄이 실패한다.
        $page->assertSeeText('22가1001');
    }

    /** 필터 자체가 도는가 — combobox 가 넣는 값은 **문자열**이다(`$wire.set` 은 문자열을 준다). */
    public function test_string_buyer_id_filters_the_list(): void
    {
        $a = $this->buyer('ALPHA TRADING');
        $b = $this->buyer('BRAVO MOTORS');
        $this->cars($a, 3, '11');
        $this->cars($b, 1, '22');

        $page = Volt::test('erp.vehicles.index')->set('buyerId', (string) $a->id);

        $page->assertSeeText('총 3대');
        $page->assertSeeText('11가1001');
        $page->assertDontSeeText('22가1001');
    }

    /** × 로 비우면 「바이어 전체」로 돌아온다 — combobox 의 clear() 가 빈 문자열을 넣는다. */
    public function test_clearing_returns_to_all_buyers(): void
    {
        $a = $this->buyer('ALPHA TRADING');
        $b = $this->buyer('BRAVO MOTORS');
        $this->cars($a, 3, '11');
        $this->cars($b, 1, '22');

        $page = Volt::test('erp.vehicles.index')->set('buyerId', (string) $a->id)->set('buyerId', '');

        $page->assertSeeText('총 4대');
        $page->assertSeeText('22가1001');
    }

    /**
     * 🔒 화면이 combobox 를 쓰는지 **정적으로** 지킨다.
     *    `<select>` 로 되돌아가도 위 기능 테스트는 전부 통과한다(서버 동작이 같다) —
     *    「검색이 안 된다」는 눈으로만 보이므로 여기서 막는다.
     */
    public function test_the_filter_is_a_searchable_combobox_not_a_plain_select(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringContainsString('<x-erp.combobox wire:key="veh-buyer-', $src);
        $this->assertStringNotContainsString('<select wire:model.live="buyerId"', $src,
            '바이어 필터가 검색 안 되는 select 로 되돌아갔다');
    }
}
