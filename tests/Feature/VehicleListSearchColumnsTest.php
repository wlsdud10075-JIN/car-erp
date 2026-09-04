<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량목록 통합검색 — 「안내한 것은 실제로 찾혀야 한다」 (jin 2026-09-04).
 *
 * 배경: `bl_number` 가 검색 컬럼에서 빠져 있어 B/L 번호로는 차를 못 찾았다. 동시에 placeholder 는
 * **차대번호(끝 6자리)** 를 안내하고 있었는데 그건 이 칸이 아니라 **옆 VIN 전용 칸**의 기능이고,
 * 반대로 실제로 검색되는 구입처·운송장번호는 어디에도 안 적혀 있었다.
 * ⇒ 안내(placeholder·title)와 동작이 갈리면 사람은 「검색이 안 된다」고만 느낀다(SKILLS §8 #60).
 */
class VehicleListSearchColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(array $attrs): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false, 'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
        ], $attrs));
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    /** 통합검색이 안내대로 동작하는가 — 컬럼마다 고유 토큰을 심고 그 토큰으로 찾는다. */
    public function test_every_advertised_column_is_actually_searchable(): void
    {
        $this->actingAs($this->clearanceUser());

        $cases = [
            'vehicle_number' => ['11가1001', '11가1001'],
            'brand' => ['22나2002', 'ZZBRANDZZ'],
            'model_type' => ['33다3003', 'ZZMODELZZ'],
            'nice_reg_owner_name' => ['44라4004', 'ZZOWNERZZ'],
            'export_declaration_number' => ['55마5005', '41-01-24-ZZDECL'],
            'bl_number' => ['66바6006', 'KMTC-ZZBLZZ'],          // ← 이번에 추가한 컬럼
            'vessel_name' => ['77사7007', 'ZZVESSELZZ'],
            'container_number' => ['88아8008', '6.09_Z ZZCNTRZZ'],
            'purchase_from' => ['99자9009', 'ZZSUPPLIERZZ'],
        ];

        foreach ($cases as $column => [$number, $token]) {
            $this->vehicle(['vehicle_number' => $number] + ($column === 'vehicle_number' ? [] : [$column => $token]));
        }

        foreach ($cases as $column => [$number, $token]) {
            Volt::test('erp.vehicles.index')
                ->set('dateType', 'all')
                ->set('search', $token)
                ->call('applyFilters')
                ->assertSee($number);
        }
    }

    /** B/L 번호는 일괄 서류 업로드의 진입점이다 — 못 찾으면 그 기능 전체가 못 쓰인다. */
    public function test_bl_number_finds_the_whole_group(): void
    {
        $this->actingAs($this->clearanceUser());
        $this->vehicle(['vehicle_number' => '11가1001', 'bl_number' => 'KMTC-2409881']);
        $this->vehicle(['vehicle_number' => '22나2002', 'bl_number' => 'KMTC-2409881']);
        $this->vehicle(['vehicle_number' => '33다3003', 'bl_number' => 'HJSC-2410077']);

        Volt::test('erp.vehicles.index')
            ->set('dateType', 'all')
            ->set('search', 'KMTC-2409881')
            ->call('applyFilters')
            ->assertSee('11가1001')
            ->assertSee('22나2002')
            ->assertDontSee('33다3003');
    }

    /**
     * 안내 문구 정적 검사 — 차대번호는 이 칸이 아니라 옆 VIN 칸의 기능이다.
     * ⚠️ 이 부류는 기능 테스트로 못 잡는다(문구가 틀려도 화면은 정상 렌더된다).
     */
    public function test_placeholder_does_not_advertise_the_vin_box(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $ph = (string) __('vehicle.search_placeholder', [], $locale);
            $this->assertStringNotContainsString('차대번호', $ph, "[$locale] placeholder 가 VIN 칸 기능을 안내하고 있다");
            $this->assertStringNotContainsString('VIN', $ph, "[$locale] placeholder 가 VIN 칸 기능을 안내하고 있다");
            $this->assertNotSame('vehicle.search_title', (string) __('vehicle.search_title', [], $locale),
                "[$locale] search_title 번역 누락 — 호버 안내가 키 문자열로 찍힌다");
        }
    }
}
