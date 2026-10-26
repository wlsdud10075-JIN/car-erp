<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재고관리 헤더 정렬 (jin 2026-10-26 «차량관리에 적용된 내용대로») — `StackedHeaderSort`.
 * 기본 정렬에서 첫 클릭 = 그 칸 ▲, 같은 칸 다시 = ▼ → 빠짐(기본으로), 다른 칸 = 뒤에 겹침. 빈 값은 뒤로. 허용 밖 칸은 무시.
 */
class InventorySortTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    private function stock(array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'SRT-'.++$this->counter, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ], $extra));
        $v->purchaseBalancePayments()->create(['amount' => 1_000_000, 'payment_date' => '2026-04-10', 'confirmed_at' => now()]);
        $v->refreshCaches();

        return $v->refresh();
    }

    private function ids($component): array
    {
        return $component->instance()->inventoryVehicles->pluck('id')->values()->all();
    }

    public function test_click_cycles_asc_desc_back_to_default_and_empty_values_go_last(): void
    {
        $late = $this->stock(['sale_price' => 100, 'sale_date' => '2026-06-01']);
        $early = $this->stock(['sale_price' => 100, 'sale_date' => '2026-05-01']);
        $none = $this->stock();

        $c = Volt::test('erp.inventory.index')->call('clickSort', 'sale_date');
        $this->assertSame(['sale_date', 'asc'], [$c->get('sortColumn'), $c->get('sortDirection')]);
        $this->assertSame([$early->id, $late->id, $none->id], $this->ids($c), '오름차순, 빈 값은 맨 뒤');

        $c->call('clickSort', 'sale_date');
        $this->assertSame('desc', $c->get('sortDirection'));
        $this->assertSame([$late->id, $early->id, $none->id], $this->ids($c), '내림차순이어도 빈 값은 맨 뒤');

        $c->call('clickSort', 'sale_date');
        $this->assertSame('', $c->get('sortColumn'), '세 번째 클릭 = 기본 정렬로');
        $this->assertSame('', $c->instance()->sortIndicator('sale_date'));
    }

    public function test_second_column_stacks_after_the_first_with_numbered_indicator(): void
    {
        $s = Salesman::create(['name' => '김철수', 'type' => 'freelance']);
        $a = $this->stock(['salesman_id' => $s->id, 'purchase_price' => 300]);
        $b = $this->stock(['salesman_id' => $s->id, 'purchase_price' => 100]);
        $c0 = $this->stock(['purchase_price' => 200]);   // 담당자 없음 → 담당자 정렬에서 맨 뒤

        $c = Volt::test('erp.inventory.index')->call('clickSort', 'salesman')->call('clickSort', 'purchase_price');
        $this->assertSame('salesman', $c->get('sortColumn'));
        $this->assertSame([['col' => 'purchase_price', 'dir' => 'asc']], $c->get('sortExtra'));
        $this->assertSame([$b->id, $a->id, $c0->id], $this->ids($c), '담당자 같은 묶음 안에서 매입가 오름차순');

        $html = $c->html();
        $this->assertStringContainsString('1▲', $html);
        $this->assertStringContainsString('2▲', $html);

        $c->call('clickSort', 'purchase_price');   // 겹친 칸 ▲ → ▼
        $this->assertSame([$a->id, $b->id, $c0->id], $this->ids($c));
    }

    public function test_relation_columns_sort_by_name(): void
    {
        $bravo = Buyer::create(['name' => 'BRAVO', 'is_active' => true]);
        $alpha = Buyer::create(['name' => 'ALPHA', 'is_active' => true]);
        $vb = $this->stock(['sale_price' => 100, 'buyer_id' => $bravo->id]);
        $va = $this->stock(['sale_price' => 100, 'buyer_id' => $alpha->id]);
        $vn = $this->stock();

        $c = Volt::test('erp.inventory.index')->call('clickSort', 'buyer');
        $this->assertSame([$va->id, $vb->id, $vn->id], $this->ids($c));
    }

    public function test_unknown_column_is_ignored(): void
    {
        $this->stock();
        $c = Volt::test('erp.inventory.index')->call('clickSort', 'id) DESC; DROP TABLE vehicles; --');
        $this->assertSame('', $c->get('sortColumn'));

        $c = Volt::test('erp.inventory.index')->set('sortColumn', 'not_a_column')->set('sortDirection', 'asc');
        $this->assertCount(1, $this->ids($c), '허용 밖 칸은 기본 정렬로 그냥 그려진다');
    }
}
