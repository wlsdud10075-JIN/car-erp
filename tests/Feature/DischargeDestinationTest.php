<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Country;
use App\Models\Port;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-06-24 — 선적 인보이스 Final Destination(E16) = 입력 목적항(영문) 우선.
 *
 * 버그(jin, 07우5459): RORO Final Destination 에 바이어 국가 한글명("코소보")이 들어감.
 *   수출 영문서류라 jin 이 입력한 목적항(discharge_port "DURRESS, ALBANIA")이 나와야 함.
 */
class DischargeDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVehicle(?int $portId): Vehicle
    {
        $country = Country::create(['name' => '코소보', 'code' => 'XKX', 'currency' => 'EUR']);
        $buyer = Buyer::create(['name' => 'BUYER', 'country_id' => $country->id]);

        return Vehicle::create([
            'vehicle_number' => 'DD-'.($portId ?? 'none'),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1707,
            'sale_price' => 4000, 'sale_date' => '2026-06-01', 'purchase_date' => '2026-05-01',
            'buyer_id' => $buyer->id, 'dhl_request' => false,
            'port_of_loading' => 'INCHEON, KOREA', 'discharge_port_id' => $portId,
            'brand' => 'HYUNDAI', 'model_type' => 'TUCSON',
        ])->fresh();
    }

    public function test_helper_prefers_english_discharge_port(): void
    {
        $port = Port::create(['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'is_active' => true]);
        $v = $this->makeVehicle($port->id);

        $this->assertSame('DURRESS, ALBANIA', DocValue::dischargeDestination($v));
    }

    public function test_helper_falls_back_to_country_without_port(): void
    {
        $v = $this->makeVehicle(null);

        // 목적항 없으면 기존 동작(목적국명) 유지 — 빈칸 방지.
        $this->assertSame('코소보', DocValue::dischargeDestination($v));
    }

    public function test_roro_invoice_e16_uses_discharge_port(): void
    {
        $port = Port::create(['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'is_active' => true]);
        $v = $this->makeVehicle($port->id);

        $ss = (new DocumentFiller(collect([$v])))->spreadsheet('roro_invoice_packing');
        $sheet = $ss->getSheetByName('INVOICE');

        $this->assertSame('DURRESS, ALBANIA', (string) $sheet->getCell('E16')->getValue());
        // G12 운송방식 라벨 = "RORO" (컨테이너 양식 "CONTAINER" 대응).
        $this->assertSame('RORO', (string) $sheet->getCell('G12')->getValue());
    }

    public function test_clearance_d10_uses_discharge_port(): void
    {
        $port = Port::create(['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'is_active' => true]);
        $v = $this->makeVehicle($port->id);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');
        $sheet = $ss->getSheetByName('구매리스트');

        $this->assertSame('DURRESS, ALBANIA', (string) $sheet->getCell('D10')->getValue());
    }
}
