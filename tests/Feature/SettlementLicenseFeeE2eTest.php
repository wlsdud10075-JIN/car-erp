<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * E2E — 정산 1차 → 2차 → 환차 전체 흐름에서 면허비 n/1 이 회계에 정확히 반영되는지.
 *
 * 시나리오 (USD 3대, 프리랜서 ratio 50%):
 *   구입 8,000,000 / 판매 10,000 USD @1300 완납 → 거래완료 정산 confirm → paid(snapshot 캡처).
 *   면허비 n/1 300,000 → 100,000/대 (기본 11,000 에서 +89,000) → 총마진·정산액 자동 감소.
 *   2차 마감 @1350 → 환차 +500,000(익) + 이월(carry_out) = closed - paid snapshot.
 */
class SettlementLicenseFeeE2eTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(Salesman $sm, Buyer $buyer, string $num): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1300,
            'salesman_id' => $sm->id,
            'buyer_id' => $buyer->id,
            'purchase_price' => 8_000_000,
            'sale_price' => 10_000,
            'sale_date' => '2026-05-01',
            'cost_deregistration' => 24_000,
            'cost_license' => 11_000,
            'cost_towing' => 30_000,
            'dhl_request' => false,
        ]);
        // 판매 완납 + 입금 시점 환율 1300 스냅샷 (환차 분모).
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 10_000,
            'exchange_rate' => 1300,
            'payment_date' => '2026-05-05',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => 1,
        ]);

        return $v->fresh();
    }

    public function test_full_settlement_lifecycle_with_license_n1_and_fx(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $sm = Salesman::create(['name' => 'E2E프리', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'E2E BUYER', 'is_active' => true]);

        $vehicles = collect(['E2E-1', 'E2E-2', 'E2E-3'])->map(fn ($n) => $this->makeVehicle($sm, $buyer, $n));

        // 각 차량 정산: pending → confirmed → paid(스냅샷 캡처 + secondary=pending).
        $settlements = $vehicles->map(function ($v) use ($sm) {
            $s = Settlement::create([
                'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
                'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending',
            ]);
            $s->settlement_status = 'confirmed';
            $s->save();
            $s->settlement_status = 'paid';
            $s->save();   // becamePaid → confirmed_snapshot + secondary_status=pending

            return $s->fresh();
        });

        // paid 시점 정산액 스냅샷 (기본 면허비 11,000 기준).
        $s1 = $settlements[0];
        $this->assertSame('pending', $s1->secondary_status);
        $paidSnapshotPayout = (int) ($s1->confirmed_snapshot['actual_payout'] ?? 0);
        $this->assertGreaterThan(0, $paidSnapshotPayout);

        $marginBefore = $s1->total_margin;

        // ── 면허비 n/1: 300,000 / 3 = 100,000/대 (기본 11,000 → +89,000) ──
        $res = app(BulkVehicleCostService::class)->apply(
            'cost_license',
            $vehicles->mapWithKeys(fn ($v) => [$v->id => 100_000])->all(),
            $admin, '면허비 2차 n/1 (E2E, 3대, 총 300,000)', false
        );
        $this->assertSame(3, $res['applied']);

        // 각 차량 cost_license = 100,000, 잠금 자동 해제 후 재잠금.
        $this->assertSame(100_000, (int) $vehicles[0]->fresh()->cost_license);
        // 감사로그 — 차량별 잠금해제 사유 기록(전량 기록 확인).
        foreach ($vehicles as $v) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $v->id, 'action' => 'bulk_cost_applied',
            ]);
        }

        // 총마진 감소 = 89,000 × 0.9 = 80,100 (부가세 10% 차감 후).
        $s1 = $s1->fresh();
        $this->assertSame($marginBefore - 80_100, $s1->total_margin);
        // 정산액 감소 = 80,100 × 50% = 40,050.
        $this->assertSame((int) round(($marginBefore - 80_100) * 0.5), $s1->settlement_amount);

        // ── 2차 마감 (2026-07-06 재피벗): 잔금을 판매환율 1300 보다 높은 1350 에 수령 → 환차 +500,000 ──
        //   (쿼리빌더 update 로 확정 잔금 lock 우회 — 실제로는 수령 시점에 1350 으로 기입된 상황을 재현)
        $vehicles[0]->finalPayments()->update(['exchange_rate' => 1350]);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s1->id);

        $s1 = $s1->fresh();
        $this->assertSame('closed', $s1->secondary_status);
        $this->assertSame(500_000, (int) $s1->exchange_difference_krw);
        // 이월 = closed 실지급 - paid 스냅샷 실지급 (면허비로 비용↑ → 정산액↓ 반영).
        $this->assertNotNull($s1->carryover_out_krw);
        // closed actual_payout 에 환차 +500,000 가산(프리랜서 ratio → 1:1 반영).
        $this->assertGreaterThan(0, $s1->actual_payout);
    }

    public function test_excel_style_bulk_records_all_vehicles_and_audits(): void
    {
        // 엑셀 업로드 = 매칭된 전 차량 기입 + 차량별 감사로그(전량 기록 검증).
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $sm = Salesman::create(['name' => 'E2E탁송', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'E2E BUYER2', 'is_active' => true]);
        $vehicles = collect(['TOW-1', 'TOW-2', 'TOW-3'])->map(fn ($n) => $this->makeVehicle($sm, $buyer, $n));

        // 위카 명세서처럼 차량별 상이한 탁송비.
        $amounts = [
            $vehicles[0]->id => 35_000,
            $vehicles[1]->id => 227_000,
            $vehicles[2]->id => 42_000,
        ];
        $res = app(BulkVehicleCostService::class)->apply('cost_towing', $amounts, $admin, '위카 탁송비 명세서 일괄 (E2E)', true);

        $this->assertSame(3, $res['applied']);
        $this->assertSame(35_000, (int) $vehicles[0]->fresh()->cost_towing);
        $this->assertSame(227_000, (int) $vehicles[1]->fresh()->cost_towing);
        $this->assertSame(42_000, (int) $vehicles[2]->fresh()->cost_towing);
        // 전 차량 감사로그 기록.
        $this->assertSame(3, AuditLog::where('action', 'bulk_cost_applied')
            ->whereIn('auditable_id', $vehicles->pluck('id'))->count());
    }
}
