<?php

namespace Tests\Feature;

use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AlarmUiTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function salesUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
    }

    private function alarm(): TaskAlarm
    {
        $v = Vehicle::create(['vehicle_number' => '88바'.rand(1000, 9999), 'sales_channel' => 'export']);

        return TaskAlarm::create([
            'type' => 'eta_clearance',
            'vehicle_id' => $v->id,
            'target_role' => '수출통관',
            'due_date' => now()->addDays(5)->toDateString(),
            'message_meta' => ['vehicle_number' => $v->vehicle_number, 'eta_date' => now()->addDays(5)->toDateString(), 'unpaid_amount_krw' => 0],
        ]);
    }

    public function test_clearance_user_sees_and_confirms_alarm(): void
    {
        $user = $this->clearanceUser();
        $alarm = $this->alarm();
        $this->actingAs($user);

        Volt::test('erp.alarm-center')
            ->assertSee($alarm->message_meta['vehicle_number'])
            ->call('confirm', $alarm->id);

        $alarm->refresh();
        $this->assertNotNull($alarm->confirmed_at);
        $this->assertSame($user->id, $alarm->confirmed_by);   // 서버 지정
    }

    public function test_sales_user_cannot_see_alarm(): void
    {
        $alarm = $this->alarm();
        $this->actingAs($this->salesUser());

        Volt::test('erp.alarm-center')->assertDontSee($alarm->message_meta['vehicle_number']);
    }

    public function test_sales_user_confirm_is_forbidden_idor(): void
    {
        $alarm = $this->alarm();
        $this->actingAs($this->salesUser());

        Volt::test('erp.alarm-center')->call('confirm', $alarm->id)->assertStatus(403);

        $this->assertNull($alarm->fresh()->confirmed_at);
    }

    public function test_inbox_page_access_control(): void
    {
        $alarm = $this->alarm();

        $this->actingAs($this->clearanceUser());
        $this->get(route('erp.alarms.index'))->assertOk()->assertSee($alarm->message_meta['vehicle_number']);

        $this->actingAs($this->salesUser());
        $this->get(route('erp.alarms.index'))->assertForbidden();
    }
}
