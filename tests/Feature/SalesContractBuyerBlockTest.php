<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\Mappings\SalesContractMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * jin 2026-07-10 — 판매계약서 바이어 블록(Passport/ID·Tel·Email·Address)을 ERP 바이어 데이터와 일치.
 *   + 바이어 수정탭 passport_id 입력칸.
 */
class SalesContractBuyerBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_buyer_form_saves_passport_id(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('erp.buyers.index')
            ->call('openCreate')
            ->set('name', 'TOKYO AUTO')
            ->set('passport_id', 'M12345678')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('buyers', ['name' => 'TOKYO AUTO', 'passport_id' => 'M12345678']);
    }

    public function test_sales_contract_buyer_block_uses_buyer_record(): void
    {
        $buyer = Buyer::create([
            'name' => 'TOKYO AUTO',
            'passport_id' => 'M12345678',
            'contact_phone' => '+81-3-1234',
            'contact_email' => 'buy@tokyo.jp',
            'address' => '1-2-3 Chuo, Tokyo',
            'is_active' => true,
        ]);

        $v = Vehicle::create([
            'vehicle_number' => 'SC-1',
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'dhl_request' => false, 'purchase_date' => '2026-06-01',
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyer->id,
        ]);

        $header = SalesContractMapping::config()['header'];

        // 좌표는 2026-07-29 레이아웃 개편으로 E66/E68/E69/E70 → P64/P66/P67/P68.
        $this->assertSame('TOKYO AUTO', $header['P64']($v));
        $this->assertSame('Passport/ID number : M12345678', $header['P66']($v));
        $this->assertStringContainsString('Tel: +81-3-1234', $header['P67']($v));
        $this->assertStringContainsString('Email: buy@tokyo.jp', $header['P67']($v));   // 구 ->email 버그였으면 빈값
        $this->assertSame('Address : 1-2-3 Chuo, Tokyo', $header['P68']($v));
    }
}
