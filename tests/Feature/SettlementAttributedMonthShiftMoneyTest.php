<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💰 **귀속월 이동이 돈을 건드리지 않는다 — 끝까지** (jin 2026-10-06 «돈이 걸린 문제라 ERP 에서 문제가 되면 안 되고 board 미러도 본다»).
 *
 * 한 정산을 10월 귀속(11/10 지급)으로 만들어 9월(10/10 지급)로 당긴 뒤, 그 정산이 지나가는 길을 전부 밟는다:
 *   금액(정산액·실지급액·회사몫) 불변 → 월결산 집계·화면 월 필터·엑셀 스코프가 9월만 가리킴 → 9월 배치에 들어가 승인·지급
 *   → 10월 배치엔 안 잡힘(이중 지급 없음) → 지급 뒤에도 금액 그대로 → board 미러(포털 API) 가 같은 금액·같은 달을 봄
 *   → 마감된 9월로는 다른 정산을 더 못 당김. 이월(캐리오버)·2차 정산 상태도 종전 그대로.
 */
class SettlementAttributedMonthShiftMoneyTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
    }

    private function user(string $perm, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role, 'email_verified_at' => now()]);
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    /** 9월에 팔고 10월 3일에 완납된 차 + 10월 귀속으로 확정된 프리랜서(50%) 정산. */
    private function octoberSettlement(Salesman $sm, string $plate): Settlement
    {
        $buyer = Buyer::firstOrCreate(['name' => 'BUYER'], ['is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'buyer_id' => $buyer->id, 'export_buyer_id' => $buyer->id, 'bl_buyer_id' => $buyer->id,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => 10_000, 'sale_date' => '2026-09-05',
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1400,
            'payment_date' => '2026-10-03', 'confirmed_at' => '2026-10-03 10:00:00',
        ]);
        $v->refresh()->refreshCaches();
        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount, '완납이어야 지급 게이트를 통과한다');

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-03 11:00:00', 'attributed_month' => '2026-10-01',
        ]);
    }

    public function test_pulling_a_settlement_back_a_month_moves_the_payout_month_without_touching_the_money(): void
    {
        $sm = Salesman::create(['name' => '프리랜서', 'email' => 'free@test.local', 'type' => 'freelance', 'is_active' => true]);
        $s = $this->octoberSettlement($sm, '58저0778');

        // ── 0. 이동 전 금액 캡처 (전부 computed — 10월 귀속 상태)
        $before = [
            'total_margin' => $s->total_margin, 'settlement_amount' => $s->settlement_amount,
            'actual_payout' => $s->actual_payout, 'company_net' => $s->company_net,
            'carryover_in' => $s->carryover_in_krw, 'secondary' => $s->secondary_status,
        ];
        $this->assertGreaterThan(0, $before['actual_payout'], '픽스처가 실제 지급액을 만들어야 검증이 뜻이 있다');

        // ── 1. 재무가 드로어에서 「◀ 직전 달로」
        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'success' && str_contains($p['message'], '2026-10-10'));
        $s->refresh();
        $this->assertSame('2026-09-01', $s->attributed_month->format('Y-m-d'));

        // ── 2. 돈은 한 푼도 안 움직였다 · 상태·확정일·이월·2차도 그대로
        $this->assertSame($before['total_margin'], $s->total_margin);
        $this->assertSame($before['settlement_amount'], $s->settlement_amount);
        $this->assertSame($before['actual_payout'], $s->actual_payout);
        $this->assertSame($before['company_net'], $s->company_net);
        $this->assertSame($before['carryover_in'], $s->carryover_in_krw);
        $this->assertSame($before['secondary'], $s->secondary_status);
        $this->assertSame('confirmed', $s->settlement_status);
        $this->assertSame('2026-10-03 11:00:00', $s->confirmed_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLog::where('auditable_type', Settlement::class)->where('auditable_id', $s->id)->where('column_name', 'attributed_month')->count());

        // ── 3. 「그 달 정산」을 묻는 세 곳이 전부 9월이라고 답한다 (월결산 집계 · 엑셀 스코프 · 화면 월 필터)
        $this->assertTrue(AlimtalkMonthlyClosing::settlementsFor('2026-09')->contains('id', $s->id), '9월 월결산 집계에 들어가야 한다');
        $this->assertFalse(AlimtalkMonthlyClosing::settlementsFor('2026-10')->contains('id', $s->id), '10월 집계에 남아 있으면 이중 집계');
        $this->assertTrue(Settlement::query()->attributedMonth('2026-09')->whereKey($s->id)->exists());
        $this->assertFalse(Settlement::query()->attributedMonth('2026-10')->whereKey($s->id)->exists());
        $gwanri = $this->user('user', '관리');
        $this->actingAs($gwanri);
        Volt::test('erp.settlements.index')->set('monthFilter', '2026-09')->assertSee('58저0778');
        Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->assertDontSee('58저0778');

        // ── 4. 9월 배치에 들어가 지급된다 — 총액 = 이 정산의 실지급액. 10월 배치 후보엔 없다(이중 지급 없음)
        $this->assertTrue(SettlementPayoutBatch::eligibleSettlementIds('2026-09')->contains($s->id));
        $this->assertFalse(SettlementPayoutBatch::eligibleSettlementIds('2026-10')->contains($s->id));
        $batch = SettlementPayoutBatch::submitForMonth($gwanri, '2026-09');
        $this->assertSame(1, $batch->settlement_count);
        $this->assertSame($before['actual_payout'], (int) $batch->total_payout, '배치 총액 = 이동 전 실지급액');
        $batch->approveBy($this->user('super'));   // super override = 최종 승인 + 일괄 paid
        $batch->refresh();
        $s->refresh();
        $this->assertSame(SettlementPayoutBatch::STATUS_APPROVED, $batch->status);
        $this->assertSame('paid', $s->settlement_status);
        $this->assertSame($batch->id, $s->payout_batch_id);
        $this->assertNotNull($s->paid_at);
        $this->assertSame($before['actual_payout'], $s->actual_payout, '지급 뒤에도 금액 그대로');
        $this->assertSame($before['actual_payout'], (int) ($s->confirmed_snapshot['actual_payout'] ?? -1), '박제 스냅샷도 같은 금액');
        $this->assertSame('pending', $s->secondary_status, '2차 정산 대기 진입은 종전과 같다');
        $this->assertFalse(SettlementPayoutBatch::eligibleSettlementIds('2026-10')->contains($s->id), '지급된 정산이 10월 배치에 또 잡히면 이중 지급');

        // ── 5. board 미러(포털 API) — 담당자가 보는 금액·달이 ERP 와 같다
        $res = $this->signedGet('/api/internal/board/settlements', ['salesman_email' => 'free@test.local'])->assertOk()->json('data');
        $this->assertCount(1, $res);
        $this->assertSame($before['actual_payout'], (int) $res[0]['actual_payout']);
        $this->assertSame('paid', $res[0]['status']);
        $mirror = $this->signedGet('/api/internal/board/payout-batches', ['salesman_email' => 'free@test.local'])->assertOk()->json('data');
        $this->assertCount(1, $mirror);
        $this->assertSame('2026-09', $mirror[0]['month'], 'board 는 9월 배치(10/10 지급)로 본다');
        $this->assertSame($before['actual_payout'], (int) $mirror[0]['settlement_total']);
        $this->assertSame('58저0778', $mirror[0]['settlements'][0]['vehicle_number']);

        // ── 6. 지급된 정산은 더 못 옮기고, 마감된 9월로는 다른 정산도 못 당긴다
        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.settlements.index')->call('openEdit', $s->id)->call('shiftAttributedMonth', 1)
            ->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'error');
        $this->assertSame('2026-09-01', $s->fresh()->attributed_month->format('Y-m-d'));

        $late = $this->octoberSettlement($sm, '16머0394');
        Volt::test('erp.settlements.index')->call('openEdit', $late->id)->call('shiftAttributedMonth', -1)
            ->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'error' && str_contains($p['message'], '마감'));
        $this->assertSame('2026-10-01', $late->fresh()->attributed_month->format('Y-m-d'));
        $this->assertTrue(SettlementPayoutBatch::eligibleSettlementIds('2026-10')->contains($late->id), '늦은 건은 10월 배치(11/10)로 정상 진행');
    }
}
