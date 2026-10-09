<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🪜 월정산 v3 결재선 (jin 2026-10-08) — 업무관리자 상신 → 부장 → 전무 → 대표. 6일차 (2026-10-09).
 *
 * - 모드는 **배치 단위**: steps 행이 있으면 결재선, 없으면 종전 사다리. 직급을 아무도 안 넣은 회사는 종전과 똑같다.
 * - 각 칸 차례가 될 때 그 사람에게 알림톡 1회(수신자 = currentApprovers). 대표는 중간 결재가 끝난 직후.
 * - 결재 중 인센티브 추가 = 변경 이력 + 총액 재계산, step 포인터는 그대로(멈춘 단계부터 이어서).
 * - 반려 = 정산을 풀기 전에 카드 박제(내용 보존).
 */
class PayoutApprovalStepsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0101234'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now(),
        ]);
    }

    private function confirmedSettlement(string $month = '2026-10'): Settlement
    {
        $sm = Salesman::create(['name' => '담당'.++$this->n, 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $v = Vehicle::create([
            'vehicle_number' => 'ST'.$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => $month.'-01',
            'sale_price' => 20_000, 'sale_date' => $month.'-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 20_000, 'exchange_rate' => 1400, 'payment_date' => $month.'-10', 'confirmed_at' => now()]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => $month.'-15', 'attributed_month' => $month.'-01',
        ]);
    }

    // ── 종전 사다리는 그대로 ───────────────────────────────────────────────

    public function test_without_titles_the_legacy_ladder_is_untouched(): void
    {
        $this->confirmedSettlement();
        $manager = $this->user('manager');
        $admin = $this->user('admin');   // 직급 없음

        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10');
        $this->assertFalse($batch->isStepsMode());
        $this->assertSame(0, $batch->steps()->count());
        $this->assertSame(3, (int) $batch->current_level);
        $this->assertNull($batch->current_step);
        $this->assertSame([$admin->id], $batch->currentApprovers()->pluck('id')->all(), '종전 = 그 계단(admin) 전원');
        $this->assertTrue($batch->canDecide($admin));

        $batch->approveBy($admin);
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('paid', Settlement::first()->settlement_status);
        $this->assertNotNull($batch->fresh()->breakdown_snapshot, '최종 승인도 박제한다');
    }

    // ── 결재선 ───────────────────────────────────────────────────────────

    public function test_steps_run_in_order_and_each_turn_notifies_only_that_person(): void
    {
        $this->confirmedSettlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $jeon = $this->user('admin', '관리', '전무');
        $ceo = $this->user('admin', '관리', '대표');
        $otherAdmin = $this->user('admin');   // 직급 없는 최고관리자 — 결재선 밖

        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '전무' => $jeon->id, '대표' => $ceo->id]);

        $this->assertTrue($batch->isStepsMode());
        $this->assertSame(['부장', '전무', '대표'], $batch->steps->pluck('title')->all());
        $this->assertSame(1, (int) $batch->current_step);
        $this->assertSame(SettlementPayoutBatch::TOP_RANK, (int) $batch->current_level, '종전 소비자가 null 을 안 보게 TOP_RANK');
        $this->assertSame([$bu->id], $batch->currentApprovers()->pluck('id')->all(), '첫 차례 = 부장 한 사람');
        $this->assertTrue($batch->canDecide($bu));
        $this->assertFalse($batch->canDecide($jeon), '전무는 아직 차례가 아니다');
        $this->assertFalse($batch->canDecide($ceo));
        $this->assertFalse($batch->canDecide($otherAdmin), '결재선 밖 최고관리자는 결재 못 한다');
        $this->assertSame(1, SettlementPayoutBatch::query()->awaitingDecisionBy($bu)->count());
        $this->assertSame(0, SettlementPayoutBatch::query()->awaitingDecisionBy($ceo)->count(), '사이드바 뱃지도 같은 판정');

        $batch->approveBy($bu, '확인했습니다');
        $batch = $batch->fresh();
        $this->assertSame('pending', $batch->status);
        $this->assertSame(2, (int) $batch->current_step);
        $this->assertSame('approved', $batch->steps()->where('seq', 1)->value('status'));
        $this->assertSame('확인했습니다', $batch->steps()->where('seq', 1)->value('note'), '한 줄 의견이 칸에 남는다');
        $this->assertSame([$jeon->id], $batch->currentApprovers()->pluck('id')->all());

        $batch->approveBy($jeon);
        $batch = $batch->fresh();
        $this->assertSame(3, (int) $batch->current_step);
        $this->assertSame([$ceo->id], $batch->currentApprovers()->pluck('id')->all(), '중간 결재가 끝난 직후 대표 차례');

        $batch->approveBy($ceo);
        $batch = $batch->fresh();
        $this->assertSame('approved', $batch->status);
        $this->assertNull($batch->current_step);
        $this->assertSame('paid', Settlement::first()->settlement_status);
    }

    public function test_skipped_titles_make_no_steps_and_ceo_is_required(): void
    {
        $this->confirmedSettlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');

        // 부장만 넣으면 → 부장 → 대표 두 칸(전무 건너뜀)
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '전무' => null, '대표' => $ceo->id]);
        $this->assertSame(['부장', '대표'], $batch->steps->pluck('title')->all());
        $batch->approveBy($bu);
        $this->assertSame([$ceo->id], $batch->fresh()->currentApprovers()->pluck('id')->all(), '부장이 결재하면 바로 대표');

        // 대표 없는 결재선은 거부
        $this->confirmedSettlement('2026-11');
        $this->expectException(\DomainException::class);
        SettlementPayoutBatch::submitForMonth($manager, '2026-11', [], ['부장' => $bu->id]);
    }

    public function test_a_chosen_approver_must_hold_that_title(): void
    {
        $this->confirmedSettlement();
        $manager = $this->user('manager');
        $notBu = $this->user('admin', '관리', '전무');
        $ceo = $this->user('admin', '관리', '대표');
        $this->expectException(\DomainException::class);
        SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $notBu->id, '대표' => $ceo->id]);
    }

    // ── 결재 중 수정 ─────────────────────────────────────────────────────

    public function test_mid_flow_incentive_keeps_the_step_and_records_a_change(): void
    {
        $s = $this->confirmedSettlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);
        $batch->approveBy($bu);
        $before = (int) $batch->fresh()->total_payout;

        // 대표 차례인데 부장(이미 결재)이 인센티브를 더한다 — 포인터는 그대로, 총액·이력만
        $batch = $batch->fresh();
        $adj = $batch->addIncentive($bu, $s->salesman_id, 300_000, '신규 바이어 개척');
        $batch = $batch->fresh();
        $this->assertSame(SettlementPayoutAdjustment::KIND_INCENTIVE, $adj->kind);
        $this->assertSame($before + 300_000, (int) $batch->total_payout);
        $this->assertSame(2, (int) $batch->current_step, '수정해도 멈춘 단계부터 이어서 — 포인터 불변');
        $this->assertSame([$ceo->id], $batch->currentApprovers()->pluck('id')->all());
        $change = $batch->batchChanges()->first();
        $this->assertSame('incentive', $change->field);
        $this->assertSame(0, $change->before);
        $this->assertSame(300_000, $change->after);
        $this->assertSame([$s->salesman_id => ['incentive']], $batch->changedFieldsBySalesman(), '카드 노란 표시의 출처');

        $batch->removeIncentive($bu, $adj->id);
        $this->assertSame($before, (int) $batch->fresh()->total_payout);
        $this->assertSame(2, $batch->batchChanges()->count(), '삭제도 이력');

        // 결재선 밖·제출 권한 없는 사람은 못 고친다
        $finance = $this->user('user', '재무');
        $this->expectException(\DomainException::class);
        $batch->fresh()->addIncentive($finance, $s->salesman_id, 1, 'x');
    }

    public function test_incentive_kind_passes_through_submit(): void
    {
        $s = $this->confirmedSettlement();
        $manager = $this->user('manager');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [
            ['salesman_id' => $s->salesman_id, 'amount' => 500_000, 'reason' => '10월 실적 우수', 'kind' => 'incentive'],
            ['salesman_id' => $s->salesman_id, 'amount' => 10_000, 'reason' => '수기'],
        ]);
        $this->assertSame(['incentive', 'manual'], $batch->adjustments()->orderBy('id')->pluck('kind')->all());
    }

    // ── 반려 보존 ─────────────────────────────────────────────────────────

    public function test_reject_snapshots_the_cards_before_detaching_settlements(): void
    {
        $s = $this->confirmedSettlement();
        $manager = $this->user('manager');
        $bu = $this->user('admin', '관리', '부장');
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10', [], ['부장' => $bu->id, '대표' => $ceo->id]);

        $batch->rejectBy($bu, '인센티브 재검토');
        $batch = $batch->fresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertNull($s->fresh()->payout_batch_id, '정산은 풀려서 새 월정산으로 다시 올라갈 수 있다');
        $this->assertSame('rejected', $batch->steps()->where('seq', 1)->value('status'));

        $snap = $batch->breakdown_snapshot;
        $this->assertIsArray($snap);
        $this->assertCount(1, $snap['people'], '반려돼도 제출 당시 사람 카드가 남는다');
        $this->assertSame($s->salesman_id, $snap['people'][0]['salesman_id']);
        $this->assertSame(1, $snap['totals']['vehicles']);
        $this->assertSame($snap, $batch->breakdownForDisplay(), '끝난 배치는 박제로 그린다');
    }
}
