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
            ->assertDontSee('매입등록')
            ->assertDontSee('증빙유형');
    }

    public function test_karaba_shows_registration_cascade_hides_account(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('매입등록')               // 1단
            ->assertSee('매매상')                 // 매매상 체크박스
            ->assertDontSee('매입처 계좌 정보')   // 계좌란 숨김
            ->assertDontSee('매도비 계좌 정보')
            ->assertDontSee('송금메모')           // 메모 3→1: 송금메모 숨김
            ->assertSee('내부 메모');             // 공통 '메모(내부메모)' 만 남김

        // 1단 선택 시 2단(증빙유형) 캐스케이드 노출
        $c->set('purchase_registration_type', '일반매입')
            ->assertSee('증빙유형')
            ->assertSee('세금계산서');
    }

    public function test_karaba_registration_change_resets_invalid_subtype(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_registration_type', '일반매입')
            ->set('purchase_evidence_subtype', '세금계산서');

        // 구매대행(2단 없음)으로 변경 → 2단 자동 리셋
        $c->set('purchase_registration_type', '구매대행');
        $this->assertSame('', $c->get('purchase_evidence_subtype'));
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

    public function test_karaba_saves_cascade_and_dealer(): void
    {
        $this->karaba();
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_registration_type', '일반매입')
            ->set('purchase_evidence_subtype', '세금계산서')
            ->set('is_dealer_purchase', true)
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame('일반매입', $v->purchase_registration_type);
        $this->assertSame('세금계산서', $v->purchase_evidence_subtype);
        $this->assertTrue((bool) $v->is_dealer_purchase);
    }
}
