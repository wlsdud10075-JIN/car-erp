<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💰 돈 규칙 질문 목록에 jin 이 답한 것 4가지 (2026-11-22 — `docs/design/md-trim/README.md`).
 *
 * ③ 2차 마감 권한에 업무관리자 포함 («업무관리자도 포함»)
 * ⑤ 대표의 직접 지급 전환에도 미수 보류 검사 («그럴 일은 없겠지만 넣자»)
 * ⑦ 시스템관리자 대행 결재는 그 단계만 넘긴다 («그 단계만 넘어가는 게 맞긴 해»)
 * ⑧ 지급 대상 아님 계정(자매 회사)의 정산 마진도 회사이익 — 월정산 카드·엑셀·승인 링크에 표시할 때 더한다 («판 건 판 것»)
 */
class MoneyRuleAnswers20261122Test extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $perm, ?string $role = null, ?string $title = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'approval_title' => $title, 'email_verified_at' => now()]);
    }

    /** KRW 차 — 매입 1천만, 판매가 $sale. $paid 만큼만 확정 입금. */
    private function krwVehicle(Salesman $sm, int $sale = 12_000_000, ?int $paid = null): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'MR'.++$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => $sale, 'sale_date' => '2026-10-02',
            'buyer_id' => Buyer::create(['name' => 'B'.$this->n, 'is_active' => true])->id,
        ]);
        if (($paid ?? $sale) > 0) {
            $v->finalPayments()->create(['type' => 'balance', 'amount' => $paid ?? $sale, 'payment_date' => '2026-10-05', 'confirmed_at' => now()]);
        }

        return $v->fresh();
    }

    private function confirmed(Vehicle $v, Salesman $sm): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ]);
    }

    private function freelancer(string $name = '프리', array $extra = []): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'freelance', 'is_active' => true] + $extra);
    }

    // ── ③ ────────────────────────────────────────────────────────────────

    public function test_a_work_manager_can_close_the_secondary_settlement_whatever_the_role_column(): void
    {
        $sm = $this->freelancer();
        $s = $this->confirmed($this->krwVehicle($sm), $sm);
        Settlement::$allowBatchPayout = true;
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;
        $this->assertSame('pending', $s->fresh()->secondary_status);

        $this->actingAs($this->user('manager', '영업'));   // role 칸이 재무·관리가 아니어도
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);
        $this->assertSame('closed', $s->fresh()->secondary_status);
    }

    public function test_sales_and_clearance_still_cannot_close(): void
    {
        $sm = $this->freelancer();
        $s = $this->confirmed($this->krwVehicle($sm), $sm);
        Settlement::$allowBatchPayout = true;
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        $this->assertFalse($this->user('user', '수출통관')->canCorrectSecondaryCosts());
        $this->assertFalse($this->user('user', '영업')->canCorrectSecondaryCosts());
        $this->assertSame('pending', $s->fresh()->secondary_status);
    }

    // ── ⑤ ────────────────────────────────────────────────────────────────

    public function test_direct_paid_by_the_ceo_is_blocked_while_the_car_has_a_receivable(): void
    {
        $sm = $this->freelancer();
        $s = $this->confirmed($this->krwVehicle($sm, 12_000_000, 11_000_000), $sm);   // 미수 100만
        $this->actingAs($this->user('admin'));

        try {
            $s->fresh()->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
            $this->fail('미수가 남은 정산이 직접 지급으로 넘어갔다');
        } catch (ValidationException $e) {
            $this->assertSame(__('settlement.guard.paid_blocked_unpaid'), $e->errors()['settlement_status'][0]);
        }
        $this->assertSame('confirmed', $s->fresh()->settlement_status);
    }

    public function test_direct_paid_passes_when_fully_paid_or_gate_overridden(): void
    {
        $sm = $this->freelancer();
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $full = $this->confirmed($this->krwVehicle($sm), $sm);
        $full->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        $this->assertSame('paid', $full->fresh()->settlement_status, '완납 정산은 종전대로');

        $held = $this->confirmed($this->krwVehicle($sm, 12_000_000, 11_000_000), $sm);
        $held->forceFill(['gate_override_at' => now(), 'gate_override_by' => $admin->id, 'gate_override_reason' => '테스트용 예외 사유입니다'])->save();
        $this->assertTrue($held->fresh()->hasGateOverride(), '전제 — 예외 대상으로 처리됨');
        $held->fresh()->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        $this->assertSame('paid', $held->fresh()->settlement_status, '게이트 예외 건은 통과');
    }

    // ── ⑦ ────────────────────────────────────────────────────────────────

    public function test_a_system_admin_standing_in_only_passes_that_step(): void
    {
        $sm = $this->freelancer();
        $s = $this->confirmed($this->krwVehicle($sm), $sm);
        $bu = $this->user('admin', null, '부장');
        $ceo = $this->user('admin', null, '대표');
        $batch = SettlementPayoutBatch::submitForMonth($this->user('manager'), '2026-10', [], ['부장' => $bu->id, '상무' => '', '대표' => $ceo->id]);

        $batch->approveBy($this->user('super'));   // 부장 칸 대행
        $batch = $batch->fresh();
        $this->assertSame('pending', $batch->status, '대행 한 번으로 최종 승인이 되면 안 된다');
        $this->assertSame('confirmed', $s->fresh()->settlement_status, '아직 지급 전');
        $this->assertTrue($batch->canDecide($ceo), '다음 칸(대표) 차례');
        $this->assertFalse($batch->canDecide($bu));

        $batch->approveBy($ceo);
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('paid', $s->fresh()->settlement_status);
    }

    public function test_legacy_ladder_stand_in_moves_one_rung(): void
    {
        $sm = $this->freelancer();
        $s = $this->confirmed($this->krwVehicle($sm), $sm);
        $batch = SettlementPayoutBatch::submitForMonth($this->user('user', '관리'), '2026-10');   // 관리(1) → 업무관리자(2) → 대표(3)
        $this->assertSame(2, (int) $batch->current_level);

        $super = $this->user('super');
        $batch->approveBy($super);
        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertSame(3, (int) $batch->fresh()->current_level);

        $batch->fresh()->approveBy($super);   // 대표 칸까지 대행하면 그때 최종
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('paid', $s->fresh()->settlement_status);
    }

    // ── ⑧ ────────────────────────────────────────────────────────────────

    public function test_the_month_card_adds_the_margin_of_payout_excluded_accounts(): void
    {
        $sm = $this->freelancer();
        $this->confirmed($this->krwVehicle($sm), $sm);
        $sister = $this->freelancer('헤이맨', ['payout_excluded' => true]);
        $ex = $this->confirmed($this->krwVehicle($sister, 13_000_000), $sister);            // 완납 — 센다
        $this->confirmed($this->krwVehicle($sister, 13_000_000, 12_000_000), $sister);       // 미수 보류 — 안 센다(월결산 알림톡과 같은 모집단)
        $other = $this->confirmed($this->krwVehicle($sister, 13_000_000), $sister);
        $other->update(['attributed_month' => '2026-09-01']);                                 // 다른 달 — 안 센다

        $batch = SettlementPayoutBatch::submitForMonth($this->user('manager'), '2026-10');
        $this->assertNull($ex->fresh()->payout_batch_id, '전제 — 지급 제외 계정 정산은 배치에 안 실린다');

        $expected = (int) $ex->fresh()->company_net;
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $batch->excludedCompanyNet());

        $bd = $batch->breakdownForDisplay();
        $html = (string) $this->blade('<x-payout.totals :totals="$t" :excluded-net="$n" />', ['t' => $bd['totals'], 'n' => $expected]);
        $this->assertStringContainsString('data-excluded-net', $html);
        $final = (int) $bd['totals']['company_net'] + $expected;
        $this->assertStringContainsString('data-company-net-final>'.($final < 0 ? '−' : '').'₩'.number_format(abs($final)), $html);

        // 종전 출력 — 지급 제외 계정 정산이 없으면 줄이 없다
        $plain = (string) $this->blade('<x-payout.totals :totals="$t" />', ['t' => $bd['totals']]);
        $this->assertStringNotContainsString('data-excluded-net', $plain);
    }
}
