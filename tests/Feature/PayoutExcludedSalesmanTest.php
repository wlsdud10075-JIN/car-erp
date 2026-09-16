<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚪 **지급 대상이 아닌 담당자는 월배치에 안 들어간다** (jin 2026-09-16).
 *
 * 「헤이맨」처럼 **사람이 아닌 계정**(자매 회사)이 담당자로 들어간 건들이 있다.
 * 기록으로만 남기고 실지급이 0 원인데, 확정하면 월배치 대상에 **0 원 줄로 올라온다**.
 * 실측 ssancarerp 2026-08 배치 대상 **15건이 전부 그것**이었다(지급 합계 0원).
 *
 * 🚫 **금액(0원)으로 가르지 않았다** — 3사 0원 정산 58건 중 **39건은 진짜 사람의 정산**이다
 *    (프리랜서 서류비 5만원에 몫이 다 깎였거나, 사내직원 기준액이 건당 금액보다 작은 경우).
 *    그건 0원이어도 그 달 기록이라 재무가 확정하고 넘어가야 한다.
 *    게다가 **음수 지급(손실 분담)이 48건** 있어, 금액으로 가르면 그것까지 휩쓸린다.
 *    ⇒ 「얼마인가」가 아니라 **「누구인가」**로 가른다.
 */
class PayoutExcludedSalesmanTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function approver(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** 확정까지 간 정산 한 건 — 배치 대상 조건(확정·미배치·완납)을 갖춘다. */
    private function confirmedSettlement(bool $excluded, float $salePrice = 12_000_000, string $type = 'freelance'): Settlement
    {
        // ⚠️ 정산 자동생성은 로그인 상태에서만 돈다(시드·적재 대량유입 차단 가드) — 실제 경로를 그대로 탄다.
        $this->actingAs($this->approver());

        $sm = Salesman::create([
            'name' => 'S'.++$this->n, 'is_active' => true,
            'type' => $type, 'payout_excluded' => $excluded,
        ]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '42자'.str_pad((string) (5000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'purchase_price' => 8_000_000, 'sale_price' => $salePrice,
            'sale_date' => now()->startOfMonth()->toDateString(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => $v->fresh()->sale_total_amount,
            'payment_date' => now()->startOfMonth()->toDateString(), 'confirmed_at' => now()->startOfMonth(),
        ]);

        $s = Settlement::where('vehicle_id', $v->id)->firstOrFail();
        $s->forceFill([
            'settlement_status' => 'confirmed',
            'confirmed_at' => now(),
            'attributed_month' => now()->startOfMonth()->toDateString(),
        ])->save();

        return $s->fresh();
    }

    /** 🚨 이번 결함 그 자체 — 0원 줄이 월배치에 올라오던 것. */
    public function test_an_excluded_salesman_never_reaches_the_monthly_batch(): void
    {
        $excluded = $this->confirmedSettlement(excluded: true);
        $normal = $this->confirmedSettlement(excluded: false);

        $ids = SettlementPayoutBatch::eligibleSettlementIds(now()->format('Y-m'));

        $this->assertTrue($ids->contains($normal->id), '전제가 안 선다 — 평범한 정산은 배치 대상이어야 한다');
        $this->assertFalse($ids->contains($excluded->id),
            '지급 대상 아닌 담당자의 정산이 월배치에 들어갔다');
    }

    /**
     * 🚫 **0원이라고 빼지 않는다** — 실지급 0원인 진짜 사람의 정산은 그대로 배치에 들어간다.
     * (프리랜서 서류비 5만원에 몫이 다 깎이는 경우 — 실측 3사 39건)
     */
    public function test_a_real_persons_zero_payout_settlement_still_joins_the_batch(): void
    {
        // 손해 차량인 사내직원 — 총마진이 음수면 건당 tier 가 0 을 돌려준다(음수 지급이 아니라 정확히 0).
        //   ⚠️ 프리랜서로 만들면 서류비 5만원 때문에 **음수**가 나와 이 케이스가 안 된다.
        $s = $this->confirmedSettlement(excluded: false, salePrice: 6_000_000, type: 'employee');

        $this->assertSame(0, $s->actual_payout, '전제가 안 선다 — 실지급이 정확히 0원이어야 한다');
        $this->assertTrue(
            SettlementPayoutBatch::eligibleSettlementIds(now()->format('Y-m'))->contains($s->id),
            '실지급 0원이라는 이유로 진짜 사람의 정산이 배치에서 빠졌다'
        );
    }

    /** ⚠️ 이미 배치에 들어가 지급이 끝난 건은 **소급해서 빠지지 않는다** — 과거 배치 구성이 바뀌면 안 된다. */
    public function test_an_already_batched_settlement_is_not_excluded_retroactively(): void
    {
        $s = $this->confirmedSettlement(excluded: true);
        $batch = SettlementPayoutBatch::create([
            'month' => now()->format('Y-m'), 'status' => 'approved',
            'submitter_id' => $this->approver()->id, 'submitter_rank' => 'manager',
            'current_level' => 1, 'submitted_at' => now(), 'decided_at' => now(),
            'settlement_count' => 1, 'total_payout' => 0,
        ]);
        $s->forceFill(['payout_batch_id' => $batch->id])->save();

        $this->assertFalse($s->fresh()->isPayoutExcludedBySalesman(),
            '지급이 끝난 건이 소급해서 「지급 대상 아님」이 됐다');
    }

    /** 🖥️ 화면이 말한다 — 표시가 없으면 「확정했는데 왜 지급이 안 되지」가 된다(§8 #60). */
    public function test_the_settlement_list_marks_the_excluded_one(): void
    {
        $excluded = $this->confirmedSettlement(excluded: true);

        $html = Volt::actingAs($this->approver())->test('erp.settlements.index')
            ->set('monthFilter', '')
            ->html();

        $this->assertTrue(str_contains($html, $excluded->vehicle->vehicle_number), '전제가 안 선다 — 목록에 있어야 한다');
        $this->assertTrue(str_contains($html, '지급 대상 아님'),
            '배치에서 빠지는 이유가 화면에 안 보인다');
    }

    /** 🖥️ 영업담당자 화면에서 켜고 끌 수 있다 — 코드에만 있으면 사람이 반대로 조작한다(§8 #60). */
    public function test_the_toggle_is_on_the_salesman_screen_and_saves(): void
    {
        $sm = Salesman::create(['name' => '자매회사', 'is_active' => true, 'type' => 'employee']);

        $c = Volt::actingAs($this->approver())->test('erp.salesmen.index')->call('openEdit', $sm->id);

        $this->assertTrue(str_contains($c->html(), '정산 지급 대상 아님'), '체크칸이 화면에 없다');

        $c->set('payout_excluded', true)->call('save');

        $this->assertTrue((bool) $sm->fresh()->payout_excluded, '체크해도 저장이 안 된다');
    }

    /** 💰 돈이 나가고 안 나가고를 가르는 스위치라 **누가 켰는지 남는다**. */
    public function test_flipping_the_toggle_is_written_to_the_audit_log(): void
    {
        $sm = Salesman::create(['name' => '자매회사2', 'is_active' => true, 'type' => 'employee']);

        Volt::actingAs($this->approver())->test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('payout_excluded', true)
            ->call('save');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Salesman::class,
            'auditable_id' => $sm->id,
            'column_name' => 'payout_excluded',
        ]);
    }

    /**
     * 🔒 **세 곳이 같은 술어를 본다** — 배치·스코프·뱃지.
     * 하나만 빠지면 「목록엔 빠졌다고 뜨는데 배치엔 들어가는」 형태가 된다(§8 #44).
     */
    public function test_the_batch_uses_the_shared_predicate(): void
    {
        $src = file_get_contents(base_path('app/Models/SettlementPayoutBatch.php'));

        $this->assertTrue(str_contains($src, 'isPayoutExcludedBySalesman()'),
            '월배치가 단일 출처를 안 쓴다');
        $this->assertFalse(str_contains($src, "where('payout_excluded'"),
            '월배치가 조건을 옮겨 적고 있다 — 뱃지와 갈린다');

        // SQL 스코프도 같은 답을 해야 한다.
        $excluded = $this->confirmedSettlement(excluded: true);
        $normal = $this->confirmedSettlement(excluded: false);
        $scoped = Settlement::query()->payoutExcludedSalesman()->pluck('id');

        $this->assertTrue($scoped->contains($excluded->id), '스코프가 대상을 못 잡는다');
        $this->assertFalse($scoped->contains($normal->id), '스코프가 평범한 정산까지 잡는다');
    }
}
