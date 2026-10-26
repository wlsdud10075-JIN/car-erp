<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 헤더 정렬 (jin 2026-10-26 «채권관리탭에도 하는 김에») — `StackedHeaderSort`.
 * 기본(아무 칸도 안 누름)은 종전대로 미수 큰 순 / `?sort=age` 오래된 순. 헤더를 누르면 그 칸부터 겹침 정렬.
 */
class ReceivableSortTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    private function sold(array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'RCV-'.++$this->counter, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-01-01', 'purchase_price' => 100,
            'sale_date' => '2026-01-10', 'sale_price' => 1_000_000,
        ], $extra));
        $v->refreshCaches();

        return $v->refresh();
    }

    private function ids($component): array
    {
        return $component->instance()->vehicles->pluck('id')->values()->all();
    }

    public function test_default_is_unpaid_desc_and_header_click_sorts_by_that_column(): void
    {
        $small = $this->sold(['sale_price' => 100]);
        $big = $this->sold(['sale_price' => 900]);
        $this->sold(['sale_price' => 500, 'vehicle_number' => 'AAA-0']);

        $c = Volt::test('erp.receivables.index');
        $this->assertTrue($c->instance()->isDefaultSort());
        $this->assertSame($big->id, $this->ids($c)[0], '기본 = 미수 큰 순');

        $c->call('clickSort', 'vehicle_no');
        $this->assertSame(['vehicle_no', 'asc'], [$c->get('sortColumn'), $c->get('sortDirection')]);
        $this->assertSame('AAA-0', Vehicle::find($this->ids($c)[0])->vehicle_number, '차량번호 오름차순');

        $c->call('clickSort', 'vehicle_no');
        $this->assertSame($big->id, $this->ids($c)[0], '▼ = RCV-2 가 맨 앞');

        $c->call('clickSort', 'vehicle_no');
        $this->assertTrue($c->instance()->isDefaultSort(), '세 번째 클릭 = 기본(미수순)으로');
        $this->assertSame($big->id, $this->ids($c)[0]);
        $this->assertNotSame($small->id, $this->ids($c)[0]);
    }

    public function test_stacking_and_buyer_name_sort(): void
    {
        $zed = Buyer::create(['name' => 'ZED', 'is_active' => true]);
        $ann = Buyer::create(['name' => 'ANN', 'is_active' => true]);
        $z1 = $this->sold(['buyer_id' => $zed->id, 'sale_price' => 300]);
        $z2 = $this->sold(['buyer_id' => $zed->id, 'sale_price' => 100]);
        $a1 = $this->sold(['buyer_id' => $ann->id, 'sale_price' => 200]);

        $c = Volt::test('erp.receivables.index')->call('clickSort', 'buyer')->call('clickSort', 'unpaid');
        $this->assertSame([$a1->id, $z2->id, $z1->id], $this->ids($c), '바이어 이름순, 같은 바이어 안에서 미수 오름차순');
        $this->assertStringContainsString('2▲', $c->html());
    }

    public function test_legacy_sort_age_link_still_means_oldest_first_and_unknown_key_is_ignored(): void
    {
        $old = $this->sold(['sale_date' => '2025-01-01']);
        $new = $this->sold(['sale_date' => '2026-03-01']);

        $c = Volt::test('erp.receivables.index', ['sortKey' => 'age']);
        $this->assertSame([$old->id, $new->id], $this->ids($c), '?sort=age = 오래된 순 그대로');

        $c->call('clickSort', 'age');   // 경과일 ▲ = 경과일 적은 순(최신 판매가 앞)
        $this->assertSame([$new->id, $old->id], $this->ids($c));

        $c->call('clickSort', 'not_a_key');
        $this->assertSame('age', $c->get('sortColumn'));
        $this->assertSame([$new->id, $old->id], $this->ids($c));
    }
}
