<?php

namespace Tests\Feature;

use App\Models\Buyer;
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
            'dhl_request' => false,
        ], $overrides));
    }

    // ── 10단계 카운트 정확성 (큐 17 — 폐기 컨셉 제거 후 11→10) ─────────

    public function test_pipeline_counts_aggregate_by_progress_status_cache(): void
    {
        // 매입중 × 2
        $this->makeVehicle();
        $this->makeVehicle();
        // 매입완료 × 1
        $this->makeVehicle(['purchase_price' => 1000, 'down_payment' => 1000]);
        // 거래완료 × 1 (큐 2.6 v2 — dhl_request + bl_document 둘 다 필요)
        $this->makeVehicle(['dhl_request' => true, 'bl_document' => 'bl.pdf']);

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $component = Volt::test('erp.dashboard');
        $counts = $component->get('pipelineCounts');

        $this->assertSame(2, $counts['매입중'] ?? 0);
        $this->assertSame(1, $counts['매입완료'] ?? 0);
        $this->assertSame(1, $counts['거래완료'] ?? 0);
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
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
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
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
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
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $flow = Volt::test('erp.vehicles.index')->get('progressFlow');
        $this->assertNull($flow);
    }

    public function test_progress_flow_export_channel_done_states(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
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

    // 큐 16 — test_progress_flow_disables_export_only_nodes_for_heyman_channel 삭제
    // (단일 채널화로 채널 disabled 분기 자체 제거)

    public function test_progress_flow_warns_on_unpaid_sale(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'sale_price' => 1000, 'deposit_down_payment' => 500,
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        $this->assertSame('done', $flow[2]['status']);  // 판매 등록 완료
        $this->assertSame('warn', $flow[3]['status']);  // 입금 미완납 → warn
    }

    // ── 큐 6 H13 — reason 키 ───────────────────────────────────────────
    // 큐 17 — test_progress_flow_reason_is_null_for_done_and_disposed 삭제 (폐기 컨셉 제거)

    public function test_progress_flow_reason_explains_warn_and_pending(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        // 매입가 입력 + 미지급 잔존 → 매입 warn
        $v = $this->makeVehicle([
            'purchase_price' => 1000, 'down_payment' => 300,
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $flow = $component->get('progressFlow');

        $this->assertSame('warn', $flow[0]['status']);
        $this->assertStringContainsString('미지급', $flow[0]['reason']);
        $this->assertSame('pending', $flow[2]['status']);
        $this->assertStringContainsString('판매가 미입력', $flow[2]['reason']);
    }

    public function test_progress_flow_clearance_reason_distinguishes_progress_vs_pending(): void
    {
        // 큐 2.6 잔여 통합 — 통관 단계에서 정보 누락 시 명시 안내
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        // 판매 완료 + 통관 정보 0 → 통관 pending + reason에 "수출통관 정보 미입력"
        $v1 = $this->makeVehicle([
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
        ]);
        $flow1 = Volt::test('erp.vehicles.index')->call('openEdit', $v1->id)->get('progressFlow');
        $this->assertSame('pending', $flow1[4]['status']);
        $this->assertStringContainsString('수출통관 정보 미입력', $flow1[4]['reason']);

        // 통관 바이어 + 선적일 입력 (체크박스만) + 문서 0 → progress + reason에 "수출신고서 업로드 필요"
        $buyer = Buyer::create([
            'name' => '테스트 바이어', 'is_active' => true, 'country_id' => null,
        ]);
        $v2 = $this->makeVehicle([
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
            'export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-20',
        ]);
        $flow2 = Volt::test('erp.vehicles.index')->call('openEdit', $v2->id)->get('progressFlow');
        $this->assertSame('progress', $flow2[4]['status']);
        $this->assertStringContainsString('수출신고서 업로드 필요', $flow2[4]['reason']);
    }

    // ── vehicles/index mount() — progressFilter 진입 시 날짜 필터 비움 ───

    public function test_vehicles_index_skips_default_date_when_progress_filter_set(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $component = Volt::test('erp.vehicles.index', ['progressFilter' => '매입중']);

        // progressFilter 진입 시 dateFrom/dateTo는 빈 문자열 유지 (산정 로직과 정합성)
        $this->assertSame('', $component->get('dateFrom'));
        $this->assertSame('', $component->get('dateTo'));
        $this->assertSame('매입중', $component->get('progressFilter'));
    }

    public function test_vehicles_index_applies_default_date_when_no_action_or_progress(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $component = Volt::test('erp.vehicles.index');

        // action·progressFilter 모두 없으면 기본 2개월 필터 적용
        $this->assertNotEmpty($component->get('dateFrom'));
        $this->assertNotEmpty($component->get('dateTo'));
    }

    // ── 큐 6 H14 — 신규 등록 후 next-step 동선 ────────────────────────

    public function test_h14_new_vehicle_save_dispatches_switch_tab_to_first_pending_node(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'NEW-H14-1')
            ->call('save')
            ->assertDispatched('switch-tab', tab: 'purchase')
            ->assertDispatched('notify');
    }

    public function test_h14_edit_save_does_not_dispatch_switch_tab(): void
    {
        // 수정 저장은 close() 흐름 유지 — switch-tab 미발사
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('memo', '수정')
            ->call('save')
            ->assertNotDispatched('switch-tab');
    }

    // 큐 17 — test_progress_flow_disables_all_when_disposed 삭제 (폐기 컨셉 제거)
}
