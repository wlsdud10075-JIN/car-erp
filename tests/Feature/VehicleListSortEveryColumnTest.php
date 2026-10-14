<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량관리 「모든 컬럼 오름차순·내림차순」 (jin 2026-10-14 «몇몇은 되어있는데 안되는 항목도 있더라»).
 *
 * 헤더에 보이는 표시 컬럼은 전부 정렬 버튼이 있어야 하고, 그 키로 정렬해도 SQL 이 죽지 않아야 한다
 * (DB 컬럼이 아닌 칸은 SORT_EXPRESSIONS 의 식으로 정렬한다 — SQLite·MySQL 공통 문법만 쓴다).
 */
class VehicleListSortEveryColumnTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 컬럼 선택기에 있는 모든 표시 컬럼의 헤더에 정렬 버튼이 있다. */
    public function test_every_toggleable_column_header_has_a_sort_button(): void
    {
        $src = file_get_contents(base_path(self::VIEW));
        preg_match_all("/x-show=\"visible\['([a-z_]+)'\]\">(.*?)<\/th>/", $src, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);

        $without = [];
        foreach ($m as [, $key, $inner]) {
            if (! str_contains($inner, '$sortBtn(')) {
                $without[] = $key;
            }
        }

        $this->assertSame([], $without, '정렬 버튼이 없는 컬럼: '.implode(', ', $without));
    }

    /** 모든 정렬 키로 asc·desc 를 걸어도 목록이 렌더된다(식에 오타가 있으면 여기서 SQL 에러). */
    public function test_sorting_by_every_button_key_renders(): void
    {
        $src = file_get_contents(base_path(self::VIEW));
        preg_match_all("/\\\$sortBtn\('([a-z_]+)'/", $src, $buttons);
        $keys = array_values(array_unique($buttons[1]));
        $this->assertGreaterThan(30, count($keys));

        Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'purchase_price' => 100, 'purchase_date' => '2026-10-01']);
        Vehicle::create(['vehicle_number' => '22가2222', 'sales_channel' => 'export', 'purchase_price' => 200, 'purchase_date' => '2026-10-02']);
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')->call('syncViewport', false);
        foreach ($keys as $key) {
            $c->call('setSort', $key)->assertSet('sortColumn', $key)->assertSet('sortDirection', 'asc')->assertSee('11가1111');
            $c->call('setSort', $key)->assertSet('sortDirection', 'desc')->assertSee('22가2222');
        }
    }

    /** 매입총액 정렬 = 매입가 + 매도비 순 (매입가만 보면 순서가 뒤집히는 데이터로 확인). */
    public function test_purchase_total_sorts_by_price_plus_selling_fee(): void
    {
        Vehicle::create(['vehicle_number' => '33가3333', 'sales_channel' => 'export', 'purchase_price' => 1000, 'selling_fee' => 0, 'purchase_date' => '2026-10-01']);
        Vehicle::create(['vehicle_number' => '44가4444', 'sales_channel' => 'export', 'purchase_price' => 900, 'selling_fee' => 500, 'purchase_date' => '2026-10-01']);
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')->call('syncViewport', false)->call('setSort', 'purchase_total');
        $html = $c->html();
        $this->assertLessThan(strpos($html, '44가4444'), strpos($html, '33가3333'), 'asc: 1,000 이 1,400 보다 앞');

        $html = $c->call('setSort', 'purchase_total')->html();
        $this->assertLessThan(strpos($html, '33가3333'), strpos($html, '44가4444'), 'desc: 1,400 이 1,000 보다 앞');
    }
}
