<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            // 두 방향을 다 건다 — 금액 칸은 ▼ 먼저(2026-11-22), 나머지는 ▲ 먼저. 어느 쪽이든 두 번 누르면 asc·desc 를 다 지난다.
            $first = $c->call('setSort', $key)->assertSet('sortColumn', $key)->assertSee('11가1111')->get('sortDirection');
            $c->call('setSort', $key)->assertSet('sortDirection', $first === 'asc' ? 'desc' : 'asc')->assertSee('22가2222');
        }
    }

    /** 매입총액 정렬 = 매입가 + 매도비 순 (매입가만 보면 순서가 뒤집히는 데이터로 확인). */
    public function test_purchase_total_sorts_by_price_plus_selling_fee(): void
    {
        Vehicle::create(['vehicle_number' => '33가3333', 'sales_channel' => 'export', 'purchase_price' => 1000, 'selling_fee' => 0, 'purchase_date' => '2026-10-01']);
        Vehicle::create(['vehicle_number' => '44가4444', 'sales_channel' => 'export', 'purchase_price' => 900, 'selling_fee' => 500, 'purchase_date' => '2026-10-01']);
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')->call('syncViewport', false)->call('setSort', 'purchase_total');
        // 🔽 2026-11-22 (jin) — 금액 칸은 첫 클릭에 큰 것부터(▼ → ▲ → 빠짐)
        $html = $c->html();
        $this->assertLessThan(strpos($html, '33가3333'), strpos($html, '44가4444'), '첫 클릭 = desc: 1,400 이 1,000 보다 앞');

        $html = $c->call('setSort', 'purchase_total')->html();
        $this->assertLessThan(strpos($html, '44가4444'), strpos($html, '33가3333'), '다시 = asc: 1,000 이 1,400 보다 앞');
    }

    /**
     * 🧭 진행상태 = 업무 순서(글자순이면 ▲ 에서 거래완료가 맨 위) · 담당자 = 이름순(종전엔 등록 번호순) — jin 2026-11-22.
     * 담당자 필터는 콤보(치면서 찾기).
     */
    public function test_progress_sorts_in_workflow_order_and_salesman_by_name(): void
    {
        $late = Salesman::create(['name' => '가나다', 'is_active' => true, 'type' => 'employee']);   // 나중에 등록됐지만 이름은 앞
        $early = Salesman::create(['name' => '하하하', 'is_active' => true, 'type' => 'employee']);
        DB::table('salesmen')->where('id', $late->id)->update(['id' => 900]);
        $rows = ['55가5555' => ['거래완료', $early->id], '66가6666' => ['매입중', 900], '77가7777' => ['선적중', $early->id]];
        foreach ($rows as $no => [$stage, $smId]) {
            $v = Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export', 'purchase_date' => '2026-10-01', 'salesman_id' => $smId]);
            DB::table('vehicles')->where('id', $v->id)->update(['progress_status_cache' => $stage]);
        }
        $this->actingAs($this->admin());
        $order = fn ($c) => collect(array_keys($rows))->sortBy(fn ($no) => strpos($c->html(), $no))->values()->all();

        $c = Volt::test('erp.vehicles.index')->call('syncViewport', false)->call('setSort', 'progress_status_cache');
        $this->assertSame(['66가6666', '77가7777', '55가5555'], $order($c), '▲ = 매입중 → 선적중 → 거래완료');
        $this->assertSame(['55가5555', '77가7777', '66가6666'], $order($c->call('setSort', 'progress_status_cache')));

        $c = Volt::test('erp.vehicles.index')->call('syncViewport', false)->call('setSort', 'salesman_id');
        $this->assertSame('66가6666', $order($c)[0], '담당자 ▲ = 이름 가나다순(번호 900 인 「가나다」가 먼저)');

        $src = file_get_contents(base_path(self::VIEW));
        $this->assertStringContainsString('<x-erp.combobox wire:key="veh-salesman-', $src);
        $this->assertStringNotContainsString('<select wire:model.live="salesmanId"', $src);
    }
}
