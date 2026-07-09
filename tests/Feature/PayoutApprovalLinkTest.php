<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * 월배치 정산지급 — 카카오 알림톡 버튼 서명 링크로 대표 승인/반려 (2026-07-08).
 * 로그인 없이 서명 링크가 인가. GET=표시만, POST=처리(1회용·계단·상태 가드).
 */
class PayoutApprovalLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'X']]], 200)]);
    }

    /** manager 제출 → current_level=3(대표) · admin 이 그 계단 승인자. */
    private function pendingBatchWithAdmin(): array
    {
        $manager = User::factory()->create(['permission' => 'manager', 'phone' => '010-1111-0000', 'email_verified_at' => now()]);
        $admin = User::factory()->create(['permission' => 'admin', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '김영업', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-07-05', 'attributed_month' => '2026-07-01',
        ]);
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-07');

        return [$batch, $admin];
    }

    private function decideUrl(SettlementPayoutBatch $batch, User $u): string
    {
        return URL::temporarySignedRoute('payout.approve.decide', now()->addHour(), ['batch' => $batch->id, 'u' => $u->id]);
    }

    public function test_signed_show_renders_unsigned_forbidden(): void
    {
        [$batch, $admin] = $this->pendingBatchWithAdmin();

        $this->get($batch->approvalLinkFor($admin))->assertOk()
            ->assertSee('지급 총액')
            ->assertSee('담당자별 실지급')   // 누가 얼마 가져가는지
            ->assertSee('회사이익');          // 직원 지급 대비 회사 몫 (jin 2026-07-09)
        // 서명 없는 접근은 차단
        $this->get(route('payout.approve.show', ['batch' => $batch->id, 'u' => $admin->id]))->assertForbidden();
    }

    public function test_admin_approves_via_link_and_pays(): void
    {
        [$batch, $admin] = $this->pendingBatchWithAdmin();

        $this->post($this->decideUrl($batch, $admin), ['action' => 'approve'])->assertOk()->assertSee('승인 완료');

        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('paid', Settlement::first()->settlement_status);
    }

    public function test_reject_requires_reason_then_rejects(): void
    {
        [$batch, $admin] = $this->pendingBatchWithAdmin();

        // 사유 없이 반려 → 상태 변경 없이 재표시
        $this->post($this->decideUrl($batch, $admin), ['action' => 'reject', 'reason' => ''])->assertOk()->assertSee('사유');
        $this->assertSame('pending', $batch->fresh()->status);

        // 사유 넣어 반려 → rejected
        $this->post($this->decideUrl($batch, $admin), ['action' => 'reject', 'reason' => '금액 재확인 필요'])->assertOk();
        $this->assertSame('rejected', $batch->fresh()->status);
    }

    public function test_reuse_after_decided_is_blocked(): void
    {
        [$batch, $admin] = $this->pendingBatchWithAdmin();

        $this->post($this->decideUrl($batch, $admin), ['action' => 'approve'])->assertOk();
        // 재클릭 → 이미 처리됨(canDecide 가드)
        $this->post($this->decideUrl($batch, $admin), ['action' => 'approve'])->assertOk()->assertSee('처리');
        $this->assertSame('approved', $batch->fresh()->status);
    }

    public function test_payout_request_message_includes_company_profit(): void
    {
        // 카톡 메시지 본문에 회사이익 줄이 렌더되는지 (jin 2026-07-09) — 대표가 누르기 전에 미리 봄.
        $body = AlimtalkTemplates::render('erp_payout_request', [
            '귀속월' => '2026-07', '건수' => '8', '총액' => '2,850,000원',
            '회사이익' => '4,890,000원', '제출자' => '황진영',
        ]);
        $this->assertStringContainsString('회사이익: 4,890,000원', $body);
        $this->assertStringContainsString('지급 총액: 2,850,000원', $body);
    }

    public function test_non_approver_cannot_decide(): void
    {
        [$batch] = $this->pendingBatchWithAdmin();
        // manager(rank2)는 current_level=3 승인자 아님 → 처리 불가
        $manager = User::where('permission', 'manager')->first();

        $this->post($this->decideUrl($batch, $manager), ['action' => 'approve'])->assertOk()->assertSee('처리');
        $this->assertSame('pending', $batch->fresh()->status);
    }
}
