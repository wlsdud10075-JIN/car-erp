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

    /**
     * 선택값은 **문자열**로 저장돼야 한다 (jin 2026-08-05 제보 회귀).
     * 행 체크박스는 wire:model.live + value="{id}" 라 브라우저가 문자열을 담고 JS 비교는 엄격하다.
     * 헤더 토글이 정수를 넣으면 [12] 에 "12" 가 안 걸려 체크 표시가 어긋나고,
     * 사용자가 다시 누르면 같은 차량이 12 와 "12" 두 벌로 쌓여 선택 수가 부푼다.
     */
    public function test_selection_is_stored_as_strings(): void
    {
        $this->actingAs($this->admin());
        $this->vehicle('88가8888');

        $c = Volt::test('erp.vehicles.index')->call('toggleAllShipDocs');

        $this->assertNotSame([], $c->get('shipDocIds'));
        foreach ($c->get('shipDocIds') as $id) {
            $this->assertIsString($id, '정수로 저장되면 행 체크박스가 안 눌린 것처럼 보인다');
        }
    }

    /** 개별 체크(문자열) ↔ 헤더 토글을 섞어도 같은 차량이 두 벌로 쌓이지 않는다. */
    public function test_no_duplicate_when_row_checkbox_and_header_toggle_mix(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle('99가9999');

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $v->id])   // 사용자가 행에서 직접 체크한 상태
            ->call('toggleAllShipDocs');             // 이미 전부 선택 → 해제로 동작

        $this->assertSame([], $c->get('shipDocIds'));

        $c->call('toggleAllShipDocs');               // 다시 전체선택
        $this->assertCount(1, $c->get('shipDocIds'), '같은 차량이 두 벌로 쌓였다');
    }

    /** 칩의 X(누적 제거)도 남은 선택을 문자열로 유지해야 한다 — 같은 타입 혼재 버그. */
    public function test_remove_from_accumulation_keeps_string_type(): void
    {
        $this->actingAs($this->admin());
        $a = $this->vehicle('12가1212');
        $b = $this->vehicle('34가3434');

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id, (string) $b->id])
            ->call('removeFromAccumulation', $a->id);

        $this->assertSame([(string) $b->id], $c->get('shipDocIds'));
    }

    /** 「선택 해제」 후에는 헤더가 다시 "추가" 로 동작해야 한다(해제로 동작하면 눌러도 아무 일이 없다). */
    public function test_clear_selection_then_select_all_adds_again(): void
    {
        $this->actingAs($this->admin());
        $this->vehicle('10가1010');

        $c = Volt::test('erp.vehicles.index')->call('toggleAllShipDocs');
        $this->assertCount(1, $c->get('shipDocIds'));

        $c->call('clearShipDocSelection');
        $this->assertSame([], $c->get('shipDocIds'));

        $c->call('toggleAllShipDocs');
        $this->assertCount(1, $c->get('shipDocIds'), '선택 해제 뒤 전체선택이 다시 동작하지 않는다');
    }

    /**
     * 🔒 morph 가드 (jin 2026-08-05 제보) — 체크박스 checked 는 DOM property 라
     * Livewire morph 가 attribute 만 바꾸면 화면에 반영되지 않는다. 반복 행에 wire:key 가 없으면
     * morph 가 행을 순서로만 매칭해 "선택 해제했는데 체크가 그대로 남는" 현상이 난다.
     * **이 부류는 기능 테스트로 원리상 못 잡는다**(서버 상태는 정상이고 화면만 어긋남) → 정적 검사.
     */
    public function test_list_rows_and_header_checkbox_have_wire_keys(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringContainsString('wire:key="vrow-', $blade, '표 행에 wire:key 가 없다');
        $this->assertStringContainsString('wire:key="vcard-', $blade, '모바일 카드에 wire:key 가 없다');
        $this->assertStringContainsString('wire:key="shipcb-all-', $blade, '헤더 전체선택 체크박스에 상태 wire:key 가 없다');
    }
}
