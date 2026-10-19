<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔢 차량관리 겹친 정렬 (jin 2026-10-19 «개별도 되지만 중복 적용도 되게»).
 *   쓰는 장면: 말소완료 → 담당자 ▲ → 수출신고번호 ▲ = 담당자별로 신고번호 안 넣은 차가 위로.
 *   일반 클릭 = 종전 단일 정렬(겹친 것 비움) · Shift+클릭 또는 「정렬 겹치기」 ON = 이어 붙이기.
 */
class VehicleStackedSortTest extends TestCase
{
    use RefreshDatabase;

    private function seedVehicles(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]));
        $a = Salesman::create(['name' => 'A담당', 'is_active' => true]);
        $b = Salesman::create(['name' => 'B담당', 'is_active' => true]);
        foreach ([
            ['SS-B2', $b, 'E200'], ['SS-A2', $a, 'E200'], ['SS-B1', $b, null], ['SS-A1', $a, null], ['SS-A3', $a, 'E300'],
        ] as [$no, $sm, $decl]) {
            Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'export_declaration_number' => $decl]);
        }
    }

    private function order(string $html): array
    {
        $pos = [];
        foreach (['SS-A1', 'SS-A2', 'SS-A3', 'SS-B1', 'SS-B2'] as $no) {
            $pos[$no] = strpos($html, $no);
        }
        asort($pos);

        return array_keys($pos);
    }

    private function list()
    {
        return Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '');
    }

    public function test_shift_click_stacks_a_second_key_inside_the_first(): void
    {
        $this->seedVehicles();

        $c = $this->list()->call('clickSort', 'salesman_id')->call('clickSort', 'export_declaration_number', true);

        $this->assertSame([['col' => 'export_declaration_number', 'dir' => 'asc']], $c->get('sortExtra'));
        $this->assertSame(['SS-A1', 'SS-A2', 'SS-A3', 'SS-B1', 'SS-B2'], $this->order($c->html()), '담당자 안에서 신고번호 빈 차가 먼저');
        $this->assertStringContainsString('2▲', $c->html(), '겹친 키는 순번이 붙는다');
    }

    public function test_stack_mode_does_the_same_without_shift_and_cycles_off(): void
    {
        $this->seedVehicles();

        $c = $this->list()->call('setSort', 'salesman_id')->set('sortStack', true)
            ->call('clickSort', 'export_declaration_number')
            ->call('clickSort', 'export_declaration_number');   // ▲ → ▼
        $this->assertSame([['col' => 'export_declaration_number', 'dir' => 'desc']], $c->get('sortExtra'));
        $this->assertSame(['SS-A3', 'SS-A2', 'SS-A1', 'SS-B2', 'SS-B1'], $this->order($c->html()));

        $c->call('clickSort', 'export_declaration_number');      // ▼ → 빠짐
        $this->assertSame([], $c->get('sortExtra'));
    }

    public function test_a_plain_click_goes_back_to_a_single_sort(): void
    {
        $this->seedVehicles();

        $c = $this->list()->call('clickSort', 'salesman_id')->call('clickSort', 'export_declaration_number', true)
            ->call('clickSort', 'vehicle_number');
        $this->assertSame([], $c->get('sortExtra'), '일반 클릭 = 개별 정렬');
        $this->assertSame('vehicle_number', $c->get('sortColumn'));
    }

    public function test_tampered_stack_keys_are_ignored(): void
    {
        $this->seedVehicles();

        $this->list()->set('sortExtra', [['col' => 'DROP TABLE vehicles;', 'dir' => 'asc'], ['col' => 'salesman_id', 'dir' => 'sideways']])
            ->assertOk();
        $this->assertSame(5, Vehicle::count());
    }
}
