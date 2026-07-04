<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
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

        $defaults = [
            'vehicle_number' => 'PIPE-'.$this->vehicleCounter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움.
        if (($overrides['sale_price'] ?? 0) > 0) {
            if (! array_key_exists('buyer_id', $overrides)) {
                $defaults['buyer_id'] = Buyer::firstOrCreate(['name' => 'TEST BUYER'], ['is_active' => true])->id;
            }
            if (! array_key_exists('sale_date', $overrides)) {
                $defaults['sale_date'] = '2026-05-01';
            }
        }

        // 큐 22-A-3 (2026-05-20) — vehicles 4컬럼 DROP. override 키가 있으면 confirmed FP 자동 생성.
        $sale4Map = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'fee',
        ];
        $sale4Inserts = [];
        foreach ($sale4Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $sale4Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        // 큐 22-C-E (2026-05-20) — vehicles 2컬럼 DROP. override 키가 있으면 confirmed PBP 자동 생성.
        $purchase2Map = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];
        $purchase2Inserts = [];
        foreach ($purchase2Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $purchase2Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        $v = Vehicle::create(array_merge($defaults, $overrides));

        foreach ($sale4Inserts as $row) {
            $v->finalPayments()->create([
                'amount' => $row['amount'],
                'type' => $row['type'],
                'confirmed_at' => now(),
            ]);
        }
        if (! empty($purchase2Inserts)) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                foreach ($purchase2Inserts as $row) {
                    $v->purchaseBalancePayments()->create([
                        'amount' => $row['amount'],
                        'type' => $row['type'],
                        'payment_date' => now()->subDay()->toDateString(),
                        'confirmed_at' => now(),
                    ]);
                }
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }
        if (! empty($sale4Inserts) || ! empty($purchase2Inserts)) {
            $v->refresh();
        }

        return $v;
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
            ->set('roleView', '수출통관');

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
            ->set('roleView', '수출통관')
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
        // 2026-07-04 — 선적/B/L 분리, DHL 흐름 제외. 순서: 선적 → 통관 → B/L
        $this->assertSame('pending', $flow[4]['status']); // 선적 (bl_loading_location 없음)
        $this->assertSame('done', $flow[5]['status']);    // 통관 (export_declaration_document 있음)
        $this->assertSame('pending', $flow[6]['status']); // B/L (bl_document 없음)
        $this->assertSame('shipping', $flow[4]['key']);
        $this->assertSame('clearance', $flow[5]['key']);
        $this->assertSame('bl', $flow[6]['key']);
        $this->assertNotContains('dhl', array_column($flow, 'key')); // DHL 노드 제거됨
    }

    public function test_progress_flow_splits_shipping_and_bl(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        // 반입지 입력 → 선적 done / B/L 문서 없음 → B/L pending (분리 검증)
        $v = $this->makeVehicle([
            'sales_channel' => 'export',
            'bl_loading_location' => 'INCHEON',
        ]);
        $flow = collect(Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->get('progressFlow'));

        $this->assertSame('done', $flow->firstWhere('key', 'shipping')['status']);
        $this->assertSame('pending', $flow->firstWhere('key', 'bl')['status']);
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
        // 회의확장씬 #1 v4 (2026-05-21) — 통관은 flow[5] (선적 swap)
        $flow1 = Volt::test('erp.vehicles.index')->call('openEdit', $v1->id)->get('progressFlow');
        $this->assertSame('pending', $flow1[5]['status']);
        $this->assertStringContainsString('수출통관 정보 미입력', $flow1[5]['reason']);

        // 통관 바이어 + 선적일 입력 (체크박스만) + 문서 0 → progress + reason에 "수출신고서 업로드 필요"
        $buyer = Buyer::create([
            'name' => '테스트 바이어', 'is_active' => true, 'country_id' => null,
        ]);
        $v2 = $this->makeVehicle([
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
            'export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-20',
        ]);
        $flow2 = Volt::test('erp.vehicles.index')->call('openEdit', $v2->id)->get('progressFlow');
        $this->assertSame('progress', $flow2[5]['status']);
        $this->assertStringContainsString('수출신고서 업로드 필요', $flow2[5]['reason']);
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
