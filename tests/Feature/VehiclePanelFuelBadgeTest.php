<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 🔋 차량 편집 패널 머리 — 하이브리드·전기만 차대번호 옆에 연료 뱃지 (jin 2026-11-18). 경유·휘발유는 안 띄운다. */
class VehiclePanelFuelBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function panelHtml(?string $fuel): string
    {
        $sm = Salesman::create(['name' => 'S', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가'.random_int(1000, 9999), 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'KMHTEST0000000001', 'nice_reg_fuel_type' => $fuel,
        ]);
        $this->actingAs(User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]));

        return Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
    }

    public function test_hybrid_and_electric_show_the_badge(): void
    {
        $this->assertMatchesRegularExpression('/data-fuel-badge[^>]*>\s*하이브리드\(경유\+전기\)\s*</u', $this->panelHtml('하이브리드(경유+전기)'));
        $this->assertMatchesRegularExpression('/data-fuel-badge[^>]*bg-blue-100[^>]*>\s*전기\s*</u', $this->panelHtml('전기'));
    }

    public function test_diesel_and_empty_show_nothing(): void
    {
        $this->assertStringNotContainsString('data-fuel-badge', $this->panelHtml('경유'));
        $this->assertStringNotContainsString('data-fuel-badge', $this->panelHtml(null));
    }
}
