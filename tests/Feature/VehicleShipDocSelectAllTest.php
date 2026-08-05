<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량관리 표 헤더 전체선택 체크박스 (jin 2026-08-05).
 *
 * 대상 = **이 페이지의 export 차량**(행 체크박스와 같은 기준). 필터 전체가 아니다 —
 * shipDocIds 는 검색을 바꿔도 유지되는 누적 셋이라 수백 건을 담으면 되돌리기 어렵고,
 * 선적 서류는 어차피 30대 상한이다. 다른 페이지에서 고른 선택은 건드리지 않는다.
 */
class VehicleShipDocSelectAllTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicle(string $number): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ]);
    }

    public function test_toggles_all_export_vehicles_on_page(): void
    {
        $this->actingAs($this->admin());
        $ids = collect(['11가1111', '22가2222', '33가3333'])
            ->map(fn ($n) => $this->vehicle($n)->id)->all();

        $c = Volt::test('erp.vehicles.index')->call('toggleAllShipDocs');

        $selected = array_map('intval', $c->get('shipDocIds'));
        sort($selected);
        sort($ids);
        $this->assertSame($ids, $selected, '페이지의 export 차량이 전부 선택돼야 한다');

        // 다시 누르면 해제 (같은 버튼이 토글)
        $c->call('toggleAllShipDocs');
        $this->assertSame([], array_map('intval', $c->get('shipDocIds')));
    }

    /**
     * 이 페이지 밖에서 고른 차량은 페이지를 해제해도 남아야 한다(누적 셋 보호).
     * 목록에 안 뜨는 id 를 미리 담아 "다른 페이지/다른 검색에서 고른 것"을 표현한다.
     */
    public function test_keeps_selection_made_outside_current_page(): void
    {
        $this->actingAs($this->admin());
        $onPage = $this->vehicle('44가4444');
        $outside = 999999;

        $c = Volt::test('erp.vehicles.index')->set('shipDocIds', [$outside]);

        $c->call('toggleAllShipDocs');   // 페이지 차량 추가
        $after = array_map('intval', $c->get('shipDocIds'));
        $this->assertContains($onPage->id, $after);
        $this->assertContains($outside, $after);

        $c->call('toggleAllShipDocs');   // 페이지 차량만 해제
        $remaining = array_map('intval', $c->get('shipDocIds'));
        $this->assertSame([$outside], $remaining, '페이지 밖 선택이 같이 지워졌다');
    }

    /** 「건수만」(perPage=0) 은 행을 안 그리므로 전체선택도 아무 일도 하지 않는다. */
    public function test_count_only_mode_is_a_noop(): void
    {
        $this->actingAs($this->admin());
        $this->vehicle('66가6666');

        $c = Volt::test('erp.vehicles.index')
            ->set('perPage', 0)
            ->call('toggleAllShipDocs');

        $this->assertSame([], array_map('intval', $c->get('shipDocIds')));
    }
}
