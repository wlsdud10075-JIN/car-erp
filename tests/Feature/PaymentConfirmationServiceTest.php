<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 큐 20-B / 20-D — PaymentConfirmationService.
 * SAP/Odoo Draft/Posted 패턴 — confirmed_at SET 시점에 ledger 반영.
 *
 * 가드 4종:
 *   1. 권한 (canConfirmFinance)
 *   2. 재확정 차단 (confirmed_at != null)
 *   3. transfer 잔금 차단 (transfer_id != null)
 *   4. paid Settlement H4
 */
class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentConfirmationService;
    }

    private function makeContext(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);

        $vehicle = Vehicle::create([
            'vehicle_number' => '20D가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 100_000_000,
            'currency' => 'KRW',
        ]);
        // 큐 22-A-3 — 4컬럼 DROP. 계약금은 confirmed FP 로 표현.
        $vehicle->finalPayments()->create(['amount' => 50_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $vehicle->refresh();

        return compact('buyer', 'finance', 'sales', 'vehicle');
    }

    public function test_confirm_payment_sets_confirmed_at_and_updates_ledger(): void
    {
        $c = $this->makeContext();
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 30_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '잔금 1차',
        ]);

        // 분자 A안: 확정 전이라 ledger 미반영 (deposit 5천만 + advance 0 → 미수 5천만)
        $this->assertEquals(50_000_000, (int) $c['vehicle']->fresh()->sale_unpaid_amount);

        $this->service->confirmPayment($payment, $c['finance'], 'KB-12345');

        $payment->refresh();
        $this->assertNotNull($payment->confirmed_at);
        $this->assertEquals($c['finance']->id, $payment->confirmed_by_user_id);
        $this->assertEquals('KB-12345', $payment->finance_note);

        // 확정 후 ledger 반영 (50_000_000 + 30_000_000 = 80_000_000 받음 → 미수 20_000_000)
        $this->assertEquals(20_000_000, (int) $c['vehicle']->fresh()->sale_unpaid_amount);
    }

    public function test_confirm_payment_blocks_without_permission(): void
    {
        $c = $this->makeContext();
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('재무 확정 권한');
        $this->service->confirmPayment($payment, $c['sales']);
    }

    public function test_confirm_payment_blocks_re_confirmation(): void
    {
        $c = $this->makeContext();
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
            'confirmed_at' => now()->subDay(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 재무 확정된 잔금');
        $this->service->confirmPayment($payment, $c['finance']);
    }

    public function test_confirm_payment_blocks_transfer_linked_row(): void
    {
        $c = $this->makeContext();
        $other = Vehicle::create(['vehicle_number' => '20D가0002', 'sales_channel' => 'export', 'buyer_id' => $c['buyer']->id, 'currency' => 'KRW']);
        // 실제 InterVehicleTransfer 생성하여 FK 통과
        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['vehicle']->id,
            'target_vehicle_id' => $other->id,
            'buyer_id' => $c['buyer']->id,
            'requester_id' => $c['sales']->id,
            'amount' => 10_000_000,
            'currency' => 'KRW',
            'status' => 'executed',
        ]);
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'transfer_id' => $transfer->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('InterVehicleTransfer 흐름에서 재무 확정');
        $this->service->confirmPayment($payment, $c['finance']);
    }

    public function test_confirm_payment_blocks_paid_settlement(): void
    {
        $c = $this->makeContext();
        Settlement::create([
            'vehicle_id' => $c['vehicle']->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('paid 정산');
        $this->service->confirmPayment($payment, $c['finance']);
    }

    public function test_confirm_purchase_payment_sets_confirmed_at_and_updates_ledger(): void
    {
        $c = $this->makeContext();
        $c['vehicle']->update(['purchase_price' => 80_000_000]);
        // 큐 22-C-E (2026-05-20) — down_payment 컬럼 DROP. PBP 'down' type confirmed row 로 변환.
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $c['vehicle']->purchaseBalancePayments()->create([
                'amount' => 30_000_000,
                'type' => 'down',
                'payment_date' => now()->toDateString(),
                'confirmed_at' => now(),
            ]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }

        $payment = PurchaseBalancePayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 20_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '매입 잔금 1차',
        ]);

        // 분자 A안 전: 미지급 = 80_000_000 - 30_000_000 = 50_000_000
        $this->assertEquals(50_000_000, $c['vehicle']->fresh()->purchase_unpaid_amount);

        $this->service->confirmPurchasePayment($payment, $c['finance'], '신한-67890');

        $payment->refresh();
        $this->assertNotNull($payment->confirmed_at);
        $this->assertEquals($c['finance']->id, $payment->confirmed_by_user_id);

        // 확정 후: 미지급 = 80_000_000 - (30_000_000 + 20_000_000) = 30_000_000
        $this->assertEquals(30_000_000, $c['vehicle']->fresh()->purchase_unpaid_amount);
    }

    public function test_confirm_purchase_payment_blocks_re_confirmation(): void
    {
        $c = $this->makeContext();
        $payment = PurchaseBalancePayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
            'confirmed_at' => now()->subDay(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 재무 확정된 매입 잔금');
        $this->service->confirmPurchasePayment($payment, $c['finance']);
    }

    public function test_confirmed_final_payment_cannot_be_updated(): void
    {
        $c = $this->makeContext();
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);
        $this->service->confirmPayment($payment, $c['finance']);

        // 2차 정산 마감(closed) → 소급 수정 잠금 (정산 락 개편 2026-07-24)
        Settlement::create([
            'vehicle_id' => $c['vehicle']->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now(),
            'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);

        // 마감 후 확정 잔금 amount 변경 시도 → 차단
        $payment->refresh();
        $payment->amount = 20_000_000;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('잠금 해제');
        $payment->save();
    }

    public function test_confirmed_final_payment_cannot_be_deleted(): void
    {
        $c = $this->makeContext();
        $payment = FinalPayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);
        $this->service->confirmPayment($payment, $c['finance']);

        Settlement::create([
            'vehicle_id' => $c['vehicle']->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now(),
            'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('삭제할 수 없습니다');
        $payment->fresh()->delete();
    }

    public function test_confirmed_purchase_payment_cannot_be_deleted(): void
    {
        $c = $this->makeContext();
        $payment = PurchaseBalancePayment::create([
            'vehicle_id' => $c['vehicle']->id,
            'amount' => 10_000_000,
            'payment_date' => now()->toDateString(),
        ]);
        $this->service->confirmPurchasePayment($payment, $c['finance']);

        Settlement::create([
            'vehicle_id' => $c['vehicle']->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now(),
            'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('삭제할 수 없습니다');
        $payment->fresh()->delete();
    }
}
