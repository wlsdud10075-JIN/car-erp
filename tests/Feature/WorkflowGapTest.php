<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Salesman;
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

        return Vehicle::create(array_merge([
            'vehicle_number' => 'WGT-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'is_disposed' => false,
            'dhl_request' => false,
        ], $overrides));
    }

    // ── C3 — progress_status 채널 분기 ──────────────────────────────

    public function test_c3_export_only_stages_skipped_for_heyman_channel(): void
    {
        // 헤이맨 차량에 export·bl·dhl 컬럼이 잔존해도 통관·선적·DHL 단계로 점프 안 함.
        // 새 Vehicle 인스턴스에 in-memory 속성 set → progress_status accessor만 평가 (DB 저장 X).
        $v = new Vehicle([
            'sales_channel' => 'heyman',
            'is_disposed' => false, 'dhl_request' => false,
            'sale_price' => 1000, 'deposit_down_payment' => 1000,
            'shipping_date' => '2026-05-01',
            'export_declaration_document' => 'edoc.pdf',
            'bl_loading_location' => '부산항', 'bl_document' => 'bl.pdf',
        ]);
        // export_buyer_id는 fillable이 아닐 수도 — 별도 set
        $v->setRawAttributes(array_merge($v->getAttributes(), [
            'export_buyer_id' => 1,
            'sale_unpaid_amount_krw_cache' => 0,
        ]));

        $this->assertSame('판매완료', $v->progress_status);
    }

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

    public function test_c3_disposed_overrides_all_stages(): void
    {
        $v = $this->makeVehicle([
            'is_disposed' => true,
            'sales_channel' => 'export',
            'export_declaration_document' => 'edoc.pdf',
        ]);

        $this->assertSame('폐기', $v->progress_status);
    }

    // ── C4·C5 — guard 메서드 직접 검증 (UI save() 흐름에서 호출되는 동일 로직) ──

    public function test_c4_blocks_export_entry_without_deregistration(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_disposed' => false,
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
            'is_disposed' => false,
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
            'is_disposed' => false,
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

    public function test_c4_c5_skipped_for_non_export_channel(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'heyman',
            'is_deregistered' => false,
            'sale_price' => 1000,
            'export_buyer_id' => 1,
        ]);

        $v->guardStageOrderForExport();
        $this->assertTrue(true);
    }

    public function test_c4_c5_skipped_when_disposed(): void
    {
        $v = new Vehicle([
            'is_disposed' => true,
            'sales_channel' => 'export',
            'is_deregistered' => false,
            'export_buyer_id' => 1, 'shipping_date' => '2026-05-01',
        ]);

        $v->guardStageOrderForExport();
        $this->assertTrue(true);
    }

    public function test_c4_c5_skipped_when_no_export_input(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_disposed' => false,
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
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
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
        $user = User::factory()->create(['permission' => 'user', 'role' => '통관']);
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
            'is_disposed' => false,
            'dhl_request' => true,
            // bl_document 비어있음
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 문서 업로드');
        $v->guardAttachmentDeps();
    }

    public function test_h1_allows_dhl_request_with_bl_document(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_disposed' => false,
            'dhl_request' => true,
            'bl_document' => 'bl.pdf',
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);
    }

    public function test_h1_skipped_for_non_export_channel(): void
    {
        // 헤이맨/카풀은 dhl 컬럼 사용 안 함. 검증 skip.
        $v = new Vehicle([
            'sales_channel' => 'heyman',
            'dhl_request' => true,
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);
    }

    public function test_h1_h2_skipped_when_disposed(): void
    {
        $v = new Vehicle([
            'is_disposed' => true,
            'sales_channel' => 'export',
            'dhl_request' => true,
            'is_export_cleared' => true,
        ]);

        $v->guardAttachmentDeps();
        $this->assertTrue(true);
    }

    // ── H2 — 수출통관 완료 체크 시 수출신고서 첨부 강제 ────────────────

    public function test_h2_blocks_export_cleared_without_declaration_document(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_disposed' => false,
            'is_export_cleared' => true,
            // export_declaration_document 비어있음
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('수출신고서 업로드');
        $v->guardAttachmentDeps();
    }

    public function test_h2_allows_export_cleared_with_declaration_document(): void
    {
        $v = new Vehicle([
            'sales_channel' => 'export',
            'is_disposed' => false,
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
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 500,
            'payment_date' => '2026-05-01',
        ]);
        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount_krw_cache);

        $fp->delete();
        $v->refresh();

        // 잔금 삭제 → 미입금 500 다시 발생 → 캐시 재계산
        $this->assertSame(500, (int) $v->sale_unpaid_amount_krw_cache);
    }
}
