<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 입금이 빠져 미수가 되살아나면 확정 전 정산 정리 — 어느 화면에서 지워도 (jin 2026-10-23, ssancarerp 67머3910).
 *
 * 그 차는 완납 순간 정산이 생겼고, 그 뒤 입금 줄이 사라져 미수가 다시 생겼는데 정산은 「정산대기」로 남았다.
 * 재무처리 화면만 정리했고 차량 패널·채권관리는 안 했다. 판정은 Vehicle::reconcileSettlementsAfterUnpaidRevived 하나.
 */
class UnpaidRevivedSettlementReconcileTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function paidVehicle(): array
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));   // 정산 자동 생성은 로그인 경로에서만
        $salesman = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $salesman->id]);
        $v = Vehicle::create([
            'vehicle_number' => '67머'.(3900 + $this->n), 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 10_000_000, 'sale_date' => '2026-10-12', 'purchase_price' => 5_000_000, 'purchase_date' => '2026-10-01',
            'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
        $fp = $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-10-19', 'confirmed_at' => now()]);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '픽스처: 완납인데 정산이 안 생겼다');

        return [$v->fresh(), $fp];
    }

    public function test_deleting_the_payment_in_receivables_clears_the_pending_settlement(): void
    {
        [$v, $fp] = $this->paidVehicle();
        $rh = ReceivableHistory::where('final_payment_id', $fp->id)->firstOrFail();

        Volt::test('erp.receivables.index')->set('selectedVehicleId', $v->id)->call('deleteHistory', $rh->id);

        $this->assertGreaterThan(0, (float) $v->fresh()->sale_unpaid_amount, '미수가 되살아나야 한다');
        $this->assertSame(0, Settlement::where('vehicle_id', $v->id)->count(), '미수인데 정산대기가 남았다(67머3910 그 모양)');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $v->id, 'action' => 'settlement_removed_unpaid_revived']);

        $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000_000, 'payment_date' => '2026-10-23', 'confirmed_at' => now()]);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '다시 완납되면 정산이 다시 생긴다');
    }

    public function test_override_approved_settlement_is_kept(): void
    {
        [$v, $fp] = $this->paidVehicle();
        Settlement::where('vehicle_id', $v->id)->update(['gate_override_reason' => '미수 예외 승인', 'gate_override_at' => now()]);
        $fp->delete();

        $r = $v->reconcileSettlementsAfterUnpaidRevived();
        $this->assertSame(0, $r['removed']);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '사람이 미수 예외로 승인한 정산은 지우지 않는다');
    }

    public function test_confirmed_settlement_is_kept_and_reported(): void
    {
        [$v, $fp] = $this->paidVehicle();
        Settlement::where('vehicle_id', $v->id)->update(['settlement_status' => 'confirmed']);
        $fp->delete();

        $r = $v->reconcileSettlementsAfterUnpaidRevived();
        $this->assertSame(['removed' => 0, 'locked' => true], $r);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count());
    }

    public function test_nothing_happens_while_still_fully_paid(): void
    {
        [$v] = $this->paidVehicle();
        $this->assertSame(['removed' => 0, 'locked' => false], $v->reconcileSettlementsAfterUnpaidRevived());
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'settlement_removed_unpaid_revived')->count());
    }

    public function test_all_three_screens_call_the_single_source(): void
    {
        foreach (['vehicles/index', 'receivables/index', 'transfers/index'] as $view) {
            $src = file_get_contents(resource_path("views/livewire/erp/{$view}.blade.php"));
            $this->assertStringContainsString('reconcileSettlementsAfterUnpaidRevived()', $src, "{$view} 가 정산 정리를 안 부른다");
        }
    }
}
