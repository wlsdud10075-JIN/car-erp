<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Port;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * item 2 (jin 2026-07-18) — 선적대기 허용 항로 가드1.
 * shipping_method=RORO + 도착항 마스터 allow_shipping_wait 플래그면 C5(50%) 진입게이트 우회 없이 통과.
 * 아니면 가드2(기존 C5 50%) 발동. 하드코딩 대신 항구 마스터 데이터로 지정.
 */
class PortShippingWaitGateTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function port(bool $allow): Port
    {
        return Port::create([
            'type' => 'discharge', 'name' => 'DURRES'.++$this->c,
            'is_active' => true, 'allow_shipping_wait' => $allow,
        ]);
    }

    /** 미완납(미수율 100% > 50%) + 통관 진입(shipping_date) 트리거 차량 — C5 대상. */
    private function gatedVehicle(array $overrides): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.++$this->c, 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => 'PW'.$this->c, 'sales_channel' => 'export',
            'sale_price' => 10_000_000, 'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id,
            'currency' => 'KRW', 'exchange_rate' => 1,
            'is_deregistered' => true, 'deregistration_document' => 'x.pdf',
            'shipping_date' => '2026-06-10',   // 통관·선적 진입 트리거
        ], $overrides));
    }

    public function test_roro_to_flagged_port_bypasses_c5(): void
    {
        $port = $this->port(true);
        $v = $this->gatedVehicle(['shipping_method' => 'RORO', 'discharge_port_id' => $port->id]);

        $v->guardStageOrderForExport();   // 미수율 100%여도 통과 — 예외 없어야
        $this->assertTrue(true, 'RORO + 선적대기 허용 항로 → C5 우회 통과');
    }

    public function test_container_to_flagged_port_still_gated(): void
    {
        $port = $this->port(true);
        $v = $this->gatedVehicle(['shipping_method' => 'CONTAINER', 'discharge_port_id' => $port->id]);

        $this->expectException(ValidationException::class);
        $v->guardStageOrderForExport();   // CONTAINER 은 가드1 미해당 → C5 발동
    }

    public function test_roro_to_unflagged_port_still_gated(): void
    {
        $port = $this->port(false);
        $v = $this->gatedVehicle(['shipping_method' => 'RORO', 'discharge_port_id' => $port->id]);

        $this->expectException(ValidationException::class);
        $v->guardStageOrderForExport();   // 플래그 없는 항로 → C5 발동
    }

    public function test_roro_without_discharge_port_still_gated(): void
    {
        $v = $this->gatedVehicle(['shipping_method' => 'RORO', 'discharge_port_id' => null]);

        $this->expectException(ValidationException::class);
        $v->guardStageOrderForExport();   // 도착항 미지정 → C5 발동
    }
}
