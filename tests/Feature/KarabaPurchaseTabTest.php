<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * karaba 매입탭 커스터마이징 2단계 (2026-07-12) — 프로파일 게이팅 UI.
 *   - 구입처(자유텍스트) → 거래처구분(드롭박스) + 매입증빙(자유입력)
 *   - 계좌란 숨김 / 말소비 17,300 default / 요약 항목별 완납
 * heyman/ssancar(system) 은 기존 UI 유지 확인(회귀 방지).
 */
class KarabaPurchaseTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function karaba(): void
    {
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '12가3456',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 10000000,
            'selling_fee' => 440000,
            'dhl_request' => false,
        ]);
    }

    public function test_non_karaba_shows_purchase_from_not_partner_fields(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('구입처')
            ->assertDontSee('거래처구분')
            ->assertDontSee('매입증빙');
    }

    public function test_karaba_shows_partner_type_and_evidence_hides_account(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('거래처구분')
            ->assertSee('매입증빙')
            ->assertDontSee('매입처 계좌 정보')   // 계좌란 숨김
            ->assertDontSee('매도비 계좌 정보')
            ->assertDontSee('송금메모')           // 메모 3→1: 송금메모 숨김
            ->assertSee('내부 메모');             // 공통 '메모(내부메모)' 만 남김
    }

    public function test_karaba_open_create_defaults_deregistration_17300_others_zero(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')->call('openCreate');

        $this->assertSame('17,300', $c->get('cost_deregistration_str'));
        $this->assertSame('0', $c->get('cost_license_str'));
        $this->assertSame('0', $c->get('cost_towing_str'));
    }

    public function test_karaba_saves_partner_type_and_evidence(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_partner_type', '매매상')
            ->set('purchase_evidence_type', '계산서')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame('매매상', $v->purchase_partner_type);
        $this->assertSame('계산서', $v->purchase_evidence_type);
    }
}
