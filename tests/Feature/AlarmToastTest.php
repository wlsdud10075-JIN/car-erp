<?php

namespace Tests\Feature;

use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-06-24 — alarm-center 폴링 시 새 알람 도착하면 떴다 사라지는 토스트(dispatch notify).
 * 배지/벨(영구)은 별도 유지 — 토스트는 보조. (jin: car-erp↔board 주고받을 때 즉시 인지)
 */
class AlarmToastTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function arrivalAlarm(): TaskAlarm
    {
        $v = Vehicle::create(['vehicle_number' => '70가'.rand(1000, 9999), 'sales_channel' => 'export']);

        return TaskAlarm::create([
            'type' => 'purchase_arrival',
            'vehicle_id' => $v->id,
            'target_role' => '관리',
            'message_meta' => ['vehicle_number' => $v->vehicle_number],
        ]);
    }

    public function test_new_alarm_after_mount_dispatches_toast(): void
    {
        $this->actingAs($this->manager());
        $c = Volt::test('erp.alarm-center');   // 마운트: 알람 0 → 기준선 0

        $this->arrivalAlarm();                  // 새 알람 도착
        $c->call('poll')->assertDispatched('notify');
    }

    public function test_existing_alarm_at_mount_does_not_toast(): void
    {
        $this->actingAs($this->manager());
        $this->arrivalAlarm();                  // 마운트 전부터 존재
        $c = Volt::test('erp.alarm-center');    // 기준선에 포함

        $c->call('poll')->assertNotDispatched('notify');
    }

    public function test_toast_fires_once_not_repeated(): void
    {
        $this->actingAs($this->manager());
        $c = Volt::test('erp.alarm-center');
        $this->arrivalAlarm();

        $c->call('poll')->assertDispatched('notify');    // 1회차 — 토스트
        $c->call('poll')->assertNotDispatched('notify');  // 2회차 — 같은 알람, 토스트 X
    }
}
