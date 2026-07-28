<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재고 보관 위치 (jin 2026-07-28) — 홈플·화물·야드 버튼 + 비고, 담당자별 위치 필터.
 * 저장은 출고일과 같은 「적용」 한 번으로 (즉시저장 아님 — 오클릭 방지 원칙 유지).
 */
class InventoryStockLocationTest extends TestCase
{
    use RefreshDatabase;

    /** 재고에 잡히려면 매입완납 + 출고일 없음 (scopeInStock). */
    private function stockVehicle(string $number): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $number, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000, 'selling_fee' => 0,
            'purchase_date' => now()->subMonth()->toDateString(),
        ]);
        // 매입 완납(confirmed PBP) 이라야 scopeInStock 에 잡힌다.
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $v->purchaseBalancePayments()->create([
                'amount' => 5_000_000, 'type' => 'down',
                'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now(),
            ]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
        $v->refresh();

        return $v;
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    public function test_location_button_stays_draft_until_applied(): void
    {
        $this->admin();
        $v = $this->stockVehicle('11가1001');

        $page = Volt::test('erp.inventory.index')->call('setLocation', $v->id, '야드');

        $this->assertNull($v->fresh()->stock_location);   // 클릭만으론 저장 안 됨

        $page->call('applyWarehouseOut');
        $this->assertSame('야드', $v->fresh()->stock_location);
    }

    public function test_clicking_same_location_clears_it(): void
    {
        $this->admin();
        $v = $this->stockVehicle('11가1001');
        $v->update(['stock_location' => '홈플']);

        Volt::test('erp.inventory.index')
            ->call('setLocation', $v->id, '홈플')   // 같은 값 재클릭 = 해제
            ->call('applyWarehouseOut');

        $this->assertNull($v->fresh()->stock_location);
    }

    public function test_clicking_same_location_clears_it_even_when_draft_not_loaded(): void
    {
        // draft 는 목록 렌더 때 채워진다. 페이지 이동 직후처럼 아직 안 채워진 차량도
        // DB 값 기준으로 해제돼야 한다(렌더 순서에 기대지 않는다).
        $this->admin();
        $v = $this->stockVehicle('11가1001');
        $v->update(['stock_location' => '야드']);

        Volt::test('erp.inventory.index')
            ->set('stockLocation', [])         // draft 비우기 = 아직 안 실린 상태 재현
            ->call('setLocation', $v->id, '야드')
            ->call('applyWarehouseOut');

        $this->assertNull($v->fresh()->stock_location);
    }

    public function test_note_is_saved_with_apply(): void
    {
        $this->admin();
        $v = $this->stockVehicle('11가1001');

        Volt::test('erp.inventory.index')
            ->call('setLocation', $v->id, '화물')
            ->set("stockLocationNote.{$v->id}", 'B동 3열')
            ->call('applyWarehouseOut');

        $v->refresh();
        $this->assertSame('화물', $v->stock_location);
        $this->assertSame('B동 3열', $v->stock_location_note);
    }

    public function test_unknown_location_is_ignored(): void
    {
        $this->admin();
        $v = $this->stockVehicle('11가1001');

        Volt::test('erp.inventory.index')
            ->call('setLocation', $v->id, '해커창고')
            ->call('applyWarehouseOut');

        $this->assertNull($v->fresh()->stock_location);
    }

    public function test_location_filter_narrows_list(): void
    {
        $this->admin();
        $yard = $this->stockVehicle('11가1001');
        $home = $this->stockVehicle('22나2002');
        $yard->update(['stock_location' => '야드']);
        $home->update(['stock_location' => '홈플']);

        Volt::test('erp.inventory.index')
            ->call('toggleLocationFilter', '야드')
            ->assertSeeText('11가1001')
            ->assertDontSeeText('22나2002');
    }

    public function test_unset_filter_shows_only_vehicles_without_location(): void
    {
        $this->admin();
        $placed = $this->stockVehicle('11가1001');
        $this->stockVehicle('22나2002');   // 위치 미지정
        $placed->update(['stock_location' => '야드']);

        Volt::test('erp.inventory.index')
            ->call('toggleLocationFilter', '__none')
            ->assertSeeText('22나2002')
            ->assertDontSeeText('11가1001');
    }

    public function test_column_picker_offers_vehicle_list_columns(): void
    {
        // 차량관리 리스트의 컬럼들을 재고관리에서도 표시컬럼으로 고를 수 있어야 한다(jin 2026-07-28).
        // 토글 자체는 Alpine+localStorage 라, 서버는 컬럼과 선택 UI 를 내려주는 것까지 책임진다.
        $this->admin();
        $this->stockVehicle('11가1001');

        $page = Volt::test('erp.inventory.index');

        $page->assertSeeText('표시 컬럼');
        foreach (['선박명', '컨사이니', '판매일', '수출신고번호', 'B/L 번호', '구입처'] as $label) {
            $page->assertSeeText($label);
        }
    }

    public function test_multiple_locations_can_be_selected_together(): void
    {
        // 홈플+화물 처럼 여러 곳을 동시에 (jin 2026-07-28).
        $this->admin();
        $home = $this->stockVehicle('11가1001');
        $cargo = $this->stockVehicle('22나2002');
        $yard = $this->stockVehicle('33다3003');
        $home->update(['stock_location' => '홈플']);
        $cargo->update(['stock_location' => '화물']);
        $yard->update(['stock_location' => '야드']);

        Volt::test('erp.inventory.index')
            ->call('toggleLocationFilter', '홈플')
            ->call('toggleLocationFilter', '화물')
            ->assertSet('locationFilters', ['홈플', '화물'])
            ->assertSeeText('11가1001')
            ->assertSeeText('22나2002')
            ->assertDontSeeText('33다3003');
    }

    public function test_selected_locations_can_include_unset(): void
    {
        $this->admin();
        $yard = $this->stockVehicle('11가1001');
        $this->stockVehicle('22나2002');   // 미지정
        $cargo = $this->stockVehicle('33다3003');
        $yard->update(['stock_location' => '야드']);
        $cargo->update(['stock_location' => '화물']);

        Volt::test('erp.inventory.index')
            ->call('toggleLocationFilter', '야드')
            ->call('toggleLocationFilter', '__none')
            ->assertSeeText('11가1001')
            ->assertSeeText('22나2002')
            ->assertDontSeeText('33다3003');
    }

    public function test_toggling_same_filter_clears_it(): void
    {
        $this->admin();

        Volt::test('erp.inventory.index')
            ->call('toggleLocationFilter', '야드')
            ->assertSet('locationFilters', ['야드'])
            ->call('toggleLocationFilter', '야드')
            ->assertSet('locationFilters', []);
    }
}
