<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 수출 진입 게이트는 **화면에 방금 넣은 환율**을 봐야 한다 (jin 2026-11-18, ssancarerp 214나4709·09나3647·02도2613).
 *
 * 실사고 — 선적일이 일괄로 먼저 찍힌(게이트 발동 상태) 외화 차에, 판매가·환율을 **한 번에** 넣고 저장하자
 * 「환율 미입력 외화 차량은 수출 진입 불가」가 떴다. 저장 전 검사용 임시 차량(previewVehicle)이 판매가만 form 값으로
 * 바꾸고 **환율·통화는 DB 옛 값(0)** 을 들고 있었다. 우회 승인을 받자 저장됐고 환율도 정상 저장됐다.
 *
 * ⚠️ 고친 뒤에도 입금 0 이면 **입금률 게이트**(60% 등)에는 걸린다 — 그건 규칙이다. 메시지가 맞는 이유로 바뀌는 것이 고침이다.
 */
class ExportGateUsesFormRateTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): array
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'FX GATE BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '214나4709', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 0,
            'salesman_id' => $sm->id,
            'buyer_id' => $buyer->id, 'export_buyer_id' => $buyer->id, 'bl_buyer_id' => $buyer->id,
            'purchase_price' => 10_000_000, 'selling_fee' => 440_000, 'purchase_date' => '2026-11-13',
            'sale_date' => '2026-11-13', 'is_deregistered' => true, 'nice_reg_owner_rrn' => '110111-1234567',
            'shipping_date' => '2026-11-21',   // 선적일 일괄 지정으로 먼저 찍힘 → 게이트 발동
        ]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 10_440_000, 'payment_date' => '2026-11-13', 'confirmed_at' => now(),
        ]);
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $admin];
    }

    public function test_rate_typed_in_the_same_save_is_not_reported_as_missing(): void
    {
        [$v, $admin] = $this->vehicle();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '20177')
            ->set('exchange_rate_str', '1473')
            ->call('save');

        $shown = implode(' / ', $c->errors()->all());
        $this->assertStringNotContainsString('환율 미입력', $shown, '방금 넣은 환율을 게이트가 못 봤다');
        $this->assertStringContainsString('입금률', $shown, '입금 0 이면 입금률 게이트에는 걸려야 한다(규칙)');
    }

    public function test_with_override_the_sale_and_rate_save_together(): void
    {
        [$v, $admin] = $this->vehicle();
        $this->actingAs($admin);
        UnpaidExportOverride::create([
            'vehicle_id' => $v->id, 'stage' => 'shipping', 'approved_by' => $admin->id,
            'reason' => '긴급 출항 선적 요청으로 인한 면장 진행', 'approved_at' => now(),
            'ip_address' => '127.0.0.1', 'sale_unpaid_amount_snapshot' => 0,
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '20177')
            ->set('exchange_rate_str', '1473')
            ->set('userConfirmedFutureDates', true)        // 선적일(미래) 확인 모달
            ->set('userConfirmedDocCheckMismatch', true)   // 말소 체크 ↔ 서류 없음 확인 모달
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1473.0, (float) $v->fresh()->exchange_rate);
    }
}
