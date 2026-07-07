<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 4 회귀 (jin 2026-07-07) — 매입일/판매일 today() 자동 기본값.
 *
 * 규칙: 신규 저장 + "해당 가격 있을 때만".
 *   - 매입가>0 인데 매입일 비면 → today()
 *   - 판매가>0 인데 판매일 비면 → today() (chk_sale_required 검증 前 주입되어 통과)
 *   - 가격 없으면 날짜 안 채움 (가격 없는 신규는 오염 방지)
 *   - 명시 입력값은 보존. 편집·import(isNew 아님)는 불침범.
 */
class VehicleAutoDateDefaultTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function manager(): User
    {
        return User::factory()->create([
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function today(): string
    {
        return now()->format('Y-m-d');
    }

    public function test_purchase_price_autofills_purchase_date_today(): void
    {
        $this->actingAs($this->manager());
        $number = 'AD-'.++$this->counter;

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->set('purchase_price_str', '10,000,000')
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', $number)->firstOrFail();
        $this->assertSame($this->today(), $v->purchase_date?->format('Y-m-d'), '매입가 있으면 매입일 오늘로 자동');
    }

    public function test_no_purchase_price_leaves_purchase_date_null(): void
    {
        $this->actingAs($this->manager());
        $number = 'AD-'.++$this->counter;

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', $number)->firstOrFail();
        $this->assertNull($v->purchase_date, '매입가 없으면 매입일 안 채움');
    }

    public function test_sale_price_autofills_sale_date_today(): void
    {
        $salesman = Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'AD BUYER', 'is_active' => true]);
        $this->actingAs($this->manager());
        $number = 'AD-'.++$this->counter;

        // 판매가 입력 + 판매일 비움 → chk_sale_required(판매일 required) 前에 today() 주입되어 통과.
        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->set('currency', 'KRW')
            ->set('exchange_rate_str', '1')
            ->set('salesman_id_str', (string) $salesman->id)
            ->set('purchase_price_str', '5,000,000')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('sale_price_str', '5,000,000')
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', $number)->firstOrFail();
        $this->assertSame($this->today(), $v->sale_date?->format('Y-m-d'), '판매가 있으면 판매일 오늘로 자동');
        $this->assertSame($this->today(), $v->purchase_date?->format('Y-m-d'), '매입가도 있으니 매입일도 오늘');
    }

    public function test_explicit_dates_are_preserved(): void
    {
        $buyer = Buyer::create(['name' => 'AD BUYER2', 'is_active' => true]);
        $this->actingAs($this->manager());
        $number = 'AD-'.++$this->counter;

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->set('currency', 'KRW')
            ->set('exchange_rate_str', '1')
            ->set('purchase_price_str', '5,000,000')
            ->set('purchase_date', '2026-01-10')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('sale_price_str', '5,000,000')
            ->set('sale_date', '2026-02-20')
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', $number)->firstOrFail();
        $this->assertSame('2026-01-10', $v->purchase_date?->format('Y-m-d'), '명시 매입일 보존');
        $this->assertSame('2026-02-20', $v->sale_date?->format('Y-m-d'), '명시 판매일 보존');
    }
}
