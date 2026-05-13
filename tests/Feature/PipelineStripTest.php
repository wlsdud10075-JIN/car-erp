<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PipelineStripTest extends TestCase
{
    use RefreshDatabase;

    private int $vehicleCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->vehicleCounter++;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'PIPE-'.$this->vehicleCounter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'is_disposed' => false,
            'dhl_request' => false,
        ], $overrides));
    }

    // ── 11단계 카운트 정확성 ─────────────────────────────────────────

    public function test_pipeline_counts_aggregate_by_progress_status_cache(): void
    {
        // 매입중 × 2
        $this->makeVehicle();
        $this->makeVehicle();
        // 매입완료 × 1
        $this->makeVehicle(['purchase_price' => 1000, 'down_payment' => 1000]);
        // 거래완료 × 1 (큐 2.6 v2 — dhl_request + bl_document 둘 다 필요)
        $this->makeVehicle(['dhl_request' => true, 'bl_document' => 'bl.pdf']);
        // 폐기 × 1
        $this->makeVehicle(['is_disposed' => true]);

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $component = Volt::test('erp.dashboard');
        $counts = $component->get('pipelineCounts');

        $this->assertSame(2, $counts['매입중'] ?? 0);
        $this->assertSame(1, $counts['매입완료'] ?? 0);
        $this->assertSame(1, $counts['거래완료'] ?? 0);
        $this->assertSame(1, $counts['폐기'] ?? 0);
    }

    public function test_pipeline_counts_filter_by_salesman_for_sales_role(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $salesman = Salesman::create(['name' => 'TEST 영업', 'is_active' => true, 'user_id' => $user->id]);
        $this->actingAs($user);

        // 본인 차량 1대 (매입중)
        $this->makeVehicle(['salesman_id' => $salesman->id]);
        // 다른 담당자 차량 1대 (매입중)
        $this->makeVehicle(['salesman_id' => null]);

        $component = Volt::test('erp.dashboard');
        $counts = $component->get('pipelineCounts');

        // 영업 본인 1대만 보여야
        $this->assertSame(1, $counts['매입중'] ?? 0);
    }

    public function test_pipeline_counts_show_all_in_clearance_view(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $salesman = Salesman::create(['name' => 'TEST 영업', 'is_active' => true]);
        $this->actingAs($admin);

        $this->makeVehicle(['salesman_id' => $salesman->id]);
        $this->makeVehicle(['salesman_id' => null]);

        $component = Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', $salesman->id)
            ->set('roleView', '통관');

        // 통관 뷰는 selectedSalesmanId 무시 → 2대 모두
        $counts = $component->get('pipelineCounts');
        $this->assertSame(2, $counts['매입중'] ?? 0);
    }

    // ── pipelineUrl URL 빌더 검증 ────────────────────────────────────

    public function test_pipeline_url_includes_progress_filter_and_salesman(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $salesman = Salesman::create(['name' => 'TEST 영업', 'is_active' => true, 'user_id' => $user->id]);
        $this->actingAs($user);

        $url = Volt::test('erp.dashboard')->instance()->pipelineUrl('매입중');

        $this->assertStringContainsString('progressFilter=', $url);
        $this->assertStringContainsString('%EB%A7%A4%EC%9E%85%EC%A4%91', $url); // 매입중 URL-encoded
        $this->assertStringContainsString('salesmanId='.$salesman->id, $url);
    }

    public function test_pipeline_url_omits_salesman_in_clearance_view(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $url = Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', 5)
            ->set('roleView', '통관')
            ->instance()
            ->pipelineUrl('수출통관중');

        $this->assertStringContainsString('progressFilter=', $url);
        $this->assertStringNotContainsString('salesmanId=', $url);
    }

    // ── 차량 편집 패널 1대 흐름도 ────────────────────────────────────

    public function test_progress_flow_returns_null_for_new_vehicle(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $flow = Volt::test('erp.vehicles.index')->get('progressFlow');
        $this->assertNull($flow);
    }

    public function test_progress_flow_export_channel_done_states(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        // 매입 완료 + 말소 완료 + 판매 + 완납 + 통관 완료
        $v = $this->makeVehicle([
            'sales_channel' => 'export',
            'purchase_price' => 1000, 'down_payment' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        $this->assertIsArray($flow);
        $this->assertCount(7, $flow);
        $this->assertSame('done', $flow[0]['status']); // 매입
        $this->assertSame('done', $flow[1]['status']); // 말소
        $this->assertSame('done', $flow[2]['status']); // 판매
        $this->assertSame('done', $flow[3]['status']); // 입금
        $this->assertSame('done', $flow[4]['status']); // 통관 (export_declaration_document 있음)
        $this->assertSame('pending', $flow[5]['status']); // 선적
        $this->assertSame('pending', $flow[6]['status']); // DHL
    }

    public function test_progress_flow_disables_export_only_nodes_for_heyman_channel(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'sales_channel' => 'heyman',
            'purchase_price' => 1000, 'down_payment' => 1000,
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        $this->assertSame('disabled', $flow[4]['status']); // 통관
        $this->assertSame('disabled', $flow[5]['status']); // 선적
        $this->assertSame('disabled', $flow[6]['status']); // DHL
    }

    public function test_progress_flow_warns_on_unpaid_sale(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'sale_price' => 1000, 'deposit_down_payment' => 500,
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        $this->assertSame('done', $flow[2]['status']);  // 판매 등록 완료
        $this->assertSame('warn', $flow[3]['status']);  // 입금 미완납 → warn
    }

    // ── vehicles/index mount() — progressFilter 진입 시 날짜 필터 비움 ───

    public function test_vehicles_index_skips_default_date_when_progress_filter_set(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $component = Volt::test('erp.vehicles.index', ['progressFilter' => '매입중']);

        // progressFilter 진입 시 dateFrom/dateTo는 빈 문자열 유지 (산정 로직과 정합성)
        $this->assertSame('', $component->get('dateFrom'));
        $this->assertSame('', $component->get('dateTo'));
        $this->assertSame('매입중', $component->get('progressFilter'));
    }

    public function test_vehicles_index_applies_default_date_when_no_action_or_progress(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $component = Volt::test('erp.vehicles.index');

        // action·progressFilter 모두 없으면 기본 2개월 필터 적용
        $this->assertNotEmpty($component->get('dateFrom'));
        $this->assertNotEmpty($component->get('dateTo'));
    }

    public function test_progress_flow_disables_all_when_disposed(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'purchase_price' => 1000, 'down_payment' => 1000,
            'is_disposed' => true,
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        foreach ($flow as $node) {
            $this->assertSame('disabled', $node['status'], "Node {$node['key']} should be disabled when vehicle is disposed");
        }
    }
}
