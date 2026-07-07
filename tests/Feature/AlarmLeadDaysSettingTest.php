<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 9 (jin 2026-07-07) — 기능설정 알람 항목별 리드데이("며칠 전") 입력.
 */
class AlarmLeadDaysSettingTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_defaults_loaded_and_saved(): void
    {
        $this->actingAs($this->super());

        $c = Volt::test('admin.settings');
        // 기본값 로드 (ETA 10 / 서류마감 5)
        $this->assertSame(10, $c->get('alarmLeadDays')['eta']);
        $this->assertSame(5, $c->get('alarmLeadDays')['document']);

        // 항목별 변경 후 저장 → Setting 반영
        $c->set('alarmLeadDays.eta', 7)
            ->set('alarmLeadDays.document', 3)
            ->call('saveAlarmParams')
            ->assertHasNoErrors();

        $this->assertSame(7, Setting::get('alarm_eta_lead_days'));
        $this->assertSame(3, Setting::get('alarm_doc_deadline_lead_days'));
    }

    public function test_negative_clamped_to_zero(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('alarmLeadDays.document', -5)
            ->call('saveAlarmParams');

        $this->assertSame(0, Setting::get('alarm_doc_deadline_lead_days'));
    }
}
