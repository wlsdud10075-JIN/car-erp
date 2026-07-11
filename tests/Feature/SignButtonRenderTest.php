<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** N대 선택 액션바에 「전자서명 요청」 버튼(라벨 + wire:click)이 실제 렌더되는지 확정. */
class SignButtonRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function exportVehicle(int $buyerId, string $vn): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyerId, 'purchase_date' => '2026-06-01',
        ]);
    }

    public function test_action_bar_renders_esign_button(): void
    {
        App::setLocale('ko');
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $v1 = $this->exportVehicle($buyer->id, 'A1');
        $v2 = $this->exportVehicle($buyer->id, 'A2');

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v1->id, $v2->id])
            ->assertSeeHtml('wire:click="requestSignature"')
            ->assertSee('전자서명 요청');
    }
}
