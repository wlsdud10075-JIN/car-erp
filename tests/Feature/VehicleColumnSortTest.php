<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #10 Phase 2-3 (2026-05-23) — 차량 목록 정렬 검증.
 *
 * 컬럼 토글은 client-side(localStorage)라 phpunit 검증 X — Dusk 별건.
 * 정렬은 server-side(Livewire property → orderBy)라 phpunit 검증 가능.
 */
class VehicleColumnSortTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $component = Volt::test('erp.vehicles.index');
        $this->assertSame('created_at', $component->get('sortColumn'));
        $this->assertSame('desc', $component->get('sortDirection'));
    }

    public function test_set_sort_changes_column_and_resets_to_asc(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('setSort', 'sale_price')
            ->assertSet('sortColumn', 'sale_price')
            ->assertSet('sortDirection', 'asc');
    }

    public function test_set_sort_same_column_toggles_direction(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('setSort', 'sale_price')
            ->call('setSort', 'sale_price')   // 같은 col → toggle
            ->assertSet('sortDirection', 'desc')
            ->call('setSort', 'sale_price')
            ->assertSet('sortDirection', 'asc');
    }

    public function test_set_sort_blocks_invalid_column(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // accessor 컬럼(unpaid_amount) 또는 SQL injection 시도 → 무시
        Volt::test('erp.vehicles.index')
            ->call('setSort', 'unpaid_amount')
            ->assertSet('sortColumn', 'created_at')   // 변경 없음
            ->call('setSort', 'DROP TABLE vehicles;')
            ->assertSet('sortColumn', 'created_at');
    }

    public function test_sort_actually_applies_to_query(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Vehicle::create([
            'vehicle_number' => 'SORT-A-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'sale_price' => 1000,
            'sale_date' => '2026-05-01', 'purchase_date' => '2026-04-01',
        ]);
        Vehicle::create([
            'vehicle_number' => 'SORT-B-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'sale_price' => 5000,
            'sale_date' => '2026-05-01', 'purchase_date' => '2026-04-01',
        ]);

        $asc = Volt::test('erp.vehicles.index')
            ->set('dateFrom', now()->subYear()->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('setSort', 'sale_price')   // asc
            ->instance()->vehicles;
        $this->assertSame(1000, (int) $asc->first()->sale_price);

        $desc = Volt::test('erp.vehicles.index')
            ->set('dateFrom', now()->subYear()->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('setSort', 'sale_price')
            ->call('setSort', 'sale_price')   // desc
            ->instance()->vehicles;
        $this->assertSame(5000, (int) $desc->first()->sale_price);
    }
}
