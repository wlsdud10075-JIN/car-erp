<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-11 ② (jin 10-28) — 송금인 Name·ID. 차량관리 검색은 **이름만**(ID 는 encrypted cast 라 LIKE 불가),
 * 기본정보 탭 2칸은 읽고·저장하고(§8 #94 「저장 배열에 안 실린 필드」), ID 는 DB·감사에 평문으로 남지 않는다.
 */
class VehicleRemitterSearchTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    public function test_search_matches_remitter_name_only(): void
    {
        $this->login();
        $match = Vehicle::create(['vehicle_number' => '61가6101', 'sales_channel' => 'export', 'remitter_name' => 'JOHN DOE', 'remitter_id' => 'AB1234567']);
        $other = Vehicle::create(['vehicle_number' => '62나6202', 'sales_channel' => 'export', 'remitter_name' => 'JANE ROE', 'remitter_id' => 'JOHNID999']);

        Volt::test('erp.vehicles.index')->set('search', 'john')
            ->assertSee($match->vehicle_number)
            ->assertDontSee($other->vehicle_number);   // ID 안의 JOHN 은 암호문이라 안 걸린다
    }

    public function test_panel_loads_and_saves_both_fields(): void
    {
        $this->login();
        $v = Vehicle::create(['vehicle_number' => '63다6303', 'sales_channel' => 'export', 'remitter_name' => 'OLD NAME', 'remitter_id' => 'OLD-ID']);

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->assertSet('remitter_name', 'OLD NAME')
            ->assertSet('remitter_id', 'OLD-ID')
            ->set('remitter_name', 'NEW NAME')
            ->set('remitter_id', 'NEW-ID-1')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame('NEW NAME', $v->remitter_name);
        $this->assertSame('NEW-ID-1', $v->remitter_id);
        $this->assertSame(AuditLog::MASKED_COLUMNS['remitter_id'], AuditLog::where('auditable_id', $v->id)->where('column_name', 'remitter_id')->latest('id')->value('new_value'));
        $this->assertSame(0, AuditLog::where('new_value', 'like', '%NEW-ID-1%')->count());
    }
}
