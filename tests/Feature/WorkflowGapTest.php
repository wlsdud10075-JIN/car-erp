<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Settlement;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 2.5번 Critical 8건 회귀 테스트.
 * C1 환율 / C2 payment_date / C3 채널 분기 / C4·C5 단계 건너뛰기 /
 * C6 unique+softDelete / C7 본인 격리 / C8 (문서)
 */
class WorkflowGapTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        $defaults = [
            'vehicle_number' => 'WGT-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움 (테스트 헬퍼 선행 PR).
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
            // PBP::creating canConfirmFinance 가드 우회 ($skipCreatingGuard) — 테스트 헬퍼 셋업.
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

    // 큐 16 — test_c3_export_only_stages_skipped_for_heyman_channel 삭제
    // (채널 단일화로 채널별 progress_status 분기 자체 제거)

    public function test_c3_export_channel_still_evaluates_export_stages(): void
    {
        // 큐 2.6 — 수출통관완료는 v2부터 is_export_cleared && export_declaration_document 둘 다 필요.
        // 안건 1 v4 (2026-05-21) — v4 cascade는 이 조합 매칭 없음 → v3 명시 셋업으로 grandfather 검증.
        $v = $this->makeVehicle([
            'sales_channel' => 'export',
            'progress_status_rule_version' => 3,
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'is_export_cleared' => true,
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $this->assertSame('수출통관완료', $v->progress_status);
    }

    // 큐 17 — test_c3_disposed_overrides_all_stages 삭제 (폐기 컨셉 제거)

    // ── C4·C5 — guard 메서드 직접 검증 (UI save() 흐름에서 호출되는 동일 로직) ──

    public function test_c4_blocks_export_entry_without_deregistration(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_deregistered' => false,
            'sale_price' => 1000,
            'export_buyer_id' => 1,
            'shipping_date' => '2026-05-01',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('말소 처리');
        $v->guardStageOrderForExport();
    }

    public function test_c5_blocks_export_entry_when_unpaid_ratio_over_50_percent(): void
    {
        // G 완화 (2026-05-20) — 미수율 > 50% 시만 C5 차단.
        $buyer = Buyer::create(['name' => 'C5_TEST', 'is_active' => true]);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'buyer_id' => $buyer->id,
        ]);
        // 30% 입금 → 미수율 70% → 차단
        $v->finalPayments()->create(['amount' => 300, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $v->refresh();

        $v->export_buyer_id = $buyer->id;
        $v->shipping_date = '2026-05-01';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('입금률 < 50%');
        $v->guardStageOrderForExport();
    }

    public function test_c5_allows_export_entry_when_paid_50_percent_or_more(): void
    {
        // G 완화 (2026-05-20) — 입금률 ≥ 50% 자유 (admin 우회 불필요).
        $buyer = Buyer::create(['name' => 'C5_OK', 'is_active' => true]);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'buyer_id' => $buyer->id,
        ]);
        // 50% 입금 → 미수율 50% → 통과 (≤ 0.5 조건)
        $v->finalPayments()->create(['amount' => 500, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $v->refresh();

        $v->export_buyer_id = $buyer->id;
        $v->shipping_date = '2026-05-01';

        $v->guardStageOrderForExport();   // 통과
        $this->assertTrue(true, '입금률 50% 정확 시 자유 통관');
    }

    public function test_c5_admin_override_bypasses_threshold(): void
    {
        // G 완화 — 입금률 < 50% 라도 admin unpaid_export_override 있으면 우회.
        $buyer = Buyer::create(['name' => 'C5_OVR', 'is_active' => true]);
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'buyer_id' => $buyer->id,
        ]);
        // 10% 입금 → 미수율 90% (정상이면 차단)
        $v->finalPayments()->create(['amount' => 100, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $v->refresh();

        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'clearance',
            'approved_by' => $admin->id,
            'reason' => '바이어 신뢰 — 미수 우회 승인',
            'approved_at' => now(),
        ]);
        $v->load('unpaidExportOverrides');

        $v->export_buyer_id = $buyer->id;
        $v->shipping_date = '2026-05-01';

        $v->guardStageOrderForExport();   // override → 통과
        $this->assertTrue(true, 'admin override 시 입금률 < 50% 라도 자유');
    }

    /**
     * 진입 우회 통합 (2026-07-01 jin) — 통관·선적은 같은 50% 관문이라 하나로 취급.
     * 드롭다운이 진입 우회를 canonical 'shipping' 으로 기록하는데, 반입지 없는 '통관' 상태
     * (코드가 'clearance' 로 판정)에서도 그 하나로 통과해야 한다.
     * (이전엔 stage 불일치 — 'shipping' 승인 ≠ 'clearance' 판정 — 로 차단돼 2회 우회가 필요했음. 서버 실증=145나1447.)
     */
    public function test_c5_entry_override_unified_shipping_covers_clearance_state(): void
    {
        $buyer = Buyer::create(['name' => 'C5_ENTRY', 'is_active' => true]);
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'buyer_id' => $buyer->id,
        ]);
        // 10% 입금 → 미수율 90% (정상이면 차단)
        $v->finalPayments()->create(['amount' => 100, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $v->refresh();

        // 진입 우회 canonical = 'shipping' 만 승인
        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'shipping',
            'approved_by' => $admin->id,
            'reason' => '진입 우회 — 통관(반입지 없음) 상태에서도 통과해야 한다',
            'approved_at' => now(),
        ]);
        $v->load('unpaidExportOverrides');

        // 반입지 없는 통관 진입 (export_buyer_id 만) → 코드 판정은 'clearance' 지만 shipping 진입 우회로 통과
        $v->export_buyer_id = $buyer->id;
        $v->guardStageOrderForExport();   // 통합 후 통과 (이전엔 stage 불일치로 차단)
        $this->assertTrue(true, '진입 우회(shipping)가 통관(clearance) 상태도 커버');
    }

    public function test_c5_blocks_when_foreign_currency_exchange_rate_missing(): void
    {
        // G 완화 — 외화 환율 미입력 → unpaid_ratio = null → 차단 + admin 우회 가능.
        $buyer = Buyer::create(['name' => 'C5_FX', 'is_active' => true]);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'currency' => 'USD',
            'exchange_rate' => 0,   // 환율 미입력 시뮬레이션 (외화 + 0)
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'buyer_id' => $buyer->id,
        ]);

        $v->export_buyer_id = $buyer->id;
        $v->shipping_date = '2026-05-01';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('환율 미입력');
        $v->guardStageOrderForExport();
    }

    public function test_c4_c5_allow_when_prerequisites_met(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'sale_price' => 1000,
            'export_buyer_id' => 1,
            'shipping_date' => '2026-05-01',
        ]);
        $v->setRawAttributes(array_merge($v->getAttributes(), [
            'sale_unpaid_amount_krw_cache' => 0,
        ]));

        // 예외 throw 안 함 (정상 진입)
        $v->guardStageOrderForExport();
        $this->assertTrue(true);
    }

    // 큐 16 — test_c4_c5_skipped_for_non_export_channel 삭제 (단일 채널화)
    // 큐 17 — test_c4_c5_skipped_when_disposed 삭제 (폐기 컨셉 제거)

    public function test_c4_c5_skipped_when_no_export_input(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_deregistered' => false,
            'sale_price' => 1000,
            // export 컬럼 모두 비어있음
        ]);

        $v->guardStageOrderForExport();
        $this->assertTrue(true);
    }

    // ── C7 — 본인 차량 격리 ───────────────────────────────────────────

    public function test_c7_sales_user_can_open_own_vehicle(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $ownSalesman = Salesman::create(['name' => '본인', 'is_active' => true, 'user_id' => $user->id]);
        $myVehicle = $this->makeVehicle(['salesman_id' => $ownSalesman->id]);

        $this->actingAs($user);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $myVehicle->id)
            ->assertSet('editingId', $myVehicle->id);
    }

    public function test_c7_sales_user_cannot_open_other_salesman_vehicle(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        Salesman::create(['name' => '본인', 'is_active' => true, 'user_id' => $user->id]);
        $otherSalesman = Salesman::create(['name' => '타인', 'is_active' => true]);
        $othersVehicle = $this->makeVehicle(['salesman_id' => $otherSalesman->id]);

        $this->actingAs($user);

        // Livewire 3: abort()는 status response로 변환. assertStatus로 검증.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $othersVehicle->id)
            ->assertStatus(403);
    }

    public function test_c7_admin_can_open_any_vehicle(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $otherSalesman = Salesman::create(['name' => '타인', 'is_active' => true]);
        $vehicle = $this->makeVehicle(['salesman_id' => $otherSalesman->id]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->assertSet('editingId', $vehicle->id);
    }

    public function test_c7_clearance_role_can_open_any_vehicle_no_isolation(): void
    {
        // 통관 role은 본인 salesman 개념 없음 — 전체 차량 통관 처리 가능
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $otherSalesman = Salesman::create(['name' => '타인', 'is_active' => true]);
        $vehicle = $this->makeVehicle(['salesman_id' => $otherSalesman->id]);

        $this->actingAs($user);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->assertSet('editingId', $vehicle->id);
    }

    // ── C6 — soft-delete + unique constraint ─────────────────────────

    public function test_c6_allows_same_vehicle_number_after_soft_delete(): void
    {
        $first = $this->makeVehicle(['vehicle_number' => '12가1001']);
        $first->delete(); // soft-delete

        // 동일 차량번호로 신규 등록 — 1062 IntegrityError 발생하지 않아야
        $second = Vehicle::create([
            'vehicle_number' => '12가1001',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);

        $this->assertNotNull($second->id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_c6_blocks_duplicate_via_validation_rule(): void
    {
        // DB-level unique 제거 → application-level Rule::unique whereNull('deleted_at')만 차단.
        // raw Vehicle::create는 검증 우회. vehicles 컴포넌트 save()를 거치면 차단됨.
        // 여기선 활성 차량 + 동일 번호 신규 등록이 Rule::unique로 차단되는지 직접 검증.
        $this->makeVehicle(['vehicle_number' => '34나2002']);

        $rule = Rule::unique('vehicles', 'vehicle_number')->whereNull('deleted_at');
        $validator = Validator::make(
            ['vehicle_number' => '34나2002'],
            ['vehicle_number' => ['required', $rule]]
        );
        $this->assertTrue($validator->fails(), '활성 차량과 동일 번호 신규 등록은 Rule::unique로 차단돼야 함');
    }

    // ── H1 — DHL 발송 체크 시 B/L 첨부 강제 ────────────────────────────

    public function test_h1_blocks_dhl_request_without_bl_document(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'dhl_request' => true,
            // bl_document 비어있음
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 문서 업로드');
        $v->guardAttachmentDeps();
    }

    public function test_h1_allows_dhl_request_with_bl_document(): void
    {
        // 큐 2.6 — H3·H4 캐스케이드 추가됨. 정상 경로는 모든 선행 단계 충족.
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => true,
            'export_declaration_document' => 'edoc.pdf',
            'bl_loading_location' => '부산항',
            'bl_document' => 'bl.pdf',
            'dhl_request' => true,
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);
    }

    // 큐 16 — test_h1_skipped_for_non_export_channel 삭제 (단일 채널화)
    // 큐 17 — test_h1_h2_skipped_when_disposed 삭제 (폐기 컨셉 제거)

    // ── H2 — 수출통관 완료 체크 시 수출신고서 첨부 강제 ────────────────

    public function test_h2_export_cleared_without_doc_no_longer_blocks_at_model(): void
    {
        // 큐 21 후속 (2026-05-18) — H2 강제 차단을 vehicles/index 모달 패턴으로 격하.
        // 모델 레이어 guardAttachmentDeps는 더 이상 차단 안 함. UI save() 흐름에서 모달 confirm.
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => true,
            // export_declaration_document 비어있음
        ]);

        $v->guardAttachmentDeps();   // 예외 없음 — 모델 레이어 통과
        $this->assertTrue(true);
    }

    public function test_h2_allows_export_cleared_with_declaration_document(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => true,
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);
    }

    // ── H7 — soft-delete 후 restore 시 캐시 재계산 ─────────────────────

    public function test_h7_restore_refreshes_progress_cache(): void
    {
        $v = $this->makeVehicle([
            'purchase_price' => 1000,
            'down_payment' => 1000,
        ]);
        $expected = $v->progress_status_cache;

        $v->delete(); // soft-delete

        // 휴면 중 캐시 컬럼을 stale 값으로 변조 (외부 작업으로 stale 가능 시뮬레이션)
        DB::table('vehicles')->where('id', $v->id)->update([
            'progress_status_cache' => 'STALE',
        ]);

        $v->restore();
        $v->refresh();

        $this->assertSame($expected, $v->progress_status_cache);
        $this->assertNotSame('STALE', $v->progress_status_cache);
    }

    public function test_h7_child_delete_refreshes_parent_cache(): void
    {
        // FinalPayment::deleted 핸들러는 기존 코드에 존재. 회귀 보호용 케이스.
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'deposit_down_payment' => 500,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);
        // 큐 20-B — 분자 A안: ledger 반영하려면 confirmed_at SET 필수.
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 500,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(),
        ]);
        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount_krw_cache);

        // 큐 20-D — 확정 잔금은 회계 무결성 lock으로 보호 → 테스트는 flag 우회로 삭제 시뮬.
        FinalPayment::$allowConfirmedMutation = true;
        try {
            $fp->delete();
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }
        $v->refresh();

        // 잔금 삭제 → 미입금 500 다시 발생 → 캐시 재계산
        $this->assertSame(500, (int) $v->sale_unpaid_amount_krw_cache);
    }

    // ── 큐 2.6 — v2 이중 트리거 분류 (4건 누수 차단) ───────────────────

    /**
     * v2 분류 검증용 in-memory Vehicle 빌더 — DB 저장 우회 (FK 회피).
     */
    private function v2Vehicle(array $attrs): Vehicle
    {
        $v = new Vehicle(array_merge([
            'sales_channel' => 'export',
            'dhl_request' => false,
            'is_deregistered' => false,
            'is_export_cleared' => false,
        ], $attrs));
        $v->setRawAttributes(array_merge($v->getAttributes(), [
            'progress_status_rule_version' => $attrs['progress_status_rule_version'] ?? 2,
            'export_buyer_id' => $attrs['export_buyer_id'] ?? null,
            'sale_unpaid_amount_krw_cache' => $attrs['sale_unpaid_amount_krw_cache'] ?? 0,
        ]));

        return $v;
    }

    public function test_q26_v2_blocks_export_cleared_without_checkbox(): void
    {
        // #5 누수 — 사용자 발견 케이스. is_export_cleared 체크 없이 문서만 업로드.
        $v = $this->v2Vehicle([
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'export_buyer_id' => 1, 'shipping_date' => '2026-05-01',
            'is_export_cleared' => false,
            'export_declaration_document' => 'edoc.pdf',
        ]);

        // 체크박스 없으니 수출통관완료 미진입, 수출통관중으로 분류.
        $this->assertSame('수출통관중', $v->progress_status);
    }

    public function test_q26_v2_blocks_shipping_without_export_cleared(): void
    {
        // #4 누수 — 반입지만 있고 통관완료 체크 미설정.
        $v = $this->v2Vehicle([
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'export_buyer_id' => 1, 'shipping_date' => '2026-05-01',
            'is_export_cleared' => false,
            'bl_loading_location' => '부산항',
        ]);

        $this->assertSame('수출통관중', $v->progress_status);
    }

    public function test_q26_v2_blocks_shipping_done_without_loading_location(): void
    {
        // #3 누수 — bl_document만 있고 반입지 미입력.
        $v = $this->v2Vehicle([
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'is_export_cleared' => true, 'export_declaration_document' => 'edoc.pdf',
            'export_buyer_id' => 1, 'shipping_date' => '2026-05-01',
            'bl_document' => 'bl.pdf',
        ]);

        $this->assertSame('수출통관완료', $v->progress_status);
    }

    public function test_q26_v2_blocks_dhl_done_without_bl_document(): void
    {
        // #2 누수 — dhl_request만 있고 bl_document 미입력.
        $v = $this->v2Vehicle([
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'is_export_cleared' => true, 'export_declaration_document' => 'edoc.pdf',
            'bl_loading_location' => '부산항',
            'dhl_request' => true,
        ]);

        $this->assertSame('선적중', $v->progress_status);
    }

    public function test_q26_v2_allows_full_cascade(): void
    {
        // 4건 누수 트리거 모두 충족 시 정상 거래완료까지 진입.
        $v = $this->v2Vehicle([
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'is_export_cleared' => true, 'export_declaration_document' => 'edoc.pdf',
            'export_buyer_id' => 1, 'shipping_date' => '2026-05-01',
            'bl_loading_location' => '부산항', 'bl_document' => 'bl.pdf',
            'dhl_request' => true,
        ]);

        $this->assertSame('거래완료', $v->progress_status);
    }

    public function test_q26_v1_grandfather_preserves_legacy_single_trigger(): void
    {
        // 큐 2.6 이전 row(v1)는 단일 트리거 그대로 평가 — retroactive drift 차단.
        $v = $this->v2Vehicle([
            'progress_status_rule_version' => 1,
            'sale_price' => 1000,
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
            'is_export_cleared' => false, // v2면 차단되지만 v1이라 단일 트리거 적용
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $this->assertSame('수출통관완료', $v->progress_status);
    }

    public function test_q26_h3_cascade_blocks_bl_document_without_loading_location(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => true,
            'export_declaration_document' => 'edoc.pdf',
            'bl_document' => 'bl.pdf',
            // bl_loading_location 비어있음
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('선적 반입지');
        $v->guardAttachmentDeps();
    }

    public function test_q26_h4_cascade_v4_no_longer_blocks_loading_location_without_export_cleared(): void
    {
        // 회의확장씬 #1 v4 (2026-05-21) — 워크플로우 순서: 선적 → 통관 → B/L → 거래완료.
        // 기존 H4 가드(bl_loading_location → is_export_cleared 필요)는 v3 가정 (통관 → 선적) 의 잔재 — v4 에서는 정반대.
        // 사용자 보고 (2026-05-22): H4 + 회의확장씬 #4 컨사이니 가드 도돌이표 → H4 폐기.
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => false,
            'bl_loading_location' => '부산항',
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);   // v4: bl_loading_location 가 통관보다 먼저 가능 — 예외 없음
    }

    // 큐 16 — test_q26_v2_skipped_for_heyman_channel 삭제 (단일 채널화)

    public function test_q26_unpaid_override_allows_export_entry_for_admin(): void
    {
        // admin 승인 시 미입금 잔존이어도 통관 진입 가능.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'deposit_down_payment' => 500, // 미입금 500 남음
            'is_deregistered' => true, 'deregistration_document' => 'dereg.pdf',
        ]);
        $v->refresh();
        $this->assertGreaterThan(0, (int) $v->sale_unpaid_amount_krw_cache);

        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'clearance',
            'approved_by' => $admin->id,
            'reason' => '컨테이너 출항 일정상 강행 — 잔금 확정 입금 확인',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 500,
        ]);
        $v->refresh();

        $v->export_buyer_id = 1;
        $v->shipping_date = '2026-05-01';
        // override 있으니 C5 차단 안 되어야 함
        $v->guardStageOrderForExport();
        $this->assertTrue(true);

        // is_override_active flag 자동 갱신 검증
        $this->assertTrue($v->fresh()->is_override_active);
    }

    public function test_q26_unpaid_override_is_append_only(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $override = UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'clearance',
            'approved_by' => $admin->id,
            'reason' => '20글자 이상의 사유 텍스트 예시 — 검증용',
            'approved_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');
        $override->reason = '수정 시도';
        $override->save();
    }

    public function test_q26_unpaid_override_delete_blocked(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $override = UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'shipping',
            'approved_by' => $admin->id,
            'reason' => '20글자 이상의 사유 텍스트 예시 — 검증용',
            'approved_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('삭제할 수 없습니다');
        $override->delete();
    }

    public function test_q26_unpaid_override_works_through_livewire_save_flow(): void
    {
        // UI save() 흐름이 Vehicle::replicate()로 임시 인스턴스를 만들어 guardStageOrderForExport()
        // 호출 — replicate() 결과는 exists=false·id=null이라 hasUnpaidOverride()가 항상 false였음.
        // admin이 우회 승인을 발급해도 차단되던 회귀. 원본 차량 식별자 복원 fix 검증.
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $buyer = Buyer::create(['name' => 'OVR BUYER', 'is_active' => true]);

        $v = $this->makeVehicle([
            'sale_price' => 1000,
            'deposit_down_payment' => 500,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'nice_reg_owner_rrn' => '950101-1234567',
        ]);
        $v->refresh();
        $this->assertGreaterThan(0, (int) $v->sale_unpaid_amount_krw_cache);

        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'clearance',
            'approved_by' => $admin->id,
            'reason' => '컨테이너 출항 일정상 강행 — 잔금 확정 입금 확인',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 500,
        ]);

        // 통관 진입 시도 (export_buyer + shipping_date) — clearance 단계로 평가됨.
        // 원본 차량 id+exists 복원 fix 적용되었으면 hasUnpaidOverride('clearance')가 true 반환 → 통과.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('export_buyer_id_str', (string) $buyer->id)
            ->set('shipping_date', '2026-05-01')
            ->call('save')
            ->assertHasNoErrors();
    }

    // ── 큐 7 확장 — C7-a 컬럼 권한 + H9 RRN 형식 + H10 말소 RRN 강제 ───

    public function test_q7_c7a_settlement_role_cannot_change_financial_fields(): void
    {
        $settlementUser = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $v = $this->makeVehicle([
            'currency' => 'USD',   // 2026-05-20 — KRW saving 훅 강제 1 회피 (외화 환율 테스트 의도)
            'purchase_price' => 1000000,
            'selling_fee' => 700000,
            'exchange_rate' => 1300,
            'sale_price' => 2000000,
        ]);

        $this->actingAs($settlementUser);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '9999999')
            ->set('selling_fee_str', '888888')
            ->set('exchange_rate_str', '5000')
            ->set('sale_price_str', '7777777')
            ->call('save')
            ->assertSet('purchase_price_str', '1000000')
            ->assertSet('selling_fee_str', '700000')
            ->assertSet('exchange_rate_str', '1300')
            ->assertSet('sale_price_str', '2000000');
    }

    public function test_q7_c7a_admin_can_change_financial_fields(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'purchase_price' => 1000000,
        ]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '2000000')
            ->call('save');

        $v->refresh();
        $this->assertSame(2000000, (int) $v->purchase_price);
    }

    public function test_q7_h9_rrn_format_invalid_blocked(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '123456-789')
            ->call('save')
            ->assertHasErrors(['nice_reg_owner_rrn']);
    }

    public function test_q7_h9_rrn_format_valid_passes(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '900101-1234567')
            ->call('save')
            ->assertHasNoErrors(['nice_reg_owner_rrn']);
    }

    public function test_q7_h10_rrn_required_when_deregistration_checked(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_deregistered', true)
            ->set('nice_reg_owner_rrn', '')
            ->call('save')
            ->assertHasErrors(['nice_reg_owner_rrn']);
    }

    // ── 2026-05-19 풀회의 안건 E — 판매 정보 입력 시 4 필드 required ──

    public function test_e_sale_required_sale_date_when_sale_price_set(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();   // sale_price=0

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '1000000')
            ->set('sale_date', '')
            ->call('save')
            ->assertHasErrors(['sale_date']);
    }

    public function test_e_sale_required_buyer_when_sale_price_set(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '1000000')
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', '')
            ->call('save')
            ->assertHasErrors(['buyer_id_str']);
    }

    public function test_e_sale_required_exchange_rate_when_sale_price_set(): void
    {
        // 2026-05-20 사용자 정정 — KRW는 환율 자동 1 normalize. 외화 시나리오만 required.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['currency' => 'USD']);
        $buyer = Buyer::firstOrCreate(['name' => 'E TEST BUYER'], ['is_active' => true]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('currency', 'USD')
            ->set('sale_price_str', '1000000')
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('exchange_rate_str', '0')
            ->call('save')
            ->assertHasErrors(['exchange_rate_str']);
    }

    public function test_e_sale_krw_auto_normalizes_exchange_rate_to_one(): void
    {
        // 2026-05-20 사용자 정정 — KRW + sale_price > 0이면 saving 훅이 exchange_rate=1 자동 normalize.
        // 사용자 직관 "한국돈인데 환율 쓸 필요 없음" 보존 + DB CHECK exchange_rate > 0 통과.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();   // currency='KRW'
        $buyer = Buyer::firstOrCreate(['name' => 'E KRW BUYER'], ['is_active' => true]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '5000000')
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('exchange_rate_str', '0')   // 사용자가 비워둬도 OK
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertEquals(1.0, (float) $v->exchange_rate, 'KRW 차량은 saving 훅이 환율 1 자동 normalize');
    }

    public function test_e_sale_all_required_satisfied_passes(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $buyer = Buyer::firstOrCreate(['name' => 'E TEST BUYER 2'], ['is_active' => true]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '1000000')
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('exchange_rate_str', '1')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame(1000000, (int) $v->sale_price);
        $this->assertSame($buyer->id, $v->buyer_id);
    }

    // ── 2026-05-20 큐 22-C-light — 매입 자동 PBP Draft 생성 ──

    public function test_22c_no_auto_pbp_draft_on_purchase_price_input(): void
    {
        // jin 2026-07-03 — 자동 PBP Draft 제거. 매입가 입력 단순 저장은 PBP 를 만들지 않는다
        //   (재무처리 큐 자동 유입 방지). 미지급은 accessor 로 노출.
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => '22C-AUTO-1',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 5000000,
            'selling_fee' => 500000,
            'dhl_request' => false,
        ]);

        $this->assertCount(0, $v->purchaseBalancePayments()->get(), '자동 PBP Draft 생성 안 됨');
        $this->assertSame(5500000, $v->fresh()->purchase_unpaid_amount, '미지급은 accessor 로 전액(price+fee)');
    }

    public function test_22c_simple_save_never_creates_pbp_even_on_price_change(): void
    {
        // jin 2026-07-03 — 최초 저장/매입가 변경 모두 PBP 0건 유지.
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => '22C-AUTO-2',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 5000000,
            'dhl_request' => false,
        ]);
        $this->assertSame(0, $v->purchaseBalancePayments()->count(), '단순 저장 PBP 0건');

        $v->purchase_price = 7000000;
        $v->save();
        $this->assertSame(0, $v->purchaseBalancePayments()->count(), '매입가 변경도 PBP 0건');
    }

    public function test_22c_auto_pbp_skipped_when_paid_settlement_exists(): void
    {
        // paid Settlement 차량에 매입가 변경 시도 → 자동 PBP 생성 차단 (defensive)
        $admin = User::factory()->create(['permission' => 'admin']);

        // 자동 생성 trigger 안 타게 시드 컨텍스트(auth 없음)로 차량 생성
        $v = Vehicle::create([
            'vehicle_number' => '22C-AUTO-3',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 5000000,
            'dhl_request' => false,
        ]);
        $this->assertCount(0, $v->purchaseBalancePayments);

        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        // admin 로그인 후 매입 정보 재저장 — paid라 자동 PBP skip
        $this->actingAs($admin);
        $v->memo = '변경 테스트';
        $v->save();

        $v->refresh();
        $this->assertCount(0, $v->purchaseBalancePayments, 'paid Settlement 차량은 자동 PBP 생성 차단');
    }

    public function test_22c_pbp_creating_blocks_new_row_after_paid(): void
    {
        // PBP::creating 훅 — paid Settlement 후 신규 PBP 직접 생성 차단
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['purchase_price' => 5000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('paid 상태');
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1000000,
            'payment_date' => null,
        ]);
    }

    // ── 2026-05-20 큐 22-A-2 — FinalPayment::creating 훅 (해석 B 정정 / 매입 22-C-light 대칭) ──

    public function test_22a2_fp_creating_blocks_new_row_after_paid(): void
    {
        // FP::creating 훅 — paid Settlement 후 신규 FP 직접 생성 차단 (회계 무결성).
        // 영업이 잔금 N+ row 추가 시도해도 paid 차량은 막힌다. PBP 패턴과 대칭.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['sale_price' => 8000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('paid 상태');
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1000000,
            'payment_date' => null,
        ]);
    }

    public function test_22a2_fp_creating_skipped_when_no_auth(): void
    {
        // 시드·artisan 환경(auth 없음)에서는 creating 훅 우회 — seed 워크플로우 보존.
        $v = $this->makeVehicle(['sale_price' => 8000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        // auth() 없는 상태 — 정상 생성되어야 함
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1000000,
            'payment_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNotNull($fp->id);
    }

    public function test_22a2_fp_creating_allows_when_pending_settlement(): void
    {
        // paid 가 아닌 settlement_status (pending / confirmed) 는 차단 X — 분자 A안 정합.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['sale_price' => 8000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'pending',
        ]);

        $this->actingAs($admin);

        $fp = FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1000000,
            'payment_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNotNull($fp->id);
        $this->assertNull($fp->confirmed_at, '영업이 추가한 row 는 Draft (재무 확정 전)');
    }

    // ── 2026-05-20 #1 — vehicles/index save() DomainException → 토스트 (화이트스크린 방지) ──

    public function test_paid_settlement_fp_save_dispatches_notify_not_whitescreen(): void
    {
        // paid Settlement 차량에 잔금 N+ 추가 후 save() → DomainException → toast 변환 (화이트스크린 X).
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $v = $this->makeVehicle(['sale_price' => 8_000_000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('finalPayments', [
                ['id' => null, 'amount' => '1000000', 'payment_date' => now()->format('Y-m-d'), 'note' => ''],
            ])
            ->call('save')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? null) === 'error' && str_contains($params['message'] ?? '', 'paid'));
    }

    // ── 2026-05-20 큐 22-A-3b — type별 분자 정합 + 권한 매트릭스 ──

    public function test_22a3b_numerator_sums_only_confirmed_fp_across_all_types(): void
    {
        // 분자 A안: type 무관 confirmed_at IS NOT NULL 만 합산. Draft 제외.
        $v = $this->makeVehicle(['sale_price' => 10_000_000]);
        $v->finalPayments()->create(['amount' => 2_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $v->finalPayments()->create(['amount' => 1_000_000, 'type' => 'interim', 'confirmed_at' => now()]);
        $v->finalPayments()->create(['amount' => 500_000, 'type' => 'advance_1', 'confirmed_at' => now()]);
        $v->finalPayments()->create(['amount' => 800_000, 'type' => 'balance', 'confirmed_at' => now()]);
        // Draft 1건 — 분자에 미반영
        $v->finalPayments()->create(['amount' => 5_000_000, 'type' => 'balance', 'confirmed_at' => null]);

        $v->refresh();
        // 분자 = 2M + 1M + 0.5M + 0.8M = 4.3M (Draft 5M 제외)
        $this->assertEquals(4_300_000, (int) ($v->sale_price - $v->sale_unpaid_amount));
        $this->assertEquals(5_700_000, (int) $v->sale_unpaid_amount);
    }

    public function test_22a3b_numerator_fee_separate_type(): void
    {
        // 2026-05-28 — advance_1 과 fee(구 advance_2) 가 다른 type 으로 별도 합산되는지 확인.
        $v = $this->makeVehicle(['sale_price' => 5_000_000]);
        $v->finalPayments()->create(['amount' => 1_000_000, 'type' => 'advance_1', 'confirmed_at' => now()]);
        $v->finalPayments()->create(['amount' => 2_000_000, 'type' => 'fee', 'confirmed_at' => now()]);

        $v->refresh();
        $this->assertEquals(2_000_000, (int) $v->sale_unpaid_amount);   // 5M - 3M
    }

    public function test_22a3b_draft_fp_excluded_from_numerator(): void
    {
        // 영업이 추가한 Draft FP (confirmed_at NULL) 은 분자에 포함 안 됨.
        $v = $this->makeVehicle(['sale_price' => 10_000_000]);
        $v->finalPayments()->create(['amount' => 5_000_000, 'type' => 'balance', 'confirmed_at' => null]);

        $v->refresh();
        $this->assertEquals(10_000_000, (int) $v->sale_unpaid_amount, 'Draft 추가는 미수 변동 X');
    }

    public function test_22a3b_can_manage_payment_breakdown_allows_finance_manager_admin(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);

        $this->assertTrue($admin->canManagePaymentBreakdown());
        $this->assertTrue($finance->canManagePaymentBreakdown());
        $this->assertTrue($manager->canManagePaymentBreakdown());
        $this->assertFalse($sales->canManagePaymentBreakdown(), '영업은 4 항목 입력 차단');
        $this->assertFalse($clearance->canManagePaymentBreakdown(), '수출통관은 4 항목 입력 차단');
    }

    public function test_22a3b_vehicles_4cols_dropped(): void
    {
        // Mig C — vehicles 테이블에 4컬럼이 실제로 schema 에서 사라졌는지.
        $columns = \Schema::getColumnListing('vehicles');
        $this->assertNotContains('deposit_down_payment', $columns);
        $this->assertNotContains('interim_payment', $columns);
        $this->assertNotContains('advance_payment1', $columns);
        $this->assertNotContains('advance_payment2', $columns);
    }

    public function test_22a3b_4cols_fillable_silent_ignore(): void
    {
        // fillable 에서 4컬럼 제거 → Vehicle::create 에 키 넘겨도 silent ignore (예외 X).
        // makeVehicle 헬퍼는 backward-compat 으로 자동 FP 변환하므로 직접 Vehicle::create 사용.
        $buyer = Buyer::create(['name' => 'TEST', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'IGN-001',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_date' => '2026-05-01',
            'buyer_id' => $buyer->id,
            'sale_price' => 5_000_000,
            'deposit_down_payment' => 1_000_000,  // silent ignore — 키 자체가 fillable 아님
        ]);

        $this->assertNotNull($v->id);
        $this->assertEquals(5_000_000, (int) $v->sale_unpaid_amount, '4컬럼 ignored → FP 없으니 미수 = sale_price');
    }

    public function test_22a3b_type_per_row_independent_audit(): void
    {
        // type별 row 가 독립적으로 confirmed_at 잠금 (FP::updating).
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = $this->makeVehicle(['sale_price' => 10_000_000]);
        $fp = $v->finalPayments()->create([
            'amount' => 3_000_000,
            'type' => 'deposit_down',
            'confirmed_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('재무 확정된 잔금');
        $fp->update(['amount' => 4_000_000]);   // confirmed 후 amount 변경 차단
    }

    public function test_22a3b_allow_confirmed_mutation_flag_unlocks_temporarily(): void
    {
        // FP::$allowConfirmedMutation flag 우회 — vehicles/index 4 input save 흐름에서 사용.
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = $this->makeVehicle(['sale_price' => 10_000_000]);
        $v->finalPayments()->create([
            'amount' => 3_000_000,
            'type' => 'deposit_down',
            'confirmed_at' => now(),
        ]);

        FinalPayment::$allowConfirmedMutation = true;
        try {
            $v->finalPayments()->where('type', 'deposit_down')->whereNotNull('confirmed_at')->delete();
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }

        $this->assertEquals(0, $v->finalPayments()->where('type', 'deposit_down')->count());
    }

    public function test_22a3b_balance_type_default_for_draft_fp(): void
    {
        // 영업이 vehicles 판매 탭에서 잔금 N+ 추가 시 type='balance' default.
        // 마이그 22-A-1 (000004) 에서 default 'balance' DB-level 명시.
        // create() 직후 인스턴스에 default 가 반영 안 되므로 refresh() 필수.
        $v = $this->makeVehicle(['sale_price' => 10_000_000]);
        $fp = $v->finalPayments()->create([
            'amount' => 2_000_000,
            'payment_date' => now()->toDateString(),
        ]);
        $fp->refresh();

        $this->assertEquals('balance', $fp->type);
        $this->assertNull($fp->confirmed_at, '영업 입력 = Draft');
    }

    public function test_22a3b_paid_settlement_blocks_4_types_via_creating_hook(): void
    {
        // FP::creating 훅 (22-A-2) — paid Settlement 후 모든 type 의 신규 FP 차단.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['sale_price' => 8_000_000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('paid 상태');
        $v->finalPayments()->create([
            'amount' => 1_000_000,
            'type' => 'deposit_down',
            'confirmed_at' => now(),
        ]);
    }

    // ── 2026-05-20 큐 22-C 핵심 — PBP::creating canConfirmFinance 가드 + flag 우회 ──

    public function test_22c_pbp_creating_blocks_sales_role_direct_create(): void
    {
        // 영업이 PBP::create 직접 호출 시 canConfirmFinance 가드 발동 (Defense-in-depth).
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $v = $this->makeVehicle(['purchase_price' => 5_000_000]);

        $this->actingAs($sales);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('재무 권한자만');
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1_000_000,
            'payment_date' => now()->toDateString(),
        ]);
    }

    public function test_22c_pbp_creating_allows_finance_role(): void
    {
        // 재무 role 은 canConfirmFinance 통과 → 직접 PBP 생성 가능.
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $v = $this->makeVehicle(['purchase_price' => 5_000_000]);

        $this->actingAs($finance);

        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '재무 직접 추가',
        ]);

        $this->assertNotNull($pbp->id);
    }

    public function test_22c_pbp_skip_creating_guard_flag_bypasses_finance_check(): void
    {
        // $skipCreatingGuard flag → 영업이라도 PBP 생성 통과 (Vehicle::saved 자동 PBP 흐름).
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $v = $this->makeVehicle(['purchase_price' => 5_000_000]);
        // 기존 자동 PBP Draft 삭제 (Vehicle::saved 가 이미 생성했을 수 있음)
        $v->purchaseBalancePayments()->delete();

        $this->actingAs($sales);

        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $pbp = PurchaseBalancePayment::create([
                'vehicle_id' => $v->id,
                'amount' => 500_000,
                'payment_date' => null,
                'confirmed_at' => null,
                'created_by_user_id' => $sales->id,
                'note' => '시스템 자동 생성 시뮬레이션',
            ]);
            $this->assertNotNull($pbp->id);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
    }

    // ── 2026-05-20 사용자 정정 — 자동 PBP Draft payment_date = 매입일 동기화 ──

    public function test_22c_purchase_date_change_syncs_unconfirmed_pbp_payment_date(): void
    {
        // 매입일 변경 시 미확정(대기) 매입 잔금 payment_date 동기화 (자동 Draft 제거 후에도 유지되는 훅).
        // 자동 Draft 는 이제 안 만들어지므로 수동 미확정 PBP 로 검증. confirmed PBP 는 sync 대상 X.
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'purchase_price' => 5_000_000,
            'purchase_date' => '2026-05-15',
        ]);
        $this->assertSame(0, $v->purchaseBalancePayments()->count(), '자동 Draft 없음');

        // 재무가 대기(미확정) 매입 잔금 1건을 직접 등록했다고 가정.
        $pending = $v->purchaseBalancePayments()->create([
            'amount' => 3_000_000, 'type' => 'balance',
            'payment_date' => '2026-05-15', 'confirmed_at' => null,
        ]);

        // 매입일 변경 → 미확정 PBP payment_date 동기화
        $v->purchase_date = '2026-05-20';
        $v->save();

        $pending->refresh();
        $this->assertEquals('2026-05-20', $pending->payment_date->toDateString());
    }

    // ── 2026-05-20 큐 22-C-F — type별 분자 정합 + 권한 매트릭스 + schema 검증 ──

    public function test_22cf_purchase_unpaid_sums_only_confirmed_pbp_across_all_types(): void
    {
        // 분자 A안: type 무관 confirmed_at IS NOT NULL 만 합산. Draft 제외.
        $v = $this->makeVehicle(['purchase_price' => 10_000_000, 'selling_fee' => 500_000]);
        // 자동 PBP Draft (10.5M, Draft) 가 Vehicle::saved 훅에서 생성됨
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $v->purchaseBalancePayments()->create(['amount' => 2_000_000, 'type' => 'down', 'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
            $v->purchaseBalancePayments()->create(['amount' => 500_000, 'type' => 'selling_fee', 'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
            $v->purchaseBalancePayments()->create(['amount' => 1_000_000, 'type' => 'balance', 'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
        $v->refresh();
        // 분자 = 2M + 0.5M + 1M = 3.5M (자동 Draft 10.5M 제외, payment_date NULL 이라 SQL 도 제외)
        // 미지급 = 10.5M - 3.5M = 7M
        $this->assertSame(7_000_000, $v->purchase_unpaid_amount);
    }

    public function test_22cf_purchase_unpaid_excludes_unconfirmed_pbp(): void
    {
        // Draft (confirmed_at NULL) PBP 는 분자에서 제외.
        $v = $this->makeVehicle(['purchase_price' => 10_000_000]);
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $v->purchaseBalancePayments()->create(['amount' => 3_000_000, 'type' => 'down', 'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => null]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
        $v->refresh();
        $this->assertSame(10_000_000, $v->purchase_unpaid_amount, 'Draft 추가는 매입 미지급 변동 X');
    }

    public function test_22cf_can_confirm_finance_allows_finance_admin_manager_blocks_others(): void
    {
        // 2026-05-21 사용자 직접 결정 — 19-F SoD 정책 변경.
        // canConfirmFinance (= canConfirmFinanceTransfer) 허용: super/admin/재무/관리 (중간 관리자).
        // 영업·수출통관 차단 유지.
        $admin = User::factory()->create(['permission' => 'admin']);
        $super = User::factory()->create(['permission' => 'super']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $this->assertTrue($admin->canConfirmFinance());
        $this->assertTrue($super->canConfirmFinance());
        $this->assertTrue($finance->canConfirmFinance());
        $this->assertTrue($manager->canConfirmFinance(), '관리도 재무처리 가능 (중간 관리자)');
        $this->assertFalse($sales->canConfirmFinance(), '영업은 PBP 직접 입력 차단');
        $this->assertFalse($clearance->canConfirmFinance(), '수출통관은 PBP 직접 입력 차단');
    }

    public function test_22cf_vehicles_2cols_dropped(): void
    {
        // Mig C — vehicles 테이블에 2컬럼이 실제로 schema 에서 사라졌는지.
        $columns = \Schema::getColumnListing('vehicles');
        $this->assertNotContains('down_payment', $columns);
        $this->assertNotContains('selling_fee_payment', $columns);
    }

    public function test_22cf_2cols_fillable_silent_ignore(): void
    {
        // fillable 에서 2컬럼 제거 → Vehicle::create 에 키 넘겨도 silent ignore (예외 X).
        $v = Vehicle::create([
            'vehicle_number' => 'IGN-22CF',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'down_payment' => 5_000_000,       // ignored
            'selling_fee_payment' => 100_000,  // ignored
        ]);
        $this->assertNotNull($v->id);
        // fillable 에서 빠졌으니 row attribute 자체 없음 (silent ignore)
        $this->assertArrayNotHasKey('down_payment', $v->getAttributes());
        $this->assertArrayNotHasKey('selling_fee_payment', $v->getAttributes());
    }

    public function test_22cf_balance_type_default_for_pbp_no_type_passed(): void
    {
        // PBP 모델 default type = 'balance' (Mig A enum default).
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $v = $this->makeVehicle(['purchase_price' => 5_000_000]);

        $this->actingAs($finance);

        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1_000_000,
            'payment_date' => now()->toDateString(),
        ]);
        // SQLite/MySQL — enum default 'balance' 는 DB schema 레벨 적용. fresh() 로 재로드 후 확인.
        $this->assertSame('balance', $pbp->fresh()->type);
    }

    public function test_22cf_paid_settlement_blocks_2_types_via_creating_hook(): void
    {
        // paid Settlement 후 'down'·'selling_fee' type 직접 INSERT 도 차단 (22-A-3b 패턴).
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['purchase_price' => 10_000_000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('paid 상태');
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1_000_000,
            'type' => 'down',
        ]);
    }

    public function test_22cf_allow_confirmed_mutation_flag_unlocks_temporarily(): void
    {
        // confirmed_at SET 된 PBP 는 amount/payment_date 변경 차단. flag 로 일시 우회 가능.
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $this->actingAs($finance);

        $v = $this->makeVehicle(['purchase_price' => 5_000_000]);
        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 1_000_000,
            'type' => 'down',
            'payment_date' => now()->toDateString(),
            'confirmed_at' => now(),
        ]);

        // flag 없이 amount 변경 시도 → 차단
        try {
            $pbp->update(['amount' => 2_000_000]);
            $this->fail('confirmed PBP amount 수정은 차단되어야 함');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('회계 무결성', $e->getMessage());
        }

        // flag 켜면 우회 가능 — Volt _str sync 흐름에서만 사용
        PurchaseBalancePayment::$allowConfirmedMutation = true;
        try {
            $pbp->update(['amount' => 2_000_000]);
            $this->assertSame(2_000_000, (int) $pbp->fresh()->amount);
        } finally {
            PurchaseBalancePayment::$allowConfirmedMutation = false;
        }
    }

    // ── 2026-05-19 풀회의 안건 C — 말소 [everyone] (canHandleDeregistration) ──

    public function test_c_can_handle_deregistration_allows_4_roles_blocks_finance(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);

        $this->assertTrue($admin->canHandleDeregistration());
        $this->assertTrue($sales->canHandleDeregistration());
        $this->assertTrue($clearance->canHandleDeregistration());
        $this->assertTrue($manager->canHandleDeregistration());
        $this->assertFalse($finance->canHandleDeregistration(), '재무 role은 SoD로 말소 처리 차단');
    }

    public function test_c_clearance_role_can_set_rrn_for_deregistration(): void
    {
        // canHandleDeregistration() 사용자는 RRN silent restore에서 제외 (Day 5 보강).
        // 수출통관 role이 말소 처리 시 RRN 입력 가능해야 H10 validation 통과.
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $v = $this->makeVehicle();

        $this->actingAs($clearance);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '900101-1234567')
            ->call('save')
            ->assertSet('nice_reg_owner_rrn', '900101-1234567');

        $v->refresh();
        $this->assertSame('900101-1234567', $v->nice_reg_owner_rrn);
    }

    // 2026-05-19 풀회의 P0-1 — RRN silent restore.
    // 정산 role이 RRN 변경 시도 → restoreFinancialFieldsFromOriginal에서 원값 복원.
    public function test_p0_rrn_silent_restore_for_settlement_role(): void
    {
        $settlementUser = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $v = $this->makeVehicle(['nice_reg_owner_rrn' => '900101-1234567']);

        $this->actingAs($settlementUser);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '880202-7654321')
            ->call('save')
            ->assertSet('nice_reg_owner_rrn', '900101-1234567');

        $v->refresh();
        $this->assertSame('900101-1234567', $v->nice_reg_owner_rrn);
    }

    // ── 큐 10 — 정산·채권 무결성 (H3·H4·H5·H6) ────────────────────────

    public function test_q10_h3_blocks_confirmed_settlement_without_amount(): void
    {
        // ratio + status=confirmed + ratio=0 → throw
        $v = $this->makeVehicle();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('정산 확정·지급');
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 0,
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }

    public function test_q10_h3_allows_pending_settlement_with_zero_amount(): void
    {
        // pending 상태는 작성 중이라 0 허용
        $v = $this->makeVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 0,
            'settlement_status' => 'pending',
        ]);
        $this->assertNotNull($s->id);
    }

    public function test_q10_h4_snapshot_captured_on_paid(): void
    {
        $v = $this->makeVehicle([
            'currency' => 'USD',   // 2026-05-20 — KRW saving 훅 강제 1 회피 (외화 환율 snapshot 테스트)
            'purchase_price' => 1000000,
            'exchange_rate' => 1300,
            'export_declaration_amount' => 5000,
            'transport_fee' => 200,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $this->assertNull($s->confirmed_snapshot);

        $s->update([
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);
        $s->refresh();

        $this->assertNotNull($s->confirmed_snapshot);
        $this->assertSame(1000000, (int) ($s->confirmed_snapshot['purchase_price'] ?? 0));
        $this->assertSame(1300, (int) ($s->confirmed_snapshot['exchange_rate'] ?? 0));
        $this->assertArrayHasKey('total_margin', $s->confirmed_snapshot);
    }

    public function test_q10_h4_blocks_vehicle_financial_change_after_paid(): void
    {
        // 회의확장씬 #8 (2026-05-22) — paid 후 secondary='pending' 자동 set 으로 [관리]/[재무]/admin 잠금 해제.
        // 본 테스트는 '2차 정산 closed 후 잠금 복귀' 시나리오 — secondary_status='closed' 명시.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['purchase_price' => 1000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'secondary_closed_at' => now(),
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '2000000')
            ->call('save')
            ->assertHasErrors(['purchase_price_str']);

        $v->refresh();
        $this->assertSame(1000000, (int) $v->purchase_price);
    }

    public function test_q10_h4_allows_non_financial_change_after_paid(): void
    {
        // paid 후에도 회계 외 컬럼(메모 등)은 변경 가능
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['purchase_price' => 1000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('memo', '메모 수정')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_q10_h5_final_payment_creates_receivable_history(): void
    {
        // 신규 FinalPayment → ReceivableHistory(method=deposit) 자동 생성
        $v = $this->makeVehicle(['sale_price' => 1000, 'currency' => 'KRW', 'exchange_rate' => 1]);
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 500,
            'payment_date' => '2026-05-01',
        ]);

        $rh = ReceivableHistory::where('final_payment_id', $fp->id)->first();
        $this->assertNotNull($rh);
        $this->assertSame('deposit', $rh->method);
        $this->assertSame(500.0, (float) $rh->amount);
    }

    public function test_q10_h5_receivable_creating_final_does_not_duplicate(): void
    {
        // ReceivableHistory(method=deposit) → FinalPayment 자동 생성 (기존 단방향).
        // 이때 역방향(FinalPayment::created → ReceivableHistory)가 또 만들어지면 중복 → skip 검증.
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['sale_price' => 1000, 'currency' => 'KRW', 'exchange_rate' => 1]);

        $rh = ReceivableHistory::create([
            'vehicle_id' => $v->id,
            'collected_at' => '2026-05-01',
            'collector_id' => $admin->id,
            'method' => 'deposit',
            'amount' => 300,
        ]);
        $rh->refresh();

        // FinalPayment 1개 생성됨
        $this->assertNotNull($rh->final_payment_id);
        $fpCount = FinalPayment::where('vehicle_id', $v->id)->count();
        $this->assertSame(1, $fpCount);

        // ReceivableHistory도 1개만 (중복 없음)
        $rhCount = ReceivableHistory::where('vehicle_id', $v->id)->count();
        $this->assertSame(1, $rhCount);
    }

    public function test_q10_h6_savings_used_creates_status_transaction(): void
    {
        // savings_used 변경 → SavingsStatus(USED) 자동 생성
        $buyer = Buyer::create(['name' => '바이어A', 'is_active' => true]);
        // 초기 잔액 500 적립
        SavingsStatus::create([
            'buyer_id' => $buyer->id,
            'currency' => 'USD',
            'transaction_type' => 'EARNED',
            'savings' => 500,
            'balance' => 500,
        ]);

        $v = $this->makeVehicle([
            'buyer_id' => $buyer->id,
            'currency' => 'USD',
            'savings_used' => 0,
        ]);

        $v->savings_used = 100;
        $v->save();

        $txn = SavingsStatus::where('vehicle_id', $v->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('USED', $txn->transaction_type);
        $this->assertSame(-100.0, (float) $txn->savings);
        $this->assertSame(400.0, (float) $txn->balance);
    }

    public function test_q10_h6_savings_used_unchanged_no_transaction(): void
    {
        // savings_used 변경 없으면 SavingsStatus 거래 미생성
        $buyer = Buyer::create(['name' => '바이어B', 'is_active' => true]);
        $v = $this->makeVehicle(['buyer_id' => $buyer->id, 'currency' => 'USD', 'savings_used' => 0]);

        $v->memo = '메모만 변경';
        $v->save();

        $count = SavingsStatus::where('vehicle_id', $v->id)->count();
        $this->assertSame(0, $count);
    }

    // ── 2026-05-20 #3 — 영업 본인 차량 목록 자동 한정 ──

    public function test_sales_role_lists_only_own_salesman_vehicles(): void
    {
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $mySalesman = Salesman::create(['name' => '본인', 'is_active' => true, 'user_id' => $salesUser->id]);
        $otherSalesman = Salesman::create(['name' => '타인', 'is_active' => true]);

        $today = now()->format('Y-m-d');
        $myVehicle = $this->makeVehicle(['salesman_id' => $mySalesman->id, 'purchase_date' => $today]);
        $othersVehicle = $this->makeVehicle(['salesman_id' => $otherSalesman->id, 'purchase_date' => $today]);

        $this->actingAs($salesUser);

        $vehicleIds = Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '')->instance()->vehicles()->pluck('id')->toArray();

        $this->assertContains($myVehicle->id, $vehicleIds, '본인 차량 보임');
        $this->assertNotContains($othersVehicle->id, $vehicleIds, '타인 차량 자동 제외');
    }

    public function test_admin_sees_all_vehicles(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $sm1 = Salesman::create(['name' => 'sm1', 'is_active' => true]);
        $sm2 = Salesman::create(['name' => 'sm2', 'is_active' => true]);

        $today = now()->format('Y-m-d');
        $v1 = $this->makeVehicle(['salesman_id' => $sm1->id, 'purchase_date' => $today]);
        $v2 = $this->makeVehicle(['salesman_id' => $sm2->id, 'purchase_date' => $today]);

        $this->actingAs($admin);

        $vehicleIds = Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '')->instance()->vehicles()->pluck('id')->toArray();
        $this->assertContains($v1->id, $vehicleIds);
        $this->assertContains($v2->id, $vehicleIds);
    }

    public function test_clearance_role_sees_all_vehicles(): void
    {
        // 통관·재무·관리는 전체 차량 보임 — 영업 한정은 영업 role 만.
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $sm = Salesman::create(['name' => 'sm', 'is_active' => true]);
        $v = $this->makeVehicle(['salesman_id' => $sm->id, 'purchase_date' => now()->format('Y-m-d')]);

        $this->actingAs($clearance);

        $vehicleIds = Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '')->instance()->vehicles()->pluck('id')->toArray();
        $this->assertContains($v->id, $vehicleIds, '통관 role 전체 차량 보임');
    }

    // ── 2026-05-20 #2-2+2-4 — 거래완료 자동 정산 생성 + Salesman.type 분기 ──

    private function makeCompletedVehicle(int $salesmanId, ?string $bl = null): Vehicle
    {
        // G1 B/L 100% 게이트 통과 패턴 — sale_price 1 + FP 1 완납.
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'salesman_id' => $salesmanId,
            'purchase_price' => 5_000_000,
            'sale_price' => 1,
        ]);
        $v->finalPayments()->create([
            'amount' => 1,
            'type' => 'balance',
            'payment_date' => now()->subDay()->toDateString(),
            'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        if ($bl !== null) {
            $v->bl_document = $bl;
            $v->save();   // → v3 거래완료 진입
        }

        return $v;
    }

    public function test_auto_settlement_created_on_completion_with_freelance_ratio(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $sm = Salesman::create(['name' => 'FREE', 'is_active' => true, 'type' => 'freelance']);
        $this->actingAs($admin);

        $v = $this->makeCompletedVehicle($sm->id, 'bl.pdf');

        $this->assertSame(1, $v->settlements()->count(), '자동 Settlement 1건 생성');
        $s = $v->settlements()->first();
        $this->assertSame('ratio', $s->settlement_type, 'freelance → ratio');
        $this->assertSame('pending', $s->settlement_status);
        $this->assertSame($sm->id, $s->salesman_id);
    }

    public function test_auto_settlement_employee_uses_per_unit(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $sm = Salesman::create(['name' => 'EMP', 'is_active' => true, 'type' => 'employee']);
        $this->actingAs($admin);

        $v = $this->makeCompletedVehicle($sm->id, 'bl.pdf');

        $this->assertSame('per_unit', $v->settlements()->first()->settlement_type);
    }

    public function test_auto_settlement_skipped_when_already_exists(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $sm = Salesman::create(['name' => 'EMP2', 'is_active' => true, 'type' => 'employee']);
        $this->actingAs($admin);

        $v = $this->makeCompletedVehicle($sm->id, 'bl.pdf');
        $this->assertSame(1, $v->settlements()->count(), '첫 자동 생성');

        $v->memo = '다른 변경';
        $v->save();   // 또 거래완료 cache 유지지만 wasChanged X
        $this->assertSame(1, $v->settlements()->count(), '두 번째 save 후에도 1건');
    }

    public function test_auto_settlement_skipped_without_salesman(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'salesman_id' => null,
            'purchase_price' => 5_000_000,
            'sale_price' => 1,
        ]);
        $v->finalPayments()->create(['amount' => 1, 'type' => 'balance', 'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
        $v->refreshCaches();
        $v->bl_document = 'bl.pdf';
        $v->save();

        $this->assertSame(0, $v->settlements()->count(), 'salesman 없으면 자동 생성 X');
    }

    public function test_auto_settlement_skipped_without_auth(): void
    {
        // auth 없음 (시드/artisan) → 자동 생성 skip. G1 게이트도 auth 우회.
        $sm = Salesman::create(['name' => 'SEED', 'is_active' => true, 'type' => 'employee']);
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000,
            'bl_document' => 'bl.pdf',
        ]);

        $this->assertSame(0, $v->settlements()->count(), '시드 컨텍스트는 자동 생성 X');
    }

    // ── 2026-05-20 #2 피드백 — 정산 차단 (거래완료 미수금) action ──

    public function test_settlement_blocked_by_unpaid_filters_completed_with_unpaid(): void
    {
        // 거래완료 + 미수금 → action 포함
        $v1 = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'purchase_price' => 5_000_000,
            'sale_price' => 10_000_000,
            'bl_document' => 'bl.pdf',
        ]);
        $v1->refreshCaches();
        $v1->sale_unpaid_amount_krw_cache = 2_000_000;
        $v1->saveQuietly();

        // 거래완료 + 완납 → 제외
        $v2 = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'purchase_price' => 5_000_000,
            'sale_price' => 10_000_000,
            'bl_document' => 'bl.pdf',
        ]);
        $v2->refreshCaches();
        $v2->sale_unpaid_amount_krw_cache = 0;
        $v2->saveQuietly();

        // 거래완료 아님 + 미수금 → 제외
        $v3 = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'purchase_price' => 5_000_000,
            'sale_price' => 10_000_000,
        ]);
        $v3->refreshCaches();
        $v3->sale_unpaid_amount_krw_cache = 3_000_000;
        $v3->saveQuietly();

        $count = Vehicle::query()->action('settlement_blocked_by_unpaid')->count();
        $this->assertSame(1, $count);
        $this->assertSame($v1->id, Vehicle::query()->action('settlement_blocked_by_unpaid')->first()->id);
    }

    // ── 2026-05-20 #1 피드백 — 수출통관 후보 차량 (clearance_candidates) ──

    public function test_clearance_candidates_includes_undregistered_with_sale(): void
    {
        // (a) 매입완료 + 판매 진행 + 말소 안 됨 → 포함
        $v = $this->makeVehicle([
            'purchase_price' => 5_000_000,
            'sale_price' => 8_000_000,
            'is_deregistered' => false,
        ]);
        $count = Vehicle::query()->action('clearance_candidates')->count();
        $this->assertSame(1, $count, '말소 안 된 차량은 통관 후보 포함');
        $this->assertSame($v->id, Vehicle::query()->action('clearance_candidates')->first()->id);
    }

    public function test_clearance_candidates_includes_deregistered_with_50_percent_paid(): void
    {
        // (b) 말소완료 + 판매 진행 + 입금률 ≥ 50% → 포함
        $v = $this->makeVehicle([
            'purchase_price' => 5_000_000,
            'sale_price' => 10_000_000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
        ]);
        // 입금률 50% → unpaid = 5,000,000 (KRW)
        $v->sale_unpaid_amount_krw_cache = 5_000_000;
        $v->saveQuietly();

        $count = Vehicle::query()->action('clearance_candidates')->count();
        $this->assertSame(1, $count, '말소완료 + 입금 50% 차량은 통관 후보 포함');
    }

    public function test_clearance_candidates_excludes_deregistered_with_under_50_percent(): void
    {
        // 말소완료 + 입금률 < 50% → 제외
        $v = $this->makeVehicle([
            'purchase_price' => 5_000_000,
            'sale_price' => 10_000_000,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
        ]);
        $v->sale_unpaid_amount_krw_cache = 7_000_000;  // 30% 입금 (70% 미수)
        $v->saveQuietly();

        $count = Vehicle::query()->action('clearance_candidates')->count();
        $this->assertSame(0, $count, '입금률 < 50% 말소완료 차량은 통관 후보 제외');
    }

    public function test_clearance_candidates_includes_after_clearance_started(): void
    {
        // 2026-05-21 사용자 피드백 — 통관 시작된 차량(수출통관완료/선적중/선적완료) 도 사이드바 메뉴에 노출.
        // 거래완료 진입 시만 사라지게 (active 필터로 자동 제외).
        $v = $this->makeVehicle([
            'purchase_price' => 5_000_000,
            'sale_price' => 8_000_000,
            'is_deregistered' => false,
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $count = Vehicle::query()->action('clearance_candidates')->count();
        $this->assertSame(1, $count, '통관 시작된 차량은 노출 (거래완료 전까지)');
    }

    public function test_clearance_candidates_excludes_completed_via_active_only(): void
    {
        // 거래완료 차량 → activeOnly 필터로 제외 (progress_status_cache != '거래완료')
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'purchase_price' => 5_000_000,
            'sale_price' => 8_000_000,
            'is_deregistered' => false,
            'bl_document' => 'bl.pdf',  // v3 거래완료 trigger
        ]);
        $v->refreshCaches();

        $count = Vehicle::query()->action('clearance_candidates')->count();
        $this->assertSame(0, $count, '거래완료 차량은 activeOnly 로 제외');
    }

    // ── jin 2026-07-03 — 자동 PBP Draft 제거: 영업 신규 등록도 PBP 0건 (재무처리 큐 유입 없음) ──

    public function test_22c_no_auto_pbp_draft_on_sales_new_vehicle_save(): void
    {
        // 영업이 신규 차량 등록(매입가 입력) 해도 자동 PBP Draft 안 생김.
        // 미지급은 accessor 로 노출 → 재무가 매입 잔금 탭에서 지급 시 직접 확정.
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        Salesman::create(['name' => 'TEST-AUTO-TOSS', 'is_active' => true, 'user_id' => $sales->id]);
        $this->actingAs($sales);

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', 'AUTO-TOSS-1')
            ->set('sales_channel', 'export')
            ->set('currency', 'KRW')
            ->set('purchase_date', '2026-05-20')
            ->set('purchase_price_str', '5000000')
            ->call('save');

        $v = Vehicle::where('vehicle_number', 'AUTO-TOSS-1')->first();
        $this->assertNotNull($v, '차량 저장 완료');
        $this->assertCount(0, $v->purchaseBalancePayments()->get(), '자동 PBP Draft 없음');
        $this->assertSame(5000000, $v->fresh()->purchase_unpaid_amount, '미지급 accessor 로 노출');
    }

    // ── 2026-05-20 안건 J 본격 — v3 거래완료 trigger 단순화 ──

    public function test_j_v3_treats_bl_document_alone_as_done(): void
    {
        // v3 거래완료 = bl_document 단독 (DHL 무관).
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'bl_document' => 'bl.pdf',
            'dhl_request' => false,  // DHL 미신청
        ]);
        $this->assertSame('거래완료', $v->progress_status);
    }

    public function test_j_v3_dhl_request_alone_does_not_complete(): void
    {
        // v3 — DHL 신청만으로 거래완료 X (bl_document 필수).
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'dhl_request' => true,   // DHL 신청
            // bl_document 없음
        ]);
        $this->assertNotSame('거래완료', $v->progress_status);
    }

    public function test_j_v2_still_requires_dhl_request(): void
    {
        // v2 row 자동 강등 X — v2 거래완료는 여전히 dhl_request && bl_document 둘 다 필요.
        // bl_loading_location 도 set — v2 선적완료 trigger 충족 (이중 trigger).
        $v = $this->makeVehicle([
            'progress_status_rule_version' => 2,
            'bl_document' => 'bl.pdf',
            'bl_loading_location' => '부산항',
            'dhl_request' => false,
        ]);
        $this->assertSame('선적완료', $v->progress_status, 'v2 grandfather: bl_document 만으론 거래완료 아님');

        $v->dhl_request = true;
        $this->assertSame('거래완료', $v->progress_status, 'v2: DHL 신청 추가 시 거래완료');
    }

    public function test_j_new_vehicle_defaults_to_v4(): void
    {
        // 안건 1 v4 (2026-05-21) — vehicles.progress_status_rule_version DEFAULT 4 (신규 row 만 영향).
        $v = Vehicle::create([
            'vehicle_number' => 'J-DEFAULT',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ]);
        $this->assertSame(4, (int) $v->fresh()->progress_status_rule_version);
    }

    public function test_j_scope_action_active_uses_progress_status_cache(): void
    {
        // scopeAction activeOnly — progress_status_cache != '거래완료' 단일 출처 (v2/v3 호환).
        // 거래완료 차량 (cache='거래완료') 은 active 필터에서 제외.
        $vDone = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'bl_document' => 'bl.pdf',
        ]);
        $vDone->refreshCaches();   // cache 갱신
        $vActive = $this->makeVehicle(['purchase_price' => 1_000_000]);

        $count = Vehicle::query()->action('purchase_unpaid')->count();
        // vActive 만 active 필터 통과 (미지급 > 0 & 거래완료 아님)
        $this->assertSame(1, $count);
    }

    public function test_j_settlement_create_needed_uses_cache(): void
    {
        // scopeAction settlement_create_needed — progress_status_cache='거래완료' 차량 중 정산 미생성.
        $vDone = $this->makeVehicle([
            'progress_status_rule_version' => 3,
            'bl_document' => 'bl.pdf',
        ]);
        $vDone->refreshCaches();
        $vNotDone = $this->makeVehicle(['progress_status_rule_version' => 3]);
        $vNotDone->refreshCaches();

        $count = Vehicle::query()->action('settlement_create_needed')->count();
        $this->assertSame(1, $count, '거래완료 차량만 정산 생성 필요');
    }

    // ── 회의확장씬 안건 1 v4 cascade 5단계 검증 (2026-05-21) ──────────────────────────
    // v4 매핑 (우선순위 높→낮):
    //   1. bl_document → 거래완료
    //   2. bl_document AND is_export_cleared → 통관완료 (실질 도달 불가)
    //   3. is_export_cleared AND bl_loading_location → 통관중
    //   4. bl_loading_location AND export_declaration_document → 선적완료
    //   5. bl_loading_location → 선적중

    public function test_v4_step5_bl_loading_location_alone_returns_선적중(): void
    {
        $v = $this->makeVehicle([
            'sale_price' => 1_000_000, 'deposit_down_payment' => 1_000_000,
            'bl_loading_location' => '부산항',
        ]);
        $this->assertSame('선적중', $v->progress_status);
    }

    public function test_v4_step4_bl_loading_location_with_export_declaration_doc_returns_선적완료(): void
    {
        $v = $this->makeVehicle([
            'sale_price' => 1_000_000, 'deposit_down_payment' => 1_000_000,
            'bl_loading_location' => '부산항',
            'export_declaration_document' => 'edoc.pdf',
        ]);
        $this->assertSame('선적완료', $v->progress_status);
    }

    public function test_v4_step3_export_cleared_with_bl_loading_returns_통관중(): void
    {
        $v = $this->makeVehicle([
            'sale_price' => 1_000_000, 'deposit_down_payment' => 1_000_000,
            'bl_loading_location' => '부산항',
            'is_export_cleared' => true,
        ]);
        $this->assertSame('통관중', $v->progress_status);
    }

    public function test_v4_step1_bl_document_alone_returns_거래완료(): void
    {
        // bl_document 단독 → 거래완료. is_export_cleared 무관, bl_loading_location 무관.
        $v = $this->makeVehicle([
            'sale_price' => 1_000_000, 'deposit_down_payment' => 1_000_000,
            'bl_document' => 'bl.pdf',
        ]);
        $this->assertSame('거래완료', $v->progress_status);
    }

    public function test_v4_no_bl_loading_falls_back_to_sale_stage(): void
    {
        // bl_loading_location 없으면 v4 cascade 5단계 모두 매칭 X → 판매/매입 단계로 fallback.
        // shipping_date·is_export_cleared 채워도 v4에선 단계 평가 안 됨 (v3과 다름).
        $v = $this->makeVehicle([
            'sale_price' => 1_000_000, 'deposit_down_payment' => 1_000_000,
            'shipping_date' => '2026-05-01',
            'is_export_cleared' => true,
            'export_declaration_document' => 'edoc.pdf',
        ]);
        // v4: is_export_cleared && bl_loading_location 매칭 X (bl_loading_location 없음).
        //     bl_loading_location && export_declaration_document 매칭 X (bl_loading_location 없음).
        //     bl_loading_location 매칭 X. → fallback 판매완료.
        $this->assertSame('판매완료', $v->progress_status);
    }
}
