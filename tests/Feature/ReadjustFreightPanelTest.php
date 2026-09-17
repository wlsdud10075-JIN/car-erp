<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔓 **회계 재조정(2차 마감 차량)으로 차량 패널에서 실제로 값을 넣을 수 있나** (jin 2026-09-17).
 *
 * 실사고 = ssancarerp `379우9212` — 2차 마감(정산 paid + secondary closed) 뒤 **운임비를 기입**하려는데
 * *「정산 PAID 상태인 차량의 회계 컬럼은 변경할 수 없습니다」* 로 막혔다. 토큰은 정상 발급돼 있었다.
 *
 * 🔑 **원인 = 화면 가드(`guardFinancialDriftAfterPaid`)가 토큰을 안 봤다.** 2026-05 에 만든 가드라
 *    트리거가 `paid` 이고, 07-24 락 개편이 도입한 잠금 해제 토큰을 모른다. 모델 가드
 *    (`guardLedgerLockOnSaving`)는 토큰을 보지만 **화면 가드가 먼저 던져** 거기까지 못 간다
 *    ⇒ 회계 재조정은 차량 패널에서 **한 번도 동작한 적이 없었다**(§8 #66 재발).
 *
 * ⚠️ **기존 `SettlementReadjustTest` 로는 원리상 못 잡는다** — 그건 토큰 «발급»까지만 보고
 *    패널 저장을 안 한다. 그래서 여기서는 **패널을 실제로 몰아** 값이 DB 에 남는지까지 본다.
 */
class ReadjustFreightPanelTest extends TestCase
{
    use RefreshDatabase;

    /** 실사고와 같은 상태 — EUR 완납 · 매입/판매 확정 잔금 · 정산 paid + 2차 closed · 운임비 0. */
    private function closedVehicle(): array
    {
        $admin = User::factory()->create([
            'permission' => 'super', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '379우9212', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1741,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 49_200_000, 'selling_fee' => 440_000,
            'purchase_date' => '2026-05-01',
            'sale_price' => 27_870, 'sale_date' => '2026-06-01',
            // 적재 차량이라 말소는 체크돼 있고 말소증 파일은 없다(운영 실측 4,090대가 이 상태).
            'is_deregistered' => true, 'nice_reg_owner_rrn' => '801201-1234567',
        ]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 49_640_000,
            'payment_date' => '2026-05-02', 'confirmed_at' => now()->subMonths(3),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 27_870,
            'payment_date' => '2026-06-05', 'confirmed_at' => now()->subMonths(3),
        ]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'paid', 'paid_at' => now()->subMonths(2),
            'secondary_status' => 'closed', 'secondary_closed_at' => now()->subMonth(),
            'attributed_month' => '2026-06-01',
        ]);
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $admin];
    }

    /** 전제 — 마감·완납 상태가 실제로 재현됐는지부터 본다. */
    public function test_the_fixture_reproduces_the_reported_state(): void
    {
        [$v] = $this->closedVehicle();

        $this->assertTrue($v->hasClosedSecondarySettlement());
        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '완납이어야 실사고와 같다');
        $this->assertSame(0.0, (float) $v->transport_fee);
    }

    /** 🚫 토큰이 없으면 종전대로 막힌다 — 가드를 없앤 게 아니라는 증거. */
    public function test_without_the_token_the_panel_still_blocks(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('transport_fee_str', '1370')
            ->set('userConfirmedDocCheckMismatch', true)
            ->call('save')
            ->assertHasErrors('purchase_price_str');

        $this->assertSame(0.0, (float) $v->fresh()->transport_fee, '토큰 없이 값이 들어갔다');
    }

    /** ✅ 회계 재조정 후 운임비가 실제로 DB 에 남는다 — 이 테스트가 실사고의 본체다. */
    public function test_readjust_lets_the_panel_save_the_freight(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '운임비 누락분 기입 (회계 재조정)');

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $this->assertTrue($c->get('hasLedgerUnlockToken'), '패널이 토큰을 인식해야 한다');

        $c->set('transport_fee_str', '1370')->call('save');

        // 적재 차량은 「말소 체크는 있고 서류가 없다」 → 확인 모달이 1회 뜬다(하드 블록 아님).
        $this->assertTrue($c->get('showDocCheckModal'), '문서 확인 모달이 뜨는 상태여야 실사고와 같다');
        $c->call('confirmSaveWithDocMismatch');

        $c->assertHasNoErrors();
        $this->assertSame(1370.0, (float) $v->fresh()->transport_fee, '회계 재조정으로도 운임비가 안 들어갔다');
    }

    /** 🔒 토큰은 1회용 — 같은 차를 또 고치려면 다시 재조정해야 한다. */
    public function test_the_token_is_consumed_after_one_save(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '운임비 누락분 기입 (회계 재조정)');

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $c->set('transport_fee_str', '1370')->set('userConfirmedDocCheckMismatch', true)->call('save');
        $this->assertSame(1370.0, (float) $v->fresh()->transport_fee);
        $this->assertFalse(Cache::has(Vehicle::ledgerUnlockCacheKey($v->id)), '토큰이 소비되지 않았다');

        // 두 번째 변경은 다시 막힌다
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('transport_fee_str', '2000')
            ->set('userConfirmedDocCheckMismatch', true)
            ->call('save')
            ->assertHasErrors('purchase_price_str');
        $this->assertSame(1370.0, (float) $v->fresh()->transport_fee, '토큰 없이 두 번째 변경이 통과했다');
    }

    /**
     * 💰 **운임비를 넣으면 미수가 새로 생기고, 그 돈을 추후에 받아 기록할 수 있어야 한다**
     *    (jin 2026-09-17 «미수로 남고, 추후에 받아야 해. 이거까지 진행이 되어야 해»).
     */
    public function test_the_new_receivable_can_be_collected_later_with_a_readjust(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '운임비 누락분 기입 (회계 재조정)');

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $c->set('transport_fee_str', '1370')->set('userConfirmedDocCheckMismatch', true)->call('save');
        $this->assertSame(1370.0, (float) $v->fresh()->sale_unpaid_amount, '운임비만큼 미수가 생겨야 한다');

        // ── 몇 달 뒤 그 돈을 받았다 — 다시 재조정하고 잔금을 넣는다 ──
        app(VehicleLedgerUnlockService::class)->unlock($v->fresh(), $admin, '운임비 수금분 기록 (회계 재조정)');

        $c2 = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $rows = $c2->get('finalPayments');
        $rows[] = [
            'id' => null, 'amount' => '1370', 'payment_date' => now()->toDateString(),
            'note' => '운임비 수금', 'type' => 'balance',
        ];
        $c2->set('finalPayments', $rows)->set('userConfirmedDocCheckMismatch', true)->call('save');

        $c2->assertHasNoErrors();
        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount, '수금분이 미수를 못 지웠다');
    }

    /** 🚫 재조정 중이 아니면 신규 잔금은 종전대로 막힌다 — 문이 상시 열린 게 아니다. */
    public function test_new_payments_stay_locked_without_a_token(): void
    {
        [$v] = $this->closedVehicle();

        $this->assertTrue($v->ledgerLockedForNewPayments(), '마감 차량의 신규 잔금은 잠겨 있어야 한다');
        Cache::put(Vehicle::ledgerUnlockCacheKey($v->id), ['by' => 1], now()->addMinutes(5));
        $this->assertFalse($v->ledgerLockedForNewPayments(), '재조정 중에는 열려야 한다');
        Cache::forget(Vehicle::ledgerUnlockCacheKey($v->id));
        $this->assertTrue($v->ledgerLockedForNewPayments(), '토큰이 사라지면 다시 잠겨야 한다');
    }
}
