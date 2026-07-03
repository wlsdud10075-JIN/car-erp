<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
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

    public function test_sync_command_fixes_unlocked_and_skips_confirmed_locked(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true, 'country_id' => null]);

        // 미잠금 — 면장이 총판매가와 다름(수기 sale_price 값)
        $unlocked = Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 500, 'export_declaration_amount' => 10000,
        ]);

        // 잠금 — confirmed 잔금 존재 → 완료 차량으로 스킵돼야
        $locked = Vehicle::create([
            'vehicle_number' => '44라4444', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 20000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 1000, 'export_declaration_amount' => 20000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $locked->id, 'amount' => 1, 'payment_date' => '2026-06-01',
            'confirmed_at' => now(), 'type' => 'balance',
        ]);

        $this->artisan('vehicles:sync-declaration-amount --apply')->assertSuccessful();

        $this->assertSame(10500, (int) $unlocked->fresh()->export_declaration_amount);   // 미잠금 보정
        $this->assertSame(20000, (int) $locked->fresh()->export_declaration_amount);     // 완료 차량 그대로
    }
}
