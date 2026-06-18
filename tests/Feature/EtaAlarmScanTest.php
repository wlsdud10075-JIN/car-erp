<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtaAlarmScanTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        Setting::updateOrCreate(['key' => 'alarm_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    private function exportVehicle(array $attr = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '99가'.rand(1000, 9999),
            'sales_channel' => 'export',
            'eta_date' => now()->addDays(5)->toDateString(),   // 도착 5일 후 = 리드데이 10 이내
        ], $attr));
    }

    public function test_scan_creates_alarm_for_imminent_eta_export_vehicle(): void
    {
        $this->enable();
        $v = $this->exportVehicle();

        $this->artisan('alarms:scan')->assertSuccessful();

        $this->assertDatabaseHas('task_alarms', [
            'type' => 'eta_clearance',
            'vehicle_id' => $v->id,
            'target_role' => '수출통관',
            'resolved_at' => null,
        ]);
        $alarm = TaskAlarm::where('vehicle_id', $v->id)->first();
        $this->assertSame($v->vehicle_number, $alarm->message_meta['vehicle_number']);
        $this->assertArrayNotHasKey('nice_reg_owner_rrn', $alarm->message_meta); // whitelist
    }

    public function test_scan_is_idempotent_and_refreshes_due_date_on_eta_change(): void
    {
        $this->enable();
        $v = $this->exportVehicle(['eta_date' => now()->addDays(5)->toDateString()]);

        $this->artisan('alarms:scan');
        $this->artisan('alarms:scan'); // 2회 — 중복 생성 없어야

        $this->assertSame(1, TaskAlarm::where('vehicle_id', $v->id)->count());

        // ETA 변경 → 다음 스캔에서 due_date 갱신
        $v->update(['eta_date' => now()->addDays(3)->toDateString()]);
        $this->artisan('alarms:scan');
        $alarm = TaskAlarm::where('vehicle_id', $v->id)->open()->first();
        $this->assertSame(now()->addDays(3)->toDateString(), $alarm->due_date->toDateString());
        $this->assertSame(1, TaskAlarm::where('vehicle_id', $v->id)->count());
    }

    public function test_channel_isolation_heyman_carpul_not_alarmed(): void
    {
        $this->enable();
        $this->exportVehicle(['sales_channel' => 'heyman']);
        $this->exportVehicle(['sales_channel' => 'carpul']);

        $this->artisan('alarms:scan');

        $this->assertSame(0, TaskAlarm::count());
    }

    public function test_far_future_eta_not_alarmed(): void
    {
        $this->enable();
        $this->exportVehicle(['eta_date' => now()->addDays(30)->toDateString()]); // 리드데이 밖

        $this->artisan('alarms:scan');

        $this->assertSame(0, TaskAlarm::count());
    }

    public function test_saved_hook_immediately_resolves_on_document_upload(): void
    {
        $this->enable();
        $v = $this->exportVehicle();
        $this->artisan('alarms:scan');
        $this->assertSame(1, TaskAlarm::where('vehicle_id', $v->id)->open()->count());

        // 수출신고서 업로드 → Vehicle::saved 즉시 자동해소
        $v->update(['export_declaration_document' => 'vehicles/1/export.pdf']);

        $this->assertSame(0, TaskAlarm::where('vehicle_id', $v->id)->open()->count());
        $resolved = TaskAlarm::where('vehicle_id', $v->id)->first();
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('document_uploaded', $resolved->resolved_reason);
    }

    public function test_scan_reconcile_resolves_when_condition_no_longer_matches(): void
    {
        $this->enable();
        $v = $this->exportVehicle();
        $this->artisan('alarms:scan');
        $this->assertSame(1, TaskAlarm::where('vehicle_id', $v->id)->open()->count());

        // ETA 를 먼 미래로 → 더 이상 조건 불일치 (saved 훅 자동해소 대상은 아님: 서류·거래완료 무관)
        $v->update(['eta_date' => now()->addDays(60)->toDateString()]);
        $this->artisan('alarms:scan');

        $this->assertSame(0, TaskAlarm::where('vehicle_id', $v->id)->open()->count());
        $this->assertSame('auto_resolved', TaskAlarm::where('vehicle_id', $v->id)->first()->resolved_reason);
    }

    public function test_disabled_flag_blocks_generation_but_dry_run_counts(): void
    {
        // alarm_enabled 미설정(기본 false)
        $this->exportVehicle();

        $this->artisan('alarms:scan')->assertSuccessful();
        $this->assertSame(0, TaskAlarm::count()); // 게이트로 생성 안 됨

        $this->artisan('alarms:scan --dry-run')->assertSuccessful();
        $this->assertSame(0, TaskAlarm::count()); // dry-run 도 생성 안 함 (카운트만)
    }

    public function test_completed_deal_excluded(): void
    {
        $this->enable();
        // 거래완료 = bl_document 있으면 progress_status_cache 가 거래완료 → active 제외
        $this->exportVehicle(['bl_document' => 'vehicles/x/bl.pdf']);

        $this->artisan('alarms:scan');

        $this->assertSame(0, TaskAlarm::count());
    }
}
