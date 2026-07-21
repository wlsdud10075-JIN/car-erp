<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 미래 날짜 확인 모달 (jin 2026-07-21) — 매입일/판매일이 오늘보다 미래면 저장 전 확인 모달.
 *   금액 적용 시점(미수·미지급·정산)이 날짜에 좌우돼 연도 오타 등 오입력 방지.
 */
class FutureDateModalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_future_purchase_date_shows_modal_then_confirm_saves(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'FD1', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());
        $future = now()->addMonths(2)->format('Y-m-d');

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '10,000,000')
            ->set('purchase_date', $future)
            ->call('save');

        // 미래 날짜 → 모달 뜨고 저장 보류
        $c->assertSet('showFutureDateModal', true);
        $this->assertNull($v->fresh()->purchase_date, '확인 전엔 저장 안 됨');

        // "맞습니다, 저장" → 저장 진행
        $c->call('confirmSaveWithFutureDates')->assertSet('showFutureDateModal', false);
        $this->assertSame($future, $v->fresh()->purchase_date->format('Y-m-d'));
    }

    public function test_past_date_no_modal_saves_directly(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'FD2', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());
        $past = now()->subMonth()->format('Y-m-d');

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '5,000,000')
            ->set('purchase_date', $past)
            ->call('save')
            ->assertSet('showFutureDateModal', false)
            ->assertHasNoErrors();

        $this->assertSame($past, $v->fresh()->purchase_date->format('Y-m-d'), '과거 날짜는 모달 없이 바로 저장');
    }

    public function test_dismiss_does_not_save(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'FD3', 'sales_channel' => 'export']);
        $this->actingAs($this->admin());
        $future = now()->addYear()->format('Y-m-d');

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '3,000,000')
            ->set('purchase_date', $future)
            ->call('save')
            ->assertSet('showFutureDateModal', true)
            ->call('dismissFutureDateModal')
            ->assertSet('showFutureDateModal', false);

        $this->assertNull($v->fresh()->purchase_date, '수정하러 가기 → 저장 안 됨');
    }
}
