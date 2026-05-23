<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 Phase 3-1 (d-2) (2026-05-23) — 감사 로그 UI 페이지 검증 (별건3 흡수).
 */
class AuditLogsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_route_requires_admin_middleware(): void
    {
        $userRole = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($userRole)
            ->get('/admin/audit-logs')
            ->assertStatus(403);
    }

    public function test_admin_can_view_audit_logs_page(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)
            ->get('/admin/audit-logs')
            ->assertOk()
            ->assertSeeText('감사 로그');
    }

    public function test_action_filter_limits_results(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $v = Vehicle::create([
            'vehicle_number' => 'AL-T-1',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-04-01',
        ]);
        AuditLog::create([
            'user_id' => $admin->id, 'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
            'action' => 'created', 'column_name' => null,
        ]);
        AuditLog::create([
            'user_id' => $admin->id, 'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
            'action' => 'updated', 'column_name' => 'sale_price', 'old_value' => '0', 'new_value' => '1000',
        ]);

        $this->actingAs($admin);

        // updated 필터 — Vehicle::create 자동 created 로그는 제외 (수동 updated 1건만 매치)
        $logs = Volt::test('admin.audit-logs.index')
            ->set('actionFilter', 'updated')
            ->instance()->logs;

        $this->assertGreaterThanOrEqual(1, $logs->count());
        foreach ($logs as $log) {
            $this->assertSame('updated', $log->action);
        }
    }
}
