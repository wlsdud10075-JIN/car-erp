<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 차량 등록번호 → 통관 SET 구매리스트 D3 기입 (2026-06-16).
 * D3 은 말소증 "제 [등록번호] 호" 로 cascade(=구매리스트!D3) 되는 칸.
 */
class ClearanceRegistrationNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_number_fills_clearance_d3(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-1', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'registration_number' => '2024-12345',
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');
        $cell = $ss->getSheetByName('구매리스트')->getCell('D3')->getValue();

        $this->assertSame('2024-12345', (string) $cell);
    }

    public function test_blank_registration_number_leaves_d3_empty(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-2', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');
        $cell = $ss->getSheetByName('구매리스트')->getCell('D3')->getValue();

        $this->assertSame('', (string) $cell);
    }
}
