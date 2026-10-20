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
 * 🚦 차량관리 「선적필요 · 통관필요 · B/L필요」 토글 (jin 2026-10-19 «말소필요처럼»).
 *   그 단계의 완료 조건이 빈 차 — 선적완료(반입지+선적일) · 통관완료(통관 체크+수출신고서) · 거래완료(B/L).
 *   진행상태와 다른 축이라, 칸을 순서와 다르게 채운 차(신고서만 있는 「통관중」)도 선적필요에 잡힌다.
 */
class VehicleStageGapToggleTest extends TestCase
{
    use RefreshDatabase;

    /** 칸을 직접 박아 둔다(저장 게이트·cascade 와 무관하게 「빈 칸」만 본다). */
    private function car(string $no, array $cols, ?string $cache = '판매완료'): Vehicle
    {
        $v = Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export']);
        DB::table('vehicles')->where('id', $v->id)->update(array_merge([
            'sale_price' => 1000, 'progress_status_cache' => $cache,
        ], $cols));

        return $v;
    }

    private function seedCars(): void
    {
        $full = ['bl_loading_location' => 'INCHEON', 'shipping_date' => '2026-10-01', 'is_export_cleared' => true, 'export_declaration_document' => 'd.pdf'];
        $this->car('GAP-NONE', []);                                                                  // 판매만 — 셋 다 빈칸
        $this->car('GAP-DECL-ONLY', ['export_declaration_document' => 'd.pdf'], '통관중');            // 순서와 다르게 채운 차
        $this->car('GAP-SHIP-OK', ['bl_loading_location' => 'INCHEON', 'shipping_date' => '2026-10-01'], '선적완료');
        $this->car('GAP-DATE-ONLY', ['shipping_date' => '2026-10-01']);                                // 선적일만 — 반입지 빈칸
        $this->car('GAP-CHECK-ONLY', ['bl_loading_location' => 'INCHEON', 'shipping_date' => '2026-10-01', 'is_export_cleared' => true], '통관완료');   // 체크만 — 신고서 빈칸
        $this->car('GAP-CLEARED', $full, '통관완료');                                                  // B/L 만 남음
        $this->car('GAP-DONE', $full + ['bl_document' => 'bl.pdf'], '거래완료');                       // 모수 밖
        $this->car('GAP-NOSALE', [] + ['sale_price' => 0], '매입완료');                                // 판매 전 — 모수 밖
        $this->car('GAP-CANCEL', ['cancel_status' => Vehicle::CANCEL_ACTIVE]);                         // 매입취소 — 모수 밖
        $dom = Buyer::create(['name' => '내수', 'is_domestic' => true, 'is_active' => true]);
        $this->car('GAP-DOMESTIC', ['buyer_id' => $dom->id]);                                          // 내수 — 모수 밖
    }

    private function gap(string $stage): array
    {
        return Vehicle::query()->stageGap($stage)->orderBy('vehicle_number')->pluck('vehicle_number')->all();
    }

    public function test_each_toggle_finds_vehicles_missing_that_stage_completion(): void
    {
        $this->seedCars();

        $this->assertSame(['GAP-DATE-ONLY', 'GAP-DECL-ONLY', 'GAP-NONE'], $this->gap('shipping'), '반입지·선적일 중 빈 칸 — 신고서만 있는 「통관중」도 잡는다');
        $this->assertSame(['GAP-CHECK-ONLY', 'GAP-DATE-ONLY', 'GAP-DECL-ONLY', 'GAP-NONE', 'GAP-SHIP-OK'], $this->gap('clearance'), '체크·신고서 중 빈 칸');
        $this->assertSame(['GAP-CHECK-ONLY', 'GAP-CLEARED'], $this->gap('bl'), 'B/L필요 = 통관 체크는 됐는데 B/L 만 없는 차 — 진행 중 전부가 아니다(2026-10-20 신고서 조건 제거, 대시보드 큐와 같은 조건)');
        $this->assertSame([], $this->gap('unknown'));
    }

    public function test_the_vehicle_list_toggle_applies_and_clears(): void
    {
        $this->seedCars();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]));

        $c = Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '')
            ->assertSee(__('vehicle.stage_gap.shipping'))->assertSee(__('vehicle.stage_gap.clearance'))->assertSee(__('vehicle.stage_gap.bl'))
            ->call('toggleTask', 'shipping')
            ->assertSee('GAP-NONE')->assertSee('GAP-DECL-ONLY')->assertDontSee('GAP-SHIP-OK')->assertDontSee('GAP-CLEARED');

        $c->call('toggleTask', 'bl')->assertSee('GAP-CLEARED')->assertSet('taskFilter', 'bl');
        $c->call('toggleTask', 'bl')->assertSet('taskFilter', '');
    }
}
