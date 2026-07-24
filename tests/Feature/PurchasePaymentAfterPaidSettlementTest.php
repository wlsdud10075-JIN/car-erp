<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 54가6191 케이스 (jin 2026-07-24) — '정산 후 매입 잔금 지급'이 이 회사의 정상 업무인데
 * paid 정산 차량의 매입 잔금 생성·확정이 막혀(구 creating/assertPaidSettlementGuard) 매입 완납
 * 기록이 영영 불가능하던 문제를 수정한 것에 대한 검증.
 *
 * 불변식 증명(advisor): paid 정산 차량에 매입 잔금을 추가·확정해도
 *   total_margin / settlement_amount / actual_payout / confirmed_snapshot 은 불변,
 *   purchase_unpaid_amount 만 0 으로 떨어진다 (PBP는 현금흐름만 갱신, 정산 회계 무영향).
 * 소급 변경 방어(확정 후 amount·삭제 차단)와 판매(FinalPayment) paid 가드는 그대로 유지됨.
 */
class PurchasePaymentAfterPaidSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_balance_can_be_paid_after_settlement_paid_without_touching_accounting(): void
    {
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $salesman = Salesman::create(['name' => '홍길동', 'is_active' => true]);

        $vehicle = Vehicle::create([
            'vehicle_number' => '54가6191',
            'sales_channel' => 'export',
            'salesman_id' => $salesman->id,
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 100_000_000,
            'purchase_price' => 80_000_000,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);

        // 정산 paid 전환 → confirmed_snapshot 캡처 (매입 잔금 지급 '전' 시점 박제).
        // auth 없이 생성 → Settlement 승인 가드 우회(시드·artisan 패턴).
        $settlement = Settlement::create([
            'vehicle_id' => $vehicle->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'paid_at' => now(),
        ]);

        $settlement->refresh();
        $beforeSnapshot = $settlement->confirmed_snapshot;
        $beforeTotalMargin = $settlement->total_margin;
        $beforeSettlementAmount = $settlement->settlement_amount;
        $beforeActualPayout = $settlement->actual_payout;

        // 매입 미지급 = 80,000,000 (아직 안 갚음)
        $this->assertEquals(80_000_000, (int) $vehicle->fresh()->purchase_unpaid_amount);

        // 정산 후 매입 잔금 지급 — 이전엔 creating 가드로 DomainException 이던 흐름.
        $this->actingAs($finance);
        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $vehicle->id,
            'amount' => 80_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '정산 후 매입 잔금 지급 (54가6191)',
            'created_by_user_id' => $finance->id,
        ]);
        app(PaymentConfirmationService::class)->confirmPurchasePayment($pbp, $finance, '신한-99999');

        // ① 매입 완납 — purchase_unpaid 0, PBP 확정됨
        $this->assertEquals(0, (int) $vehicle->fresh()->purchase_unpaid_amount);
        $this->assertNotNull($pbp->fresh()->confirmed_at);

        // ② 정산 회계값 byte-identical (PBP는 정산 마진·snapshot 무영향)
        $settlement->refresh();
        $this->assertSame($beforeSnapshot, $settlement->confirmed_snapshot);
        $this->assertSame($beforeTotalMargin, $settlement->total_margin);
        $this->assertSame($beforeSettlementAmount, $settlement->settlement_amount);
        $this->assertSame($beforeActualPayout, $settlement->actual_payout);
        $this->assertSame('paid', $settlement->settlement_status);

        // ③ 추적 가능한 허용 — AuditLog 기록 (purchase_payment_gate_override 패턴)
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vehicle::class,
            'auditable_id' => $vehicle->id,
            'action' => 'purchase_payment_after_paid',
        ]);
    }

    public function test_confirmed_purchase_payment_still_cannot_be_deleted_after_paid(): void
    {
        // 소급 변경 방어(updating/deleting)는 그대로 — 완화한 건 creating(신규 지급)뿐.
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $vehicle = Vehicle::create([
            'vehicle_number' => '54가6192',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'purchase_price' => 50_000_000,
        ]);

        $this->actingAs($finance);
        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $vehicle->id,
            'amount' => 50_000_000,
            'payment_date' => now()->toDateString(),
            'created_by_user_id' => $finance->id,
        ]);
        app(PaymentConfirmationService::class)->confirmPurchasePayment($pbp, $finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('재무 확정된 매입 잔금은 삭제할 수 없습니다');
        $pbp->fresh()->delete();
    }
}
