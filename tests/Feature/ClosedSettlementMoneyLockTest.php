<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔒 **2차 정산이 마감되면 돈에 관한 것은 회계 재조정으로만 고친다** (jin 2026-11-20).
 *
 * 문서 정리 중 코드 대조에서 나온 구멍 넷을 한 번에 막았다:
 *   ① 정산 드로어 — 지급된 정산의 상태 내리기 차단 · 마감 행은 비고만
 *   ② 차량 패널 — 마감 차의 확정 매입 지급 줄(계약금·매도비 칸 포함)·거울 없는 확정 판매 입금 줄은 재조정 뒤에만
 *   ③ 재조정 토큰 — 저장 한 번에 끝난다(빈 칸 첫 입력에서도 남지 않는다)
 *   ⑦ 미수 보류가 풀린 정산이 마감된 달에 남으면 열린 달로 자동 이동 + 표시, 월결산은 보류 중 정산을 안 센다
 *
 * ⚠️ 가장 큰 위험 = 아무것도 안 바꾼 저장이 막히는 것(운영 마감 정산 3,992건 대부분이 적재분). 그 경우를 따로 지킨다.
 */
class ClosedSettlementMoneyLockTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** EUR 완납 · 확정 매입 지급 줄(잔금 + 계약금) · 거울 없는 확정 판매 입금 줄 · 정산 paid + 2차 closed. */
    private function closedVehicle(string $secondary = 'closed'): array
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당'.++$this->n, 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'BUYER'.$this->n, 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => 'LOCK-'.$this->n, 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1741,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 49_200_000, 'selling_fee' => 440_000, 'purchase_date' => '2026-05-01',
            'sale_price' => 27_870, 'sale_date' => '2026-06-01',
            'is_deregistered' => true, 'nice_reg_owner_rrn' => '801201-1234567',
        ]);
        $pbp = PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'amount' => 44_640_000, 'payment_date' => '2026-05-02', 'confirmed_at' => now()->subMonths(3)]);
        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'type' => 'down', 'amount' => 5_000_000, 'payment_date' => '2026-05-01', 'confirmed_at' => now()->subMonths(3)]);
        // 거울(채권관리 회수 줄) 없는 확정 입금 — 엑셀 적재분과 같은 모양
        $fp = FinalPayment::withoutEvents(fn () => FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 27_870, 'exchange_rate' => 1745.5,
            'payment_date' => '2026-06-05', 'confirmed_at' => now()->subMonths(3),
        ]));
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'paid', 'paid_at' => now()->subMonths(2),
            'secondary_status' => $secondary, 'secondary_closed_at' => $secondary === 'closed' ? now()->subMonth() : null,
            'attributed_month' => '2026-06-01',
        ]);
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $admin, $pbp, $fp, $s];
    }

    private function panel(Vehicle $v)
    {
        return Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('userConfirmedDocCheckMismatch', true);
    }

    // ── ② 차량 패널 ────────────────────────────────────────────────────────────

    /** 🚨 아무것도 안 바꾼 저장(메모만)은 마감 차에서도 통과한다 — 숫자 표기 차이로 막히면 운영 수천 대의 저장이 멎는다. */
    public function test_an_unchanged_save_on_a_closed_car_is_not_blocked(): void
    {
        [$v, $admin, $pbp, $fp] = $this->closedVehicle();
        $this->actingAs($admin);

        $this->panel($v)->set('purchase_remittance_memo', '메모만 고침')->call('save')->assertHasNoErrors();

        $this->assertSame('메모만 고침', $v->fresh()->purchase_remittance_memo);
        $this->assertSame(44_640_000.0, (float) $pbp->fresh()->amount);
        $this->assertSame(27_870.0, (float) $fp->fresh()->amount);
    }

    public function test_a_confirmed_purchase_row_cannot_be_edited_or_deleted_on_a_closed_car(): void
    {
        [$v, $admin, $pbp] = $this->closedVehicle();
        $this->actingAs($admin);

        $c = $this->panel($v);
        $rows = $c->get('purchaseBalancePayments');
        $i = array_search($pbp->id, array_column($rows, 'id'), true);
        $c->set("purchaseBalancePayments.{$i}.amount", '40,000,000')->call('save')->assertHasErrors('purchase_price_str');
        $this->assertSame(44_640_000.0, (float) $pbp->fresh()->amount, '마감 차의 확정 매입 지급 줄 금액이 바뀌었다');

        $c2 = $this->panel($v);
        $rows = array_values(array_filter($c2->get('purchaseBalancePayments'), fn ($r) => $r['id'] !== $pbp->id));
        $c2->set('purchaseBalancePayments', $rows)->call('save')->assertHasErrors('purchase_price_str');
        $this->assertNotNull($pbp->fresh(), '마감 차의 확정 매입 지급 줄이 지워졌다');
    }

    public function test_the_deposit_box_is_locked_on_a_closed_car(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);

        $this->panel($v)->set('down_payment_str', '3,000,000')->call('save')->assertHasErrors('purchase_price_str');

        $this->assertSame(5_000_000.0, (float) $v->purchaseBalancePayments()->where('type', 'down')->sum('amount'));
    }

    public function test_an_imported_confirmed_sale_row_is_locked_on_a_closed_car(): void
    {
        [$v, $admin, , $fp] = $this->closedVehicle();
        $this->actingAs($admin);

        $c = $this->panel($v);
        $i = array_search($fp->id, array_column($c->get('finalPayments'), 'id'), true);
        $c->set("finalPayments.{$i}.amount", '20000')->call('save')->assertHasErrors('purchase_price_str');

        $this->assertSame(27_870.0, (float) $fp->fresh()->amount, '마감 차의 확정 판매 입금 줄 금액이 바뀌었다');
    }

    /** 새 줄 추가는 열려 있다 — 정산 뒤 매입 지급이 정상 흐름이다(모델 훅도 새 줄은 안 잠근다). */
    public function test_a_new_purchase_row_can_still_be_added_on_a_closed_car(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);

        $c = $this->panel($v);
        $rows = $c->get('purchaseBalancePayments');
        $rows[] = ['id' => null, 'amount' => '100,000', 'payment_date' => '2026-11-01', 'note' => '추가 지급', 'type' => 'balance'];
        $c->set('purchaseBalancePayments', $rows)->call('save')->assertHasNoErrors();

        $this->assertSame(3, $v->purchaseBalancePayments()->count());
    }

    /** 마감 전(2차 대기)에는 종전대로 고친다 — 그 변동은 2차 마감 때 이월로 정리된다. */
    public function test_before_closing_the_rows_stay_editable(): void
    {
        [$v, $admin, $pbp] = $this->closedVehicle('pending');
        $this->actingAs($admin);

        $c = $this->panel($v);
        $i = array_search($pbp->id, array_column($c->get('purchaseBalancePayments'), 'id'), true);
        $c->set("purchaseBalancePayments.{$i}.amount", '40,000,000')->call('save')->assertHasNoErrors();

        $this->assertSame(40_000_000.0, (float) $pbp->fresh()->amount);
    }

    // ── ②+③ 회계 재조정 ────────────────────────────────────────────────────────

    public function test_a_readjust_opens_the_rows_for_one_save_only(): void
    {
        [$v, $admin, $pbp] = $this->closedVehicle();
        $this->actingAs($admin);
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '매입 지급 금액 정정 (회계 재조정)');

        $c = $this->panel($v);
        $i = array_search($pbp->id, array_column($c->get('purchaseBalancePayments'), 'id'), true);
        $c->set("purchaseBalancePayments.{$i}.amount", '44,000,000')->call('save')->assertHasNoErrors();
        $this->assertSame(44_000_000.0, (float) $pbp->fresh()->amount, '재조정으로도 못 고쳤다');
        $this->assertFalse(Cache::has(Vehicle::ledgerUnlockCacheKey($v->id)), '지급 줄만 고친 저장에서 토큰이 남았다');

        $c2 = $this->panel($v);
        $c2->set("purchaseBalancePayments.{$i}.amount", '43,000,000')->call('save')->assertHasErrors('purchase_price_str');
        $this->assertSame(44_000_000.0, (float) $pbp->fresh()->amount, '토큰 없이 두 번째 변경이 통과했다');
    }

    /** ③ 빈 칸 첫 입력(모델 가드가 토큰을 안 쓰는 저장)에서도 토큰이 저장 한 번으로 끝난다. */
    public function test_the_token_does_not_linger_after_a_first_input_save(): void
    {
        [$v, $admin] = $this->closedVehicle();
        $this->actingAs($admin);
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '탁송비 누락분 기입 (회계 재조정)');

        $this->panel($v)->set('cost_towing_str', '25,000')->call('save')->assertHasNoErrors();

        $this->assertSame(25_000, (int) $v->fresh()->cost_towing);
        $this->assertFalse(Cache::has(Vehicle::ledgerUnlockCacheKey($v->id)), '첫 입력 저장 뒤 토큰이 5분간 남는다');
        $this->assertNull(Vehicle::$readjustSaveVehicleId, '재조정 저장 플래그가 남았다');
    }

    // ── ① 정산 드로어 ──────────────────────────────────────────────────────────

    public function test_a_paid_settlement_cannot_be_moved_back(): void
    {
        [, $admin, , , $s] = $this->closedVehicle('pending');
        $this->actingAs($admin);

        try {
            $s->update(['settlement_status' => 'confirmed']);
            $this->fail('지급된 정산의 상태가 내려갔다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settlement_status', $e->errors());
        }
        $this->assertSame('paid', $s->fresh()->settlement_status);

        // 2차 대기 동안 금액 칸은 종전대로 고친다
        $s->fresh()->update(['other_deduction' => 30_000]);
        $this->assertSame(30_000.0, (float) $s->fresh()->other_deduction);
    }

    public function test_the_drawer_saves_only_the_note_on_a_closed_settlement(): void
    {
        [, $admin, , , $s] = $this->closedVehicle();
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)
            ->set('per_unit_amount', 500000)->call('save')->assertHasErrors('settlement_status');
        $this->assertSame(100_000.0, (float) $s->fresh()->per_unit_amount, '마감된 정산의 건당액이 바뀌었다');

        Volt::test('erp.settlements.index')->call('openEdit', $s->id)
            ->set('note', '비고만')->call('save')->assertHasNoErrors();
        $this->assertSame('비고만', $s->fresh()->note);
        $this->assertSame('closed', $s->fresh()->secondary_status);
    }

    // ── ⑦ 귀속월 자동 이동 ─────────────────────────────────────────────────────

    private function closeMonth(string $ym, string $status = SettlementPayoutBatch::STATUS_APPROVED): void
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        SettlementPayoutBatch::create([
            'month' => $ym, 'submitter_id' => $u->id, 'submitter_rank' => 1, 'current_level' => 2, 'status' => $status,
            'total_payout' => 100_000, 'settlement_count' => 1, 'submitted_at' => now(), 'decided_at' => $status === SettlementPayoutBatch::STATUS_APPROVED ? now() : null,
        ]);
    }

    /** 10월 귀속 · 미수 200 으로 보류 중인 확정 정산. */
    private function heldSettlement(array $salesman = []): array
    {
        $sm = Salesman::create(array_merge(['name' => '보류담당'.++$this->n, 'type' => 'freelance', 'is_active' => true], $salesman));
        $buyer = Buyer::create(['name' => 'HB'.$this->n, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'HELD-'.$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id, 'sale_price' => 10_000_000, 'sale_date' => '2026-10-05',
            'purchase_price' => 8_000_000, 'purchase_date' => '2026-10-01',
        ]);
        FinalPayment::withoutEvents(fn () => FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 9_999_800, 'payment_date' => '2026-10-20', 'confirmed_at' => now()]));
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-25', 'attributed_month' => '2026-10-01',
        ]);
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $s->fresh()];
    }

    public function test_a_released_settlement_moves_to_the_open_month_when_the_car_is_paid_off(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        [$v, $s] = $this->heldSettlement();
        $this->closeMonth('2026-10');
        $this->assertTrue($s->isPayoutHeldByUnpaid(), '전제 — 미수로 보류 중');
        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'), '보류 중에는 그 달에 그대로 둔다');

        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '재무', 'email_verified_at' => now()]));
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 200, 'payment_date' => '2026-11-24', 'confirmed_at' => now()]);

        $s = $s->fresh();
        $this->assertSame('2026-11-01', $s->attributed_month->format('Y-m-d'), '완납됐는데 마감된 10월에 남았다');
        $this->assertSame('2026-10-01', $s->reanchored_from->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $s->id, 'action' => 'settlement_reanchored']);
        $this->assertContains($s->id, SettlementPayoutBatch::eligibleSettlementIds('2026-11'), '11월 월정산 대상에 들어가야 한다');

        // 정산관리에 「10월에서 이동」 뱃지
        Volt::test('erp.settlements.index')->set('monthFilter', '2026-11')->assertSeeHtml('data-reanchored');
    }

    /** 결재 중인 달로는 보내지 않는다 — 그 배치에 못 들어가고 승인 뒤 또 마감 달에 남는다. */
    public function test_the_destination_skips_a_month_that_is_waiting_for_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        [$v, $s] = $this->heldSettlement();
        $this->closeMonth('2026-10');
        $this->closeMonth('2026-11', SettlementPayoutBatch::STATUS_PENDING);

        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '재무', 'email_verified_at' => now()]));
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 200, 'payment_date' => '2026-11-24', 'confirmed_at' => now()]);

        $this->assertSame('2026-12-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 🚫 지급 제외 계정(헤이맨)의 정산은 옮기지 않는다 — 옮기면 달이 마감될 때마다 영원히 앞으로 굴러간다. */
    public function test_payout_excluded_settlements_stay_where_they_are(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        [$v, $s] = $this->heldSettlement(['payout_excluded' => true]);
        $this->closeMonth('2026-10');
        FinalPayment::withoutEvents(fn () => FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 200, 'payment_date' => '2026-11-24', 'confirmed_at' => now()]));
        $v->refreshCaches();

        $this->artisan('settlements:reanchor-closed-month --apply')->assertSuccessful();

        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'));
        $this->assertNull($s->fresh()->reanchored_from);
    }

    /** 저장 계기가 안 울린 경로(raw update)는 매일 점검이 옮긴다. dry-run 은 아무것도 안 바꾼다. */
    public function test_the_daily_sweep_moves_what_the_save_hooks_missed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        [$v, $s] = $this->heldSettlement();
        $this->closeMonth('2026-10');
        FinalPayment::withoutEvents(fn () => FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 200, 'payment_date' => '2026-11-24', 'confirmed_at' => now()]));

        $this->artisan('settlements:reanchor-closed-month')->assertSuccessful();
        $this->assertSame('2026-10-01', $s->fresh()->attributed_month->format('Y-m-d'), 'dry-run 이 옮겼다');

        $this->artisan('settlements:reanchor-closed-month --apply')->assertSuccessful();
        $this->assertSame('2026-11-01', $s->fresh()->attributed_month->format('Y-m-d'));
    }

    /** 월결산 알림톡은 미수로 보류 중인 정산을 세지 않는다 — 승인 때 본 월정산 카드와 같은 모집단. */
    public function test_monthly_closing_does_not_count_a_held_settlement(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        [, $held] = $this->heldSettlement();
        [$v2, $free] = $this->heldSettlement();
        FinalPayment::withoutEvents(fn () => FinalPayment::create(['vehicle_id' => $v2->id, 'type' => 'balance', 'amount' => 200, 'payment_date' => '2026-10-28', 'confirmed_at' => now()]));
        $v2->refreshCaches();   // 10월은 아직 열려 있어 그대로 10월 귀속
        $this->assertTrue($held->fresh()->isPayoutHeldByUnpaid());
        $this->assertFalse($free->fresh()->isPayoutHeldByUnpaid());

        $vars = AlimtalkMonthlyClosing::buildVars('2026-10');

        $this->assertSame(number_format($free->fresh()->total_margin).'원', $vars['총마진'], '보류 중 정산의 마진이 결산에 들어갔다');
    }
}
