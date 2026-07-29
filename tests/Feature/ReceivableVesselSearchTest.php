<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 검색에 선박명(VSL)·컨테이너번호 추가 (jin 2026-07-29).
 *
 * "그 배에 실린 차들 미수" 처럼 선적 단위로 채권을 묻는 흐름.
 * 차량목록 검색(vehicles/index)과 같은 기준을 맞춘다.
 */
class ReceivableVesselSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function sold(string $num, array $attrs = []): Vehicle
    {
        // ⚠️ 바이어 이름에 차량번호를 넣지 말 것 — 바이어 필터 드롭다운에 그대로 렌더돼
        //    assertDontSee(차량번호) 가 그 <option> 에 걸린다(검색은 멀쩡한데 테스트만 깨짐).
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

    private function page()
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        return Volt::actingAs($admin)->test('erp.receivables.index')
            ->set('dateFrom', '')
            ->set('dateTo', '');
    }

    public function test_search_by_vessel_name_finds_the_vehicle(): void
    {
        $this->sold('11가1111', ['vessel_name' => 'MORNING CAROL']);
        $this->sold('22나2222', ['vessel_name' => 'GRAND MERCURY']);

        $this->page()
            ->set('search', 'MORNING')
            ->assertSee('11가1111')
            ->assertDontSee('22나2222');
    }

    public function test_search_by_container_number_finds_the_vehicle(): void
    {
        $this->sold('33다3333', ['container_number' => 'TCNU1234567']);
        $this->sold('44라4444', ['container_number' => 'MSKU7654321']);

        $this->page()
            ->set('search', 'TCNU1234')
            ->assertSee('33다3333')
            ->assertDontSee('44라4444');
    }

    /** 기존 검색(차량번호·브랜드)이 깨지지 않아야 한다. */
    public function test_existing_plate_and_brand_search_still_works(): void
    {
        $this->sold('55마5555', ['brand' => 'HYUNDAI', 'vessel_name' => 'MORNING CAROL']);
        $this->sold('66바6666', ['brand' => 'KIA', 'vessel_name' => 'MORNING CAROL']);

        $this->page()->set('search', '55마')->assertSee('55마5555')->assertDontSee('66바6666');
        $this->page()->set('search', 'KIA')->assertSee('66바6666')->assertDontSee('55마5555');
    }

    /** 같은 배에 실린 차가 여럿이면 함께 나와야 한다 — 이게 이 기능의 목적이다. */
    public function test_one_vessel_returns_every_vehicle_on_it(): void
    {
        $this->sold('77사7777', ['vessel_name' => 'MORNING CAROL']);
        $this->sold('88아8888', ['vessel_name' => 'MORNING CAROL']);
        $this->sold('99자9999', ['vessel_name' => 'GRAND MERCURY']);

        $this->page()
            ->set('search', 'MORNING CAROL')
            ->assertSee('77사7777')
            ->assertSee('88아8888')
            ->assertDontSee('99자9999');
    }
}
