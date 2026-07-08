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

    public function test_declaration_follows_sale_total_when_previously_autofilled(): void
    {
        $buyer = Buyer::create(['name' => '바이어3', 'is_active' => true, 'country_id' => null]);

        $v = Vehicle::create([
            'vehicle_number' => '77사7777', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 500,
        ]);
        $this->assertSame(10500, (int) $v->fresh()->export_declaration_amount);  // 자동복사

        // 총판매가 변경(sale_price 12000, 면장 직접 안 건드림) → 면장이 추종해야
        $v = $v->fresh();
        $v->sale_price = 12000;
        $v->save();

        $this->assertSame(12500, (int) $v->fresh()->export_declaration_amount);  // 12000 + 500
    }

    public function test_manual_declaration_not_overwritten_when_sale_total_changes(): void
    {
        $buyer = Buyer::create(['name' => '바이어4', 'is_active' => true, 'country_id' => null]);

        $v = Vehicle::create([
            'vehicle_number' => '88아8888', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 500, 'export_declaration_amount' => 9999,
        ]);
        $this->assertSame(9999, (int) $v->fresh()->export_declaration_amount);   // 수동값(총판매가 10500과 다름)

        // 총판매가 변경돼도 수동 면장은 보존
        $v = $v->fresh();
        $v->sale_price = 12000;
        $v->save();

        $this->assertSame(9999, (int) $v->fresh()->export_declaration_amount);
    }

    public function test_sync_command_fixes_unlocked_and_safe_locked_but_skips_suspect(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true, 'country_id' => null]);

        // 미잠금 — 면장이 총판매가와 다름 → 보정
        $unlocked = Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 500, 'export_declaration_amount' => 10000,
        ]);
        // 총판매가 10500

        // 완료-safe — 면장=sale_price(구 자동복사, 수동 아님) → 보정
        $lockedSafe = Vehicle::create([
            'vehicle_number' => '55마5555', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 20000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 1000, 'export_declaration_amount' => 20000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $lockedSafe->id, 'amount' => 1, 'payment_date' => '2026-06-01',
            'confirmed_at' => now(), 'type' => 'balance',
        ]);
        // 총판매가 21000, 면장 20000(=sale_price) → safe

        // 완료-suspect — 면장이 제3의 값(수동수정 의심) → 스킵
        $lockedSuspect = Vehicle::create([
            'vehicle_number' => '66바6666', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_price' => 30000, 'sale_date' => '2026-06-01',
            'buyer_id' => $buyer->id, 'transport_fee' => 1500, 'export_declaration_amount' => 99999,
        ]);
        FinalPayment::create([
            'vehicle_id' => $lockedSuspect->id, 'amount' => 1, 'payment_date' => '2026-06-01',
            'confirmed_at' => now(), 'type' => 'balance',
        ]);
        // 총판매가 31500, 면장 99999(제3값) → suspect

        $this->artisan('vehicles:sync-declaration-amount --apply')->assertSuccessful();

        $this->assertSame(10500, (int) $unlocked->fresh()->export_declaration_amount);      // 미잠금 보정
        $this->assertSame(21000, (int) $lockedSafe->fresh()->export_declaration_amount);    // 완료-safe 보정
        $this->assertSame(99999, (int) $lockedSuspect->fresh()->export_declaration_amount); // 완료-suspect 스킵
    }
}
