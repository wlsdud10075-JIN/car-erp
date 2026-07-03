<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 면장금액(export_declaration_amount) 미입력 시 총판매가(sale_total_amount) 자동 복사.
 * (2026-07-03 jin — 구 sale_price 에서 부대비용 포함 총판매가로 교체.)
 */
class DeclarationAmountAutofillTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_declaration_autofills_sale_total_amount_not_sale_price(): void
    {
        $buyer = Buyer::create(['name' => '바이어', 'is_active' => true, 'country_id' => null]);

        $v = Vehicle::create([
            'vehicle_number' => '11가1111',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1300,
            'sale_price' => 10000,
            'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id,
            'transport_fee' => 500,
            'commission' => 200,
            'auto_loading' => 100,
            'tax_dc' => 300,
        ]);

        // 총판매가 = 10000 + 500 + 0(기타) + 200 + 100 - 300 = 10500 (sale_price 10000 이 아니라)
        $this->assertSame(10500, (int) $v->fresh()->export_declaration_amount);
    }

    public function test_explicit_declaration_amount_is_preserved(): void
    {
        $buyer = Buyer::create(['name' => '바이어2', 'is_active' => true, 'country_id' => null]);

        $v = Vehicle::create([
            'vehicle_number' => '22나2222',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1300,
            'sale_price' => 10000,
            'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id,
            'transport_fee' => 500,
            'export_declaration_amount' => 9999,   // 명시 입력 — CIF/FOB 등, 자동 덮어쓰기 안 함
        ]);

        $this->assertSame(9999, (int) $v->fresh()->export_declaration_amount);
    }
}
