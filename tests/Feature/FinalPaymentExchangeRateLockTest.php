<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 정산 재설계 선행결함1 (2026-07-06) — 재무확정 잔금의 exchange_rate/amount_krw 회계잠금.
 *
 * 신 2차정산분(Σ실입금KRW − sale_total_amount×판매환율)이 잔금별 환율(amount_krw)에 의존하는데,
 * FinalPayment::updating confirmed 락이 exchange_rate 를 빠뜨려 재무확정 후 무단수정 가능하던 갭 보강.
 * c-2 잠금해제($allowConfirmedMutation)만 우회 가능.
 */
class FinalPaymentExchangeRateLockTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedPayment(): FinalPayment
    {
        $v = Vehicle::create([
            'vehicle_number' => 'FX-LOCK-1', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1400,
            'sale_price' => 4000, 'sale_date' => '2026-06-01',
            'purchase_date' => '2026-05-01', 'dhl_request' => false,
        ]);

        $fp = $v->finalPayments()->create([
            'amount' => 4000, 'type' => 'balance', 'exchange_rate' => 1400,
            'payment_date' => '2026-06-10', 'confirmed_at' => now(),
        ]);

        // 정산 락 개편 (jin 2026-07-24) — 2차 정산 마감(closed)이 잔금 소급 잠금 트리거.
        //   (auth 없는 시점이라 paid 전환 승인 가드 자동 우회.)
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now(),
            'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);

        return $fp;
    }

    public function test_confirmed_payment_exchange_rate_change_is_blocked(): void
    {
        $p = $this->confirmedPayment();

        $this->expectException(\DomainException::class);
        $p->update(['exchange_rate' => 1500]);
    }

    public function test_unlock_flag_allows_exchange_rate_change(): void
    {
        $p = $this->confirmedPayment();

        FinalPayment::$allowConfirmedMutation = true;
        try {
            $p->update(['exchange_rate' => 1500]);
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }

        $p->refresh();
        $this->assertSame('1500.0000', (string) $p->exchange_rate);
        // amount_krw = amount × exchange_rate 자동 재계산 (saving 훅).
        $this->assertSame('6000000.00', (string) $p->amount_krw);
    }

    // ── c-2 잠금해제 토큰 (선행결함2) ──────────────────────────────

    public function test_c2_unlock_allows_one_time_correction_then_relocks(): void
    {
        $p = $this->confirmedPayment();
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        app(VehicleLedgerUnlockService::class)->unlockForFinalPayment($p, $admin, '환율 정정 사유 10자 이상');

        // 토큰 1회 소비 → 정정 통과.
        $p->update(['exchange_rate' => 1500]);
        $this->assertSame('1500.0000', (string) $p->fresh()->exchange_rate);

        // 토큰 소비됨 → 두 번째 정정은 다시 차단(재잠금).
        $this->expectException(\DomainException::class);
        $p->update(['exchange_rate' => 1600]);
    }

    public function test_c2_unlock_and_change_are_audited(): void
    {
        $p = $this->confirmedPayment();
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        app(VehicleLedgerUnlockService::class)->unlockForFinalPayment($p, $admin, '환율 정정 사유 기록용');
        $p->update(['exchange_rate' => 1500]);

        // 잠금해제 사유 기록.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => FinalPayment::class, 'auditable_id' => $p->id,
            'action' => 'ledger_field_unlocked', 'column_name' => 'unlock_reason',
        ]);
        // 실제 old→new 변경 기록.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => FinalPayment::class, 'auditable_id' => $p->id,
            'action' => 'updated', 'column_name' => 'exchange_rate', 'new_value' => '1500.0000',
        ]);
    }

    public function test_c2_unlock_requires_canapprove(): void
    {
        $p = $this->confirmedPayment();
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);

        $this->expectException(AuthorizationException::class);
        app(VehicleLedgerUnlockService::class)->unlockForFinalPayment($p, $sales, '충분히 긴 사유 텍스트');
    }

    public function test_c2_unlock_requires_min_reason_length(): void
    {
        $p = $this->confirmedPayment();
        $admin = User::factory()->create(['permission' => 'admin']);

        $this->expectException(\DomainException::class);
        app(VehicleLedgerUnlockService::class)->unlockForFinalPayment($p, $admin, '짧음');
    }
}
