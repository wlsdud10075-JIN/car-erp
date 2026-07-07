<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * item 6 (jin 2026-07-07) — 선적 서류마감 5일전 알람 (type 'document_deadline', target_role '관리').
 *
 * 단일출처 = Vehicle::scopeAction('document_deadline_reminder'). alarms:scan 이 생성/갱신/자동해소.
 * "5일 전부터" = Setting('alarm_doc_deadline_lead_days', 기본 5). ETA 알람과 동형 패턴.
 */
class DocumentDeadlineAlarmTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        Setting::updateOrCreate(['key' => 'alarm_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    private function vehicle(array $attr = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '88가'.rand(1000, 9999),
            'sales_channel' => 'export',
        ], $attr));
    }

    public function test_scan_creates_deadline_alarm_for_management(): void
    {
        $this->enable();
        $v = $this->vehicle(['document_deadline_date' => now()->addDays(3)->toDateString()]);

        $this->artisan('alarms:scan')->assertSuccessful();

        $this->assertDatabaseHas('task_alarms', [
            'type' => 'document_deadline',
            'vehicle_id' => $v->id,
            'target_role' => '관리',
            'resolved_at' => null,
        ]);
        $alarm = TaskAlarm::where('type', 'document_deadline')->where('vehicle_id', $v->id)->first();
        $this->assertSame($v->document_deadline_date->toDateString(), $alarm->due_date->toDateString());
        $this->assertSame($v->vehicle_number, $alarm->message_meta['vehicle_number']);
    }

    public function test_far_future_and_null_deadline_not_alarmed(): void
    {
        $this->enable();
        $this->vehicle(['document_deadline_date' => now()->addDays(10)->toDateString()]); // 5일 밖
        $this->vehicle();                                                                  // 마감일 없음

        $this->artisan('alarms:scan');

        $this->assertSame(0, TaskAlarm::where('type', 'document_deadline')->count());
    }

    public function test_idempotent_and_auto_resolves_when_deadline_cleared(): void
    {
        $this->enable();
        $v = $this->vehicle(['document_deadline_date' => now()->addDays(2)->toDateString()]);

        $this->artisan('alarms:scan');
        $this->artisan('alarms:scan'); // 중복 생성 없어야
        $this->assertSame(1, TaskAlarm::where('type', 'document_deadline')->where('vehicle_id', $v->id)->count());

        // 마감일 제거 → 다음 스캔에서 자동 해소
        $v->update(['document_deadline_date' => null]);
        $this->artisan('alarms:scan');
        $this->assertSame(0, TaskAlarm::where('type', 'document_deadline')->open()->count());
    }

    public function test_disabled_gate_creates_nothing(): void
    {
        // alarm_enabled 미설정(기본 false) → 생성 안 됨 (배포 ≠ 작동)
        $this->vehicle(['document_deadline_date' => now()->addDays(1)->toDateString()]);
        $this->artisan('alarms:scan');
        $this->assertSame(0, TaskAlarm::where('type', 'document_deadline')->count());
    }

    public function test_visibility_management_and_admin_see_sales_do_not(): void
    {
        $this->enable();
        $v = $this->vehicle(['document_deadline_date' => now()->addDays(1)->toDateString()]);
        $this->artisan('alarms:scan');

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'role' => '영업', 'email_verified_at' => now()]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        $this->assertSame(1, TaskAlarm::visibleTo($admin)->where('type', 'document_deadline')->count(), 'admin 은 본다');
        $this->assertSame(1, TaskAlarm::visibleTo($manager)->where('type', 'document_deadline')->count(), '업무관리자는 본다');
        $this->assertSame(0, TaskAlarm::visibleTo($sales)->where('type', 'document_deadline')->count(), '영업은 못 본다');
    }
}
