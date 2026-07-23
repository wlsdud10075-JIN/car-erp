<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 보증금 매입 마커 (2026-07-23, jin) — 재무가 C2 보증금 매입 선지급을 확정하는 시점에
 *   대상 차 is_deposit_purchase 도장 자동 set. 뱃지 상태(deposit_purchase_state)는
 *   판매완료(미수 0)=paid(초록) / 미완납=waiting(주황). 게이트와 무관, 표시 전용.
 */
class DepositPurchaseMarkerTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /** 소스 S1($100k 완납) + 대상 T3(매입만) + 기안/관리/재무 3인 — PurchaseFundingTest 동일 셋업. */
    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $finance = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $source = Vehicle::create([
            'vehicle_number' => 'S1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $source->finalPayments()->create([
            'amount' => 100_000, 'type' => 'balance', 'payment_date' => '2026-05-02',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => 'T3', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-10', 'purchase_price' => 30_000_000,
        ]);

        return compact('buyer', 'drafter', 'manager', 'finance', 'source', 'target');
    }

    public function test_flag_not_set_before_finance_confirm(): void
    {
        ['drafter' => $drafter, 'manager' => $manager, 'source' => $source, 'target' => $target] = $this->ctx();

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter);
        $this->service->approvePurchaseFunding($t, $manager);

        // 관리 승인까지만 — 아직 도장 없음
        $this->assertFalse((bool) $target->fresh()->is_deposit_purchase);
        $this->assertNull($target->fresh()->deposit_purchase_state);
    }

    public function test_finance_confirm_stamps_marker_waiting_when_no_sale(): void
    {
        ['drafter' => $drafter, 'manager' => $manager, 'finance' => $finance,
            'source' => $source, 'target' => $target] = $this->ctx();

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter);
        $this->service->approvePurchaseFunding($t, $manager);
        $this->service->confirmPurchaseFundingByFinance($t, $finance, '은행이체 완료');

        $target->refresh();
        $this->assertTrue((bool) $target->is_deposit_purchase, '재무 확정 시 도장 자동 set');
        $this->assertNotNull($target->deposit_purchase_at, '도장 일시(알림 타이머 기산점)도 기록');
        // 판매 미입력(sale_price=0) → 완납 아님 → 대기(주황)
        $this->assertSame('waiting', $target->deposit_purchase_state);
    }

    public function test_marker_turns_paid_when_sale_fully_paid(): void
    {
        ['buyer' => $buyer, 'drafter' => $drafter, 'manager' => $manager, 'finance' => $finance,
            'source' => $source, 'target' => $target] = $this->ctx();

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter);
        $this->service->approvePurchaseFunding($t, $manager);
        $this->service->confirmPurchaseFundingByFinance($t, $finance);

        // 대상 차에 판매 등록 + 완납 (바이어가 자기 돈으로 판매금 입금)
        $target->update([
            'sale_date' => '2026-06-01', 'sale_price' => 50_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $target->finalPayments()->create([
            'amount' => 50_000, 'type' => 'balance', 'payment_date' => '2026-06-02',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);
        $target->refresh();

        $this->assertLessThanOrEqual(0, $target->sale_unpaid_amount, '판매완료(미수 0)');
        $this->assertSame('paid', $target->deposit_purchase_state, 'jin: 판매완료 → 자동 green');
    }

    public function test_non_deposit_vehicle_has_null_state(): void
    {
        $plain = Vehicle::create([
            'vehicle_number' => 'X9', 'sales_channel' => 'export',
            'sale_date' => '2026-05-01', 'sale_price' => 10_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);

        $this->assertFalse((bool) $plain->is_deposit_purchase);
        $this->assertNull($plain->deposit_purchase_state, '보증금 매입 아니면 뱃지 없음');
    }
}
