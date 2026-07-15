<?php

namespace Tests\Feature;

use App\Models\MailDeliveryLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 문서 메일 발송 로그 UI 페이지 검증 (2026-07-15).
 */
class MailDeliveryLogsUiTest extends TestCase
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
            ->get('/admin/mail-delivery-logs')
            ->assertStatus(403);
    }

    public function test_admin_can_view_mail_delivery_logs_page(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)
            ->get('/admin/mail-delivery-logs')
            ->assertOk()
            ->assertSeeText('메일 발송 로그');
    }

    public function test_status_filter_limits_results(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $v = Vehicle::create([
            'vehicle_number' => 'MD-T-1',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-04-01',
        ]);

        MailDeliveryLog::create([
            'vehicle_id' => $v->id, 'user_id' => $admin->id, 'channel' => 'gmail',
            'from_address' => 'a@co.test', 'to_email' => 'buyer@x.test',
            'subject' => 'Docs', 'document_names' => ['bl.xlsx'], 'status' => 'sent', 'error' => null,
        ]);
        MailDeliveryLog::create([
            'vehicle_id' => $v->id, 'user_id' => $admin->id, 'channel' => 'ses',
            'from_address' => 'a@co.test', 'to_email' => 'buyer@x.test',
            'subject' => 'Docs', 'document_names' => [], 'status' => 'failed', 'error' => 'SMTP down',
        ]);

        $this->actingAs($admin);

        $logs = Volt::test('admin.mail-delivery-logs.index')
            ->set('statusFilter', 'failed')
            ->instance()->logs;

        $this->assertSame(1, $logs->count());
        $this->assertSame('failed', $logs->first()->status);
    }
}
