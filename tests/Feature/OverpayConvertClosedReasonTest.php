<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 과입금 → 적립금 전환, 2차 정산 마감 차량 (jin 2026-08-26).
 *
 * 🔑 **왜 고쳤나** — 이 버튼은 2026-07-09 규칙(«마감 차량은 무조건 차단»)을 들고 있었는데,
 *    **2026-07-24 정산 락 개편**이 «마감 전 자유 / 마감 후 사유 남기고 정정»으로 완화했다.
 *    `FinalPayment::updating` 은 그때 갱신됐지만 이 소비자만 안 따라왔다.
 *    게다가 이 버튼은 `$allowConfirmedMutation` 로 모델 가드를 우회하므로 **잠금을 풀어도 안 열렸다** —
 *    운영에서 104라2951·62두1461 두 대가 그 상태로 막혀 있었다.
 *
 * 🚫 승인 사다리는 두지 않는다(jin) — 사유만 남기고 본인이 진행한다.
 */
class OverpayConvertClosedReasonTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = '바이어 착오 송금분 크레딧 전환';

    private Buyer $buyer;

    private function overpaidVehicle(bool $closed): Vehicle
    {
        $sm = Salesman::create(['name' => '영업', 'type' => 'employee', 'is_active' => true]);
        $this->buyer = Buyer::create(['name' => 'OVERPAY BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '104라2951', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1694,
            'salesman_id' => $sm->id, 'buyer_id' => $this->buyer->id, 'dhl_request' => false,
            'purchase_price' => 8_000_000, 'purchase_date' => now()->subMonths(6)->toDateString(),
            'sale_date' => now()->subMonths(5)->toDateString(),
            'sale_price' => 16930, 'transport_fee' => 1065,
        ]);
        // 총판매 17,995 인데 18,075 입금 → 과입금 80 EUR (운영 실제 형태)
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 16930,
            'payment_date' => now()->subMonths(5)->toDateString(), 'confirmed_at' => now()->subMonths(5)]);
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 1145,
            'payment_date' => now()->subMonths(4)->toDateString(), 'confirmed_at' => now()->subMonths(4)]);

        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_status' => $closed ? 'paid' : 'pending',
            'secondary_status' => $closed ? 'closed' : 'pending',
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000,
        ]);

        return $v->fresh();
    }

    private function panel(Vehicle $v, User $u)
    {
        $this->actingAs($u);

        return Volt::test('erp.receivables.index')->call('openPanel', $v->id);
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 마감 전에는 **사유를 묻지 않는다** — 개편의 요지가 「마감 전 자유 수정」이다. */
    public function test_before_close_no_reason_is_asked(): void
    {
        $v = $this->overpaidVehicle(closed: false);

        $this->panel($v, $this->manager())->call('convertOverpayToSavings');

        $this->assertEqualsWithDelta(0.0, (float) $v->fresh()->sale_unpaid_amount, 0.01);
    }

    /** 🚨 마감 후 사유 없이 누르면 막힌다 — 그리고 **왜 막혔는지** 알려준다. */
    public function test_after_close_without_reason_is_blocked_with_a_message(): void
    {
        $v = $this->overpaidVehicle(closed: true);

        $page = $this->panel($v, $this->manager())->call('convertOverpayToSavings');

        $this->assertLessThan(0, (float) $v->fresh()->sale_unpaid_amount, '사유 없이 전환돼 버렸다');
        $page->assertSee('정정 사유');
    }

    /** ✅ 마감 후여도 사유를 쓰면 통과한다 — 이게 07-24 개편의 규칙이다. */
    public function test_after_close_with_reason_converts(): void
    {
        $v = $this->overpaidVehicle(closed: true);

        $this->panel($v, $this->manager())
            ->set('overpayReason', self::REASON)
            ->call('convertOverpayToSavings');

        $this->assertEqualsWithDelta(0.0, (float) $v->fresh()->sale_unpaid_amount, 0.01, '마감 후 전환이 안 됐다');
    }

    /** 사유는 **감사로그에 남는다** — 「왜 지급 끝난 차를 건드렸나」가 여기서만 보인다. */
    public function test_the_reason_is_recorded_in_the_audit_log(): void
    {
        $v = $this->overpaidVehicle(closed: true);

        $this->panel($v, $this->manager())
            ->set('overpayReason', self::REASON)
            ->call('convertOverpayToSavings');

        $log = AuditLog::where('action', 'overpay_converted_to_savings')
            ->where('auditable_id', $v->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(self::REASON, $log->old_value);
        $this->assertStringContainsString('EUR', (string) $log->new_value);
    }

    /** 짧은 사유는 거부 — 다른 잠금해제와 같은 기준(10자)을 쓴다. */
    public function test_a_too_short_reason_is_refused(): void
    {
        $v = $this->overpaidVehicle(closed: true);

        $this->panel($v, $this->manager())
            ->set('overpayReason', '착오')
            ->call('convertOverpayToSavings');

        $this->assertLessThan(0, (float) $v->fresh()->sale_unpaid_amount);
    }

    /**
     * 마감 후 전환은 **관리·업무관리자**만 (jin: 재무 역할은 없고 그 둘이 한다).
     * 재무 role 은 전환 버튼 자체는 보이지만 마감 차량에서는 못 누른다.
     */
    public function test_after_close_finance_role_alone_cannot_convert(): void
    {
        $v = $this->overpaidVehicle(closed: true);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);

        $this->panel($v, $finance)
            ->set('overpayReason', self::REASON)
            ->call('convertOverpayToSavings');

        $this->assertLessThan(0, (float) $v->fresh()->sale_unpaid_amount, '권한 없는 역할이 마감 차량을 전환했다');
    }
}
