<?php

namespace Tests\Feature;

use App\Models\DocumentAccessLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_only_documents_are_blocked_for_carpul_channel(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'carpul',
        ]);

        foreach (['invoice', 'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract'] as $type) {
            $response = $this->get(route('erp.vehicles.documents.show', [$vehicle->id, $type]));
            $response->assertStatus(403);
        }
    }

    public function test_korean_documents_are_allowed_for_carpul_channel(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'carpul',
        ]);

        foreach (['deregistration', 'deregistration_contract', 'poa'] as $type) {
            $response = $this->get(route('erp.vehicles.documents.show', [$vehicle->id, $type]));
            $response->assertOk();
        }
    }

    public function test_document_download_creates_access_log(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'export',
        ]);

        $this->assertEquals(0, DocumentAccessLog::count());

        $this->get(route('erp.vehicles.documents.show', [$vehicle->id, 'deregistration']));

        $this->assertEquals(1, DocumentAccessLog::count());
        $log = DocumentAccessLog::first();
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertEquals($vehicle->id, $log->vehicle_id);
        $this->assertEquals('deregistration', $log->document_type);
    }

    public function test_regular_user_can_download_with_logging(): void
    {
        // 결정 1 = D: 모든 인증 user 다운로드 가능 + 감사 로그
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($user);

        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'export',
        ]);

        $response = $this->get(route('erp.vehicles.documents.show', [$vehicle->id, 'deregistration']));
        $response->assertOk();
        $this->assertEquals(1, DocumentAccessLog::where('user_id', $user->id)->count());
    }

    public function test_failed_channel_check_does_not_create_log(): void
    {
        // 채널 격리로 403 시 로그가 생성되지 않는지 확인 (성공한 다운로드만 로깅)
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'carpul',
        ]);

        $this->get(route('erp.vehicles.documents.show', [$vehicle->id, 'invoice']));

        $this->assertEquals(0, DocumentAccessLog::count(), '403 차단된 요청은 access log에 기록되지 않아야 함');
    }

    // ── #3 다중차량 선적 서류 ───────────────────────────────────────

    public function test_multi_vehicle_shipping_document_downloads_and_logs_per_vehicle(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $ids = collect(range(1, 3))->map(fn ($i) => Vehicle::create([
            'vehicle_number' => '12가000'.$i,
            'sales_channel' => 'export',
            'sale_price' => 1000 * $i,
        ])->id);

        $response = $this->get(route('erp.vehicles.documents.multi', [
            'type' => 'container_invoice_packing',
            'ids' => $ids->implode(','),
        ]));

        $response->assertOk();
        // 차량당 1행 (개인정보 감사)
        $this->assertEquals(3, DocumentAccessLog::count());
        foreach ($ids as $id) {
            $this->assertEquals(1, DocumentAccessLog::where('vehicle_id', $id)->count());
        }
    }

    public function test_multi_vehicle_blocked_when_any_vehicle_is_not_export(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $export = Vehicle::create(['vehicle_number' => '12가0001', 'sales_channel' => 'export']);
        $carpul = Vehicle::create(['vehicle_number' => '12가0002', 'sales_channel' => 'carpul']);

        $response = $this->get(route('erp.vehicles.documents.multi', [
            'type' => 'roro_contract',
            'ids' => $export->id.','.$carpul->id,
        ]));

        $response->assertStatus(403);
        $this->assertEquals(0, DocumentAccessLog::count());
    }

    public function test_multi_vehicle_rejects_non_shipping_type(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $vehicle = Vehicle::create(['vehicle_number' => '12가0001', 'sales_channel' => 'export']);

        // invoice/clearance 는 다중차량 미지원 (단일 전용)
        $response = $this->get(route('erp.vehicles.documents.multi', [
            'type' => 'invoice',
            'ids' => (string) $vehicle->id,
        ]));

        $response->assertStatus(404);
    }

    public function test_multi_vehicle_rejects_over_thirty(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $ids = collect(range(1, 31))->map(fn ($i) => Vehicle::create([
            'vehicle_number' => '12가'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
        ])->id);

        $response = $this->get(route('erp.vehicles.documents.multi', [
            'type' => 'container_contract',
            'ids' => $ids->implode(','),
        ]));

        $response->assertStatus(422);
    }
}
