<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량목록 「건수만」 모드 (jin 2026-07-28) — 선박명 등으로 수백 대를 걸러 엑셀로 받는 흐름.
 * 행을 렌더하지 않고 총건수만 보여준다. 엑셀 내보내기는 기존대로 필터 전체를 내보낸다.
 */
class VehicleListCountOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function vehicles(int $count, string $vessel, string $prefix): void
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        for ($i = 1; $i <= $count; $i++) {
            Vehicle::create([
                'vehicle_number' => $prefix.sprintf('가%04d', 1000 + $i),
                'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
                'vessel_name' => $vessel,
            ]);
        }
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    public function test_count_only_shows_total_but_renders_no_rows(): void
    {
        $this->admin();
        $this->vehicles(5, 'GMT', '11');

        $page = Volt::test('erp.vehicles.index')->set('perPage', 0);

        // 총건수는 그대로 보이고
        $page->assertSeeText('총 5대');
        // 목록 자리에는 안내문만 — 차량번호는 한 대도 렌더되지 않는다
        $page->assertSeeText('목록 표시를 생략했습니다');
        $page->assertDontSeeText('11가1001');
    }

    public function test_count_only_counts_filtered_set_not_everything(): void
    {
        $this->admin();
        $this->vehicles(3, 'GMT', '11');
        $this->vehicles(2, 'OTHER', '22');

        $page = Volt::test('erp.vehicles.index')
            ->set('search', 'GMT')
            ->set('perPage', 0);

        $page->assertSeeText('총 3대');
    }

    public function test_normal_per_page_still_renders_rows(): void
    {
        $this->admin();
        $this->vehicles(2, 'GMT', '11');

        Volt::test('erp.vehicles.index')
            ->set('perPage', 10)
            ->assertSeeText('11가1001');
    }

    public function test_invalid_per_page_falls_back_to_default(): void
    {
        $this->admin();

        Volt::test('erp.vehicles.index')->set('perPage', 7)->assertSet('perPage', 10);
    }

    public function test_invalid_per_page_from_url_is_normalized_at_mount(): void
    {
        // #[Url] 이라 ?perPage=… 로 직접 들어올 수 있다. 0(건수만) 이 의미를 가진 뒤로는
        // 잘못된 값이 빈 목록으로 새면 이유를 알 수 없다.
        $this->admin();

        Volt::test('erp.vehicles.index', ['perPage' => 999])->assertSet('perPage', 10);
    }
}
