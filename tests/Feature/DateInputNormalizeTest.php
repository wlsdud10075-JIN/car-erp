<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 날짜 1970 버그 방어 (jin 2026-07-20) — 20260717 처럼 8자리 숫자를 flatpickr 미포맷(Enter 없이) 저장 시
 *   Eloquent date 캐스트가 순수 숫자를 Unix 타임스탬프로 오인 → 1970 저장되던 문제.
 *   save() 의 $toDate 가 8자리 숫자를 Y-m-d 로 정규화하는지 검증(프론트 우회 안전망).
 */
class DateInputNormalizeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_eight_digit_purchase_date_normalized_not_1970(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'DT1', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '10,000,000')
            ->set('purchase_date', '20260717')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-07-17', $v->fresh()->purchase_date->format('Y-m-d'));
    }

    public function test_hyphenated_date_unchanged(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'DT2', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '5,000,000')
            ->set('purchase_date', '2026-07-17')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-07-17', $v->fresh()->purchase_date->format('Y-m-d'));
    }

    public function test_empty_date_saved_null(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'DT3', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '3,000,000')
            ->set('purchase_date', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($v->fresh()->purchase_date);
    }
}
