<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A-3 (jin 2026-07-08) — 판매완료(완납) 시 정산 자동 생성 + 귀속월(attributed_month) 고정.
 * 트리거 = FinalPayment::saved(완납 감지). auth 가드로 시드·artisan 대량유입 차단. 재귀속 금지.
 */
class A3SettlementTriggerTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function soldVehicle(int $price = 10_000_000): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'S'.$this->c, 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create([
            'vehicle_number' => 'A3'.$this->c, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => $price,
            'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    public function test_full_payment_creates_settlement_with_attributed_month(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $this->assertSame(0, Settlement::count(), '미완납 → 정산 없음');

        // 완납(6/15 잔금) → 트리거
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);

        $this->assertSame(1, Settlement::count());
        $s = Settlement::first();
        $this->assertSame('pending', $s->settlement_status);
        $this->assertSame('2026-06-01', $s->attributed_month->format('Y-m-d'), '완납월(6월) 1일');
    }

    public function test_no_auth_no_auto_create(): void
    {
        // 로그인 없음(시드·artisan 시뮬) → 트리거 안 함 (대량 유입 차단)
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
        $this->assertSame(0, Settlement::count());
    }

    public function test_no_reattribution_once_created(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
        $this->assertSame(1, Settlement::count());

        // 추가 입금 저장·거래완료 진입해도 재생성 안 됨 (재귀속 금지)
        $v->finalPayments()->create([
            'amount' => 1, 'type' => 'fee', 'payment_date' => '2026-07-01', 'confirmed_at' => now(),
        ]);
        $v->refresh()->createSettlementIfComplete('중복시도');
        $this->assertSame(1, Settlement::count());
    }

    public function test_partial_payment_no_settlement(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 3_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
        $this->assertSame(0, Settlement::count(), '부분입금 → 미완납 → 정산 없음');
    }

    public function test_cancelled_vehicle_no_auto_settlement_even_when_paid(): void
    {
        // 매입취소 차량은 위약금 완납(KRW·인코텀즈 무관)해도 정산 자동생성 안 됨 (jin 2026-07-18 명시 가드).
        $this->actingAs($this->admin());
        $v = $this->soldVehicle(600_000);
        $v->update(['cancel_status' => Vehicle::CANCEL_ACTIVE]);
        $v->finalPayments()->create([
            'amount' => 600_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
        $this->assertSame(0, Settlement::count(), '매입취소 차량 완납 → 정산 없음(가드)');

        // 대조군 — 동일 조건 정상차(KRW)는 정산 생성됨.
        //   KRW 는 freight 게이트를 통과하므로, 취소차를 막은 유일한 요인이 cancel_status 임을 입증.
        $v2 = $this->soldVehicle(600_000);
        $v2->finalPayments()->create([
            'amount' => 600_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
        $this->assertSame(1, Settlement::count(), '정상차(KRW) 완납 → 정산 생성 — 취소차만 가드로 차단');
    }

    public function test_submit_for_month_uses_attributed_month(): void
    {
        $salesman = Salesman::create(['name' => 'SM', 'is_active' => true, 'type' => 'employee']);
        // 6월 귀속(attributed_month) confirmed 2건 — confirmed_at 은 7월(범위 밖)이라도 attributed_month 로 잡혀야
        foreach (['A3x1', 'A3x2'] as $vn) {
            $v = Vehicle::create(['vehicle_number' => $vn, 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
            Settlement::create([
                'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
                'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
                'settlement_status' => 'confirmed', 'confirmed_at' => '2026-07-25',   // 범위 밖(구 앵커면 7월)
                'attributed_month' => '2026-06-01',
            ]);
        }
        $gwanri = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);

        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-06');
        $this->assertSame(2, $batch->settlement_count, 'attributed_month=6월 이면 6월 배치로 잡힘');
    }

    public function test_backfill_command_preserves_current_month(): void
    {
        $salesman = Salesman::create(['name' => 'SB', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => 'A3bf', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-07-02',   // payrollMonthOf → 2026-06
        ]);
        $this->assertNull($s->attributed_month);

        $this->artisan('settlements:backfill-attributed-month --apply')->assertSuccessful();

        $this->assertSame('2026-06-01', $s->fresh()->attributed_month->format('Y-m-d'), '현재 귀속월(6월) 보존');
    }
}
