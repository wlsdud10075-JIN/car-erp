<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 판매탭 입금 단순화 (jin 2026-07-06 확정, 2026-07-08 구현) —
 * 계약금·중도금·선수금1 입력칸 제거(잔금으로 일원화). 레거시 값은 read-only 로만 표시, 미수계산·데이터 보존.
 */
class SalesTabPaymentSimplifyTest extends TestCase
{
    use RefreshDatabase;

    private function financeAdmin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_breakdown_inputs_removed_but_fee_kept(): void
    {
        $v = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export']);
        $this->actingAs($this->financeAdmin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertDontSeeHtml('wire:model="deposit_down_payment_str"')
            ->assertDontSeeHtml('wire:model="interim_payment_str"')
            ->assertDontSeeHtml('wire:model="advance_payment1_str"')
            ->assertSeeHtml('wire:model="fee_str"');   // 수수료는 유지
    }

    public function test_no_legacy_block_when_no_prior_breakdown(): void
    {
        $v = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export']);
        $this->actingAs($this->financeAdmin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertDontSee(__('vehicle.panel.legacy_breakdown'));
    }

    public function test_legacy_breakdown_shown_readonly_and_preserved_on_save(): void
    {
        $v = Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export']);
        $v->finalPayments()->create([
            'amount' => 500_000, 'type' => 'deposit_down', 'payment_date' => today(), 'confirmed_at' => now(),
        ]);
        $this->actingAs($this->financeAdmin());

        // 레거시 값 read-only 표시 (라벨 + 금액)
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee(__('vehicle.panel.legacy_breakdown'))
            ->assertSee('500,000');

        // 데이터는 그대로 보존 (숨김이 삭제가 아님)
        $this->assertSame(1, $v->finalPayments()->where('type', 'deposit_down')->count());
        $this->assertSame(500_000, (int) $v->finalPayments()->where('type', 'deposit_down')->sum('amount'));
    }
}
