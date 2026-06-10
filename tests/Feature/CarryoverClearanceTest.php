<?php

namespace Tests\Feature;

use App\Models\CarryoverClearance;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 미청산 이월 청산(zero-out) — 퇴사자 정리 (2026-06-10).
 * 주의점 검증: ① 청산 후 잔액 0 ② 청산 후 새 정산이 재흡수 안 함(이중계상 차단) ③ 권한(canApprove만).
 */
class CarryoverClearanceTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** stranded carryover_out 를 가진 (흡수처 없는) 영업담당자. */
    private function strandedSalesman(int $carryoverOut): Salesman
    {
        $this->c++;
        $s = Salesman::create(['name' => '퇴사'.$this->c, 'type' => 'freelance', 'is_active' => false]);
        $v = Vehicle::create([
            'vehicle_number' => 'CC-'.$this->c, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false, 'salesman_id' => $s->id,
        ]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $s->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => $carryoverOut,
        ]);

        return $s;
    }

    public function test_clear_zeroes_positive_carryover(): void
    {
        $s = $this->strandedSalesman(320_000);
        $this->assertSame(320_000, $s->unconsumed_carryover);

        Volt::actingAs($this->admin())
            ->test('erp.salesmen.cashflow', ['id' => $s->id])
            ->call('clearCarryover');

        $this->assertSame(0, $s->fresh()->unconsumed_carryover, '청산 후 잔액 0 아님');
        $cl = CarryoverClearance::where('salesman_id', $s->id)->first();
        $this->assertNotNull($cl);
        $this->assertSame(320_000, $cl->amount_krw);
        $this->assertSame('pay', $cl->direction);
    }

    public function test_clear_zeroes_negative_carryover(): void
    {
        $s = $this->strandedSalesman(-150_000);
        $this->assertSame(-150_000, $s->unconsumed_carryover);

        Volt::actingAs($this->admin())
            ->test('erp.salesmen.cashflow', ['id' => $s->id])
            ->call('clearCarryover');

        $this->assertSame(0, $s->fresh()->unconsumed_carryover);
        $this->assertSame('collect', CarryoverClearance::where('salesman_id', $s->id)->first()->direction);
    }

    /** 핵심 주의점: 청산 후 새 정산이 생겨도 재흡수(이중계상) 안 됨. */
    public function test_after_clear_new_settlement_does_not_reabsorb(): void
    {
        $s = $this->strandedSalesman(320_000);
        Volt::actingAs($this->admin())
            ->test('erp.salesmen.cashflow', ['id' => $s->id])
            ->call('clearCarryover');
        $this->assertSame(0, $s->fresh()->unconsumed_carryover);

        // 청산 후 같은 담당자 새 정산 — creating 훅이 Σ청산액 차감 → carryover_in 흡수 안 함.
        $v = Vehicle::create([
            'vehicle_number' => 'CC-after-'.$s->id, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false, 'salesman_id' => $s->id,
        ]);
        $new = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $s->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending',
        ]);

        $this->assertNull($new->carryover_in_krw, '청산된 이월을 새 정산이 재흡수함(이중계상)');
        $this->assertSame(0, $s->fresh()->unconsumed_carryover, '청산 후 잔액이 다시 생김');
    }

    public function test_sales_user_cannot_clear_own(): void
    {
        $s = $this->strandedSalesman(320_000);
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $s->update(['user_id' => $salesUser->id]);

        // 영업 본인은 자기 cashflow 열람은 되지만 청산은 canApprove 아니라 403.
        Volt::actingAs($salesUser)
            ->test('erp.salesmen.cashflow', ['id' => $s->id])
            ->call('clearCarryover')
            ->assertStatus(403);

        $this->assertSame(320_000, $s->fresh()->unconsumed_carryover, '영업이 청산해버림');
        $this->assertSame(0, CarryoverClearance::where('salesman_id', $s->id)->count());
    }

    public function test_clear_when_zero_is_noop(): void
    {
        $s = Salesman::create(['name' => '제로', 'type' => 'freelance']);
        Volt::actingAs($this->admin())
            ->test('erp.salesmen.cashflow', ['id' => $s->id])
            ->call('clearCarryover');

        $this->assertSame(0, CarryoverClearance::where('salesman_id', $s->id)->count(), '0인데 청산 기록 생성됨');
    }
}
