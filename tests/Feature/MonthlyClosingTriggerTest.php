<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 월 결산 알림톡 발송 시점 (jin 2026-07-31).
 *
 * > "월 결산요약이 정산이 컨펌나고 나서 나가야 할 것 같은데? 정산 전부 완료되기 전에는 나갈 수 없지 않나?"
 *
 * 종전엔 익월 첫 영업일에 무조건 나가서, 아직 확정 전인 정산이 통째로 빠진 채 보고됐다.
 * 이제 월배치 정산이 **최종 승인된 달만** 나간다.
 */
class MonthlyClosingTriggerTest extends TestCase
{
    use RefreshDatabase;

    private string $month = '2026-06';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 대표(수신자) — 없으면 커맨드가 그냥 skip 해서 아무것도 검증되지 않는다.
        $this->admin = User::factory()->create(['permission' => 'admin', 'phone' => '01011112222', 'email_verified_at' => now()]);
    }

    /** 승인 완료된 월배치 — 마감 신호. */
    private function approvedBatch(int $count = 1, int $payout = 100_000): SettlementPayoutBatch
    {
        return SettlementPayoutBatch::create([
            'month' => $this->month, 'submitter_id' => $this->admin->id, 'submitter_rank' => 1,
            'current_level' => 3, 'status' => SettlementPayoutBatch::STATUS_APPROVED,
            'total_payout' => $payout, 'settlement_count' => $count, 'submitted_at' => now(), 'decided_at' => now(),
        ]);
    }

    /** 그 달 귀속 확정 정산 1건 (attributed_month 앵커 = 배치와 동일). */
    private function confirmedSettlement(): Settlement
    {
        $buyer = Buyer::create(['name' => 'MC-B', 'is_active' => true]);
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'MC-1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id, 'purchase_date' => '2026-05-01', 'purchase_price' => 5_000_000,
            'sale_date' => '2026-06-01', 'sale_price' => 10_000_000, 'currency' => 'KRW', 'exchange_rate' => 1,
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_status' => 'confirmed', 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'confirmed_at' => '2026-06-20 10:00:00', 'attributed_month' => '2026-06-01 00:00:00',
        ]);
    }

    /** 🚨 핵심 — 정산이 아직 승인 전이면 보고가 나가면 안 된다. */
    public function test_no_send_while_the_month_is_not_closed(): void
    {
        $this->confirmedSettlement();

        $this->artisan('alimtalk:monthly-closing', ['month' => $this->month])->assertExitCode(0);

        $this->assertSame(0, AlimtalkLog::where('status', 'sent')->count(), '마감 전에는 발송하면 안 됩니다.');
        $log = AlimtalkLog::where('template_code', 'erp_monthly_closing')->latest('id')->first();
        $this->assertNotNull($log, '왜 안 나갔는지는 로그 화면에 남아야 합니다.');
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('최종 승인되지 않아', (string) $log->error);
        $this->assertNull(Setting::get(AlimtalkMonthlyClosing::sentKey($this->month)));
    }

    /** 승인되면 그때 나간다 — 게이트가 열린다. */
    public function test_it_sends_once_the_batch_is_approved(): void
    {
        $this->confirmedSettlement();
        $this->approvedBatch();

        $this->artisan('alimtalk:monthly-closing', ['month' => $this->month])->assertExitCode(0);

        // 알림톡 계정 미설정이라 실제 발송은 skipped 지만, **게이트는 통과**해 send() 까지 갔는지 본다.
        $log = AlimtalkLog::where('template_code', 'erp_monthly_closing')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNotSame('최종 승인되지 않아', (string) $log->error);
        $this->assertStringNotContainsString('대기 중', (string) $log->error ?: '');
    }

    /** 정산이 0건인 달은 "전부 확정"이 자동으로 참이 되어 마진 0원짜리 보고가 나간다 — 막는다. */
    public function test_zero_settlement_month_is_not_reported(): void
    {
        $this->approvedBatch(0, 0);

        $this->artisan('alimtalk:monthly-closing', ['month' => $this->month])->assertExitCode(0);

        $log = AlimtalkLog::where('template_code', 'erp_monthly_closing')->latest('id')->first();
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('보고할 결산이 없음', (string) $log->error);
    }

    /** 매일 도는 재시도가 같은 달을 두 번 보내면 안 된다. */
    public function test_it_does_not_send_the_same_month_twice(): void
    {
        Setting::updateOrCreate(
            ['key' => AlimtalkMonthlyClosing::sentKey($this->month)],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        $this->artisan('alimtalk:monthly-closing', ['month' => $this->month])->assertExitCode(0);

        $this->assertSame(0, AlimtalkLog::count(), '이미 보낸 달은 로그도 남기지 않고 조용히 넘어갑니다.');
    }

    /** 집계 축이 배치와 같아야 한다 — attributed_month 기준으로 잡히는지. */
    public function test_aggregate_uses_the_same_anchor_as_the_batch(): void
    {
        $s = $this->confirmedSettlement();

        $found = AlimtalkMonthlyClosing::settlementsFor($this->month);
        $this->assertTrue($found->contains('id', $s->id), 'attributed_month 로 잡혀야 합니다.');

        $vars = AlimtalkMonthlyClosing::buildVars($this->month);
        $this->assertSame('2026년 6월분', $vars['대상월']);
        $this->assertStringContainsString('홍길동', $vars['인원별지급']);
    }
}
