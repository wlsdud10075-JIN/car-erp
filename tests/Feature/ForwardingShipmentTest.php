<?php

namespace Tests\Feature;

use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 7 (jin 2026-07-07) — 포워딩사별 선적 현황 (운임비 통화별 합산 + 기간 + 검색).
 */
class ForwardingShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function shipVehicle(int $fcId, array $attr = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '77가'.rand(1000, 9999),
            'sales_channel' => 'export',
            'forwarding_company_id' => $fcId,
            'shipping_date' => '2026-05-10',
        ], $attr));
    }

    public function test_fees_summed_per_currency(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD ALPHA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 500]);
        $this->shipVehicle($fc->id, ['currency' => 'JPY', 'transport_fee' => 2000]);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->assertSee('FWD ALPHA')
            ->assertSee('USD 1,500')   // 1000 + 500
            ->assertSee('JPY 2,000');  // 통화별 분리 합산
    }

    public function test_search_by_vessel_filters_shipments(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD BETA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000, 'vessel_name' => 'EVER GIVEN']);
        $this->shipVehicle($fc->id, ['currency' => 'JPY', 'transport_fee' => 9000, 'vessel_name' => 'OTHER SHIP']);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->set('search', 'EVER GIVEN')
            ->call('searchNow')
            ->assertSee('USD 1,000')
            ->assertDontSee('JPY 9,000');   // 검색 밖 차량은 합산 제외
    }

    public function test_date_range_filters_shipments(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD GAMMA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000, 'shipping_date' => '2026-05-15']);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 7000, 'shipping_date' => '2026-03-01']);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->assertSee('USD 1,000')
            ->assertDontSee('7,000');   // 5월 밖 선적 제외
    }

    /**
     * 포워딩사 펼침(컨테이너/RORO) 안의 차량 행에 차대번호가 보인다 (jin 2026-08-05).
     * ⚠️ 목록 쿼리가 select 를 컬럼 지정으로 걸고 있어 nice_reg_vin 을 빼먹으면
     * 예외 없이 그냥 안 보인다 — 화면 문자열로 검증한다.
     */
    public function test_shipment_rows_show_chassis_number(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD VIN', 'is_active' => true]);
        $this->shipVehicle($fc->id, [
            'vehicle_number' => '19더9065',
            'nice_reg_vin' => 'KMHFWDVIN0000001',
            'currency' => 'USD',
            'transport_fee' => 700,
        ]);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->assertSee('19더9065')
            ->assertSee('KMHFWDVIN0000001');
    }

    /**
     * 헤더 금액은 **미청산 잔금만** 보여준다 (jin 2026-08-05).
     * "지급해서 0원으로 털면 청산 안 한 금액만 남는다" — 청산한 묶음은 통째로 빠져야 한다.
     */
    public function test_header_total_shows_only_unsettled_freight(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD SETTLE', 'is_active' => true]);
        // 컨테이너 2묶음 — 하나만 청산한다.
        $this->shipVehicle($fc->id, ['container_number' => 'CONT-A', 'currency' => 'USD', 'transport_fee' => 1000]);
        $this->shipVehicle($fc->id, ['container_number' => 'CONT-B', 'currency' => 'USD', 'transport_fee' => 300]);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')->assertSee('USD 1,300');   // 청산 전 = 전액

        ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id,
            'group_type' => 'container', 'group_key' => 'CONT-A',
            'currency' => 'USD', 'amount' => 1000, 'paid_at' => now(),
        ]);

        Volt::test('erp.forwarding-companies.index')
            ->assertSee('USD 300')            // 남은 잔금만
            ->assertDontSee('USD 1,300');
    }

    /** 전액 청산하면 금액이 사라지는 대신 「운임 청산완료」로 구분된다(운임비 없음과 혼동 방지). */
    public function test_fully_settled_company_shows_settled_label(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD DONE', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['container_number' => 'CONT-C', 'currency' => 'USD', 'transport_fee' => 500]);

        ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id,
            'group_type' => 'container', 'group_key' => 'CONT-C',
            'currency' => 'USD', 'amount' => 500, 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin());

        // 헤더에 잔금 뱃지 대신 「운임 청산완료」가 뜬다.
        //   ⚠️ assertDontSee('USD 500') 은 못 쓴다 — 같은 금액이 아래 청산 기록 상세에도 렌더된다
        //   (아코디언이 접혀 있어도 Alpine x-show 라 DOM 에는 있다).
        Volt::test('erp.forwarding-companies.index')
            ->assertSee(__('forwarding.fee_all_settled'));
    }

    /** 묶음 키가 없는(미분류) 선적은 아직 청산할 수 없으므로 잔금에 남아야 한다. */
    public function test_ungrouped_shipment_stays_in_outstanding(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD NOKEY', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 700]);   // 컨테이너·선박·신고번호 없음

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')->assertSee('USD 700');
    }
}
