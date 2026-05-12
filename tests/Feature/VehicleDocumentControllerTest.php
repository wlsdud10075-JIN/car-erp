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

        foreach (['invoice', 'sales_contract', 'ro_cipl', 'con_cipl'] as $type) {
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

        foreach (['deregistration', 'registration_application', 'transfer_certificate'] as $type) {
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
}
