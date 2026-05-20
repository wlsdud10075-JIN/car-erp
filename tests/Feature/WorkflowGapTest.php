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
            'advance_payment2' => 'advance_2',
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

        $v = Vehicle::create(array_merge($defaults, $overrides));

        foreach ($sale4Inserts as $row) {
            $v->finalPayments()->create([
                'amount' => $row['amount'],
                'type' => $row['type'],
                'confirmed_at' => now(),
            ]);
        }
        if (! empty($sale4Inserts)) {
            $v->refresh();
        }

        return $v;
    }

    // 큐 16 — test_c3_export_only_stages_skipped_for_heyman_channel 삭제
    // (채널 단일화로 채널별 progress_status 분기 자체 제거)

    public function test_c3_export_channel_still_evaluates_export_stages(): void
    {
        // 큐 2.6 — 수출통관완료는 v2부터 is_export_cleared && export_declaration_document 둘 다 필요.
        $v = $this->makeVehicle([
            'sales_channel' => 'export',
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

    public function test_c5_blocks_export_entry_with_unpaid_sale_cache(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
            'sale_price' => 1000,
            'export_buyer_id' => 1,
            'shipping_date' => '2026-05-01',
        ]);
        // cache 컬럼에 미입금 잔존 시뮬레이션
        $v->setRawAttributes(array_merge($v->getAttributes(), [
            'sale_unpaid_amount_krw_cache' => 500,
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('판매 미입금');
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

    public function test_q26_h4_cascade_blocks_loading_location_without_export_cleared(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_export_cleared' => false,
            'bl_loading_location' => '부산항',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('수출통관 완료 처리');
        $v->guardAttachmentDeps();
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
            'stage' => 'dhl',
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

    public function test_22c_auto_pbp_draft_created_on_purchase_price_input(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        // 영업 매입가 입력 → 자동 PBP Draft 1건 생성
        $v = Vehicle::create([
            'vehicle_number' => '22C-AUTO-1',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 5000000,
            'selling_fee' => 500000,
            'down_payment' => 1000000,
            'dhl_request' => false,
        ]);

        $pbps = $v->purchaseBalancePayments()->get();   // fresh query
        $this->assertCount(1, $pbps, '자동 PBP Draft 1건 생성');
        $this->assertSame(4500000, (int) $pbps->first()->amount, 'amount = price + fee - down = 5000000 + 500000 - 1000000');
        $this->assertNull($pbps->first()->payment_date, 'Draft: payment_date NULL');
        $this->assertNull($pbps->first()->confirmed_at, 'Draft: confirmed_at NULL');
        $this->assertSame($admin->id, $pbps->first()->created_by_user_id);
    }

    public function test_22c_auto_pbp_skipped_when_pbp_exists(): void
    {
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
        $this->assertSame(1, $v->purchaseBalancePayments()->count(), '1차 자동 생성');

        // 매입가 변경 → 자동 재생성 X (PO 우려 회피)
        $v->purchase_price = 7000000;
        $v->save();
        $this->assertSame(1, $v->purchaseBalancePayments()->count(), '매입가 변경 시 PBP 재생성 X');
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
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle(['purchase_price' => 1000000]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
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
}
