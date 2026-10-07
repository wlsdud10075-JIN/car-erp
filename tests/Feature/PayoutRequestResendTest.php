<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📨 **월배치 승인요청 재전송 버튼** (jin 2026-10-07 「재전송 버튼을 만들긴 해야겠다. 매번 내가 수동으로 해줄 순 없겠네」).
 *
 * 실사례: heymanerp 2026-09 배치(#4)의 승인요청이 10-02 에 대표에게 전달됐는데 승인이 안 돼 jin 이 손으로 재발송했다.
 * 규칙: 승인 대기 배치만 · 제출 권한자([관리]·업무관리자)만 · **현재 승인 계단**에게만 · 마지막 발송 뒤 10분은 잠김 · 감사로그.
 */
class PayoutRequestResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);
        $set = Setting::companyTemplateSet();
        foreach ([
            "alimtalk_enabled_{$set}" => '1', "alimtalk_itemlist_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'uid', "alimtalk_profile_{$set}" => 'prof',
            "alimtalk_tmpl_erp_payout_request_{$set}" => 'T_REQ', "alimtalk_tmpl_erp_payout_done_{$set}" => 'T_DONE',
        ] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
    }

    /** [관리] 제출 → 업무관리자(2) → 대표(3). 반환: [배치, 제출자, 업무관리자, 대표]. */
    private function pendingBatch(): array
    {
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $mgr = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $adm = User::factory()->create(['permission' => 'admin', 'phone' => '010-3000-0000', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '영업', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '99가9999', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-09-15 10:00:00', 'attributed_month' => '2026-09-01',
        ]);

        return [SettlementPayoutBatch::submitForMonth($submitter, '2026-09'), $submitter, $mgr, $adm];
    }

    private function requestsTo(string $phone): int
    {
        return AlimtalkLog::where('template_code', 'erp_payout_request')->where('phone', $phone)->where('status', 'sent')->count();
    }

    /** 제출이 발송 시각을 찍고, 10분 뒤 재전송하면 **현재 계단**(대표)에게만 한 통 더 간다 + 감사로그. */
    public function test_resend_goes_to_the_current_step_only_and_is_audited(): void
    {
        [$batch, $submitter, $mgr] = $this->pendingBatch();
        $this->assertNotNull($batch->fresh()->request_notified_at, '제출이 발송 시각을 안 찍었다');
        $batch->approveBy($mgr);   // → 대표 단계, 대표에게 1통
        $this->assertSame(1, $this->requestsTo('01030000000'));

        $this->travel(11)->minutes();
        $this->actingAs($submitter);
        Volt::test('erp.payout-batches.index')->call('resendRequest', $batch->id)
            ->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'success');

        $this->assertSame(2, $this->requestsTo('01030000000'), '대표에게 다시 안 갔다');
        $this->assertSame(1, $this->requestsTo('01020000000'), '이미 승인한 업무관리자에게 또 갔다');
        $this->assertSame(1, AuditLog::where('action', 'payout_request_resent')->where('auditable_id', $batch->id)->count());
        $this->assertSame('pending', $batch->fresh()->status, '재전송이 배치를 바꿨다');
    }

    /** 🚫 10분 안에는 잠긴다 — 버튼 비활성 + 밀어 넣어도 모델이 막는다. 지나면 풀린다. */
    public function test_resend_is_locked_for_ten_minutes_after_any_send(): void
    {
        [$batch, $submitter] = $this->pendingBatch();
        $this->actingAs($submitter);
        $this->assertGreaterThan(0, $batch->fresh()->resendWaitMinutes());

        $c = Volt::test('erp.payout-batches.index');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-resend-request|data-resend-request[^>]*disabled/u', $c->html(), '잠긴 버튼이 비활성으로 안 그려졌다');
        $c->call('resendRequest', $batch->id)->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'warning');
        $this->assertSame(1, $this->requestsTo('01020000000'), '대기 시간 안에 또 보냈다');

        $this->travel(601)->seconds();
        $this->assertSame(0, $batch->fresh()->resendWaitMinutes());
    }

    /** 🚫 제출 권한 없는 사람(재무·대표)은 못 보낸다 · 버튼도 안 보인다 · 승인 끝난 배치는 대상 아님. */
    public function test_only_submitters_and_only_pending_batches(): void
    {
        [$batch, , $mgr, $adm] = $this->pendingBatch();
        $this->travel(11)->minutes();

        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $this->expectExceptionObject(new \DomainException(__('payout_batch.resend.forbidden')));
        try {
            $batch->fresh()->resendPayoutRequest($finance);
        } finally {
            // 대표(최고관리자)는 받는 사람 — 버튼 없음(jin 2026-10-07 「관리, 업무관리자만」)
            $this->actingAs($adm);
            $this->assertStringNotContainsString('data-resend-request', Volt::test('erp.payout-batches.index')->html(), '최고관리자 화면에 재전송 버튼이 보인다');
            $batch->approveBy($mgr);
            $batch->approveBy($adm);
            $this->assertSame('approved', $batch->fresh()->status);
        }
    }

    /** 🚫 시스템관리자(super)는 버튼도 권한도 없다 — 서버에서 직접 보낸다(jin 2026-10-07 「관리, 업무관리자만」). */
    public function test_super_admin_has_no_resend_button(): void
    {
        [$batch] = $this->pendingBatch();
        $this->travel(11)->minutes();
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);

        $c = Volt::test('erp.payout-batches.index');
        $this->assertStringNotContainsString('data-resend-request', $c->html(), 'super 화면에 버튼이 보인다');
        $c->call('resendRequest', $batch->id)->assertDispatched('notify', fn ($n, $p) => ($p['type'] ?? '') === 'warning');
        $this->assertSame(1, $this->requestsTo('01020000000'), 'super 가 보냈다');
    }

    /** 카드에 마지막 발송 시각과 결과 한 줄 — 전달 보고가 오면 「전달됨」, 실패면 「발송 실패」. */
    public function test_the_card_shows_last_send_time_and_delivery_result(): void
    {
        [$batch, $submitter] = $this->pendingBatch();
        $this->actingAs($submitter);

        $html = Volt::test('erp.payout-batches.index')->html();
        $this->assertMatchesRegularExpression('/data-request-last-sent[\s\S]{0,300}?'.preg_quote(__('payout_batch.resend.delivery.sent'), '/').'/u', $html);

        AlimtalkLog::where('template_code', 'erp_payout_request')->update(['report_status' => 'delivered']);
        $this->assertSame('delivered', $batch->fresh()->lastRequestDelivery());
        $this->assertMatchesRegularExpression('/data-request-last-sent[\s\S]{0,300}?'.preg_quote(__('payout_batch.resend.delivery.delivered'), '/').'/u',
            Volt::test('erp.payout-batches.index')->html());

        AlimtalkLog::where('template_code', 'erp_payout_request')->update(['status' => 'failed', 'report_status' => null]);
        $this->assertSame('failed', $batch->fresh()->lastRequestDelivery());
    }
}
