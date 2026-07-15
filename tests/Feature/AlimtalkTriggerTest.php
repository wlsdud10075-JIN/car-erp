<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 알림톡 자동발송 트리거 배선 — 수신자 resolver + cron 커맨드 + 이벤트 훅.
 * BizM 발송은 Http::fake 로 가로챔. 게이트 off 면 skipped, on 이면 sent 로그.
 */
class AlimtalkTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);
    }

    /** 알림톡 계정·게이트 켜기 (현재 회사 set). 전달한 코드의 tmplId 세팅. */
    private function enableAlimtalk(array $codes): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_itemlist_{$set}"], ['value' => '1', 'type' => 'boolean']);   // 아이템리스트형 게이트 on
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        foreach ($codes as $c) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$c}_{$set}"], ['value' => 'T_'.$c, 'type' => 'string']);
        }
    }

    private function admin(string $phone): User
    {
        // 운영 최고관리자처럼 role='관리'도 겸함 — 그래도 관리 알림엔 안 잡혀야(대표=요약만).
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    private function manager(string $phone): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    public function test_recipients_resolver_by_role(): void
    {
        $this->admin('010-1111-0000');
        User::factory()->create(['permission' => 'super', 'role' => '관리', 'phone' => '010-9999-0000', 'email_verified_at' => now()]);  // super 제외(role='관리' 겸해도)
        $this->manager('010-2222-0000');
        User::factory()->create(['permission' => 'manager', 'phone' => '010-4444-0000', 'email_verified_at' => now()]);  // 업무관리자
        User::factory()->create(['permission' => 'user', 'role' => '영업', 'phone' => '010-3333-0000', 'email_verified_at' => now()]);

        $this->assertSame(['010-1111-0000'], AlimtalkRecipients::admins(), '대표=admin만(super 제외)');
        // 대표(admin)·super 가 role='관리'를 겸해도 관리 알림엔 제외 — 관리 6종은 순수 관리/업무관리자만.
        $this->assertEqualsCanonicalizing(['010-2222-0000', '010-4444-0000'], AlimtalkRecipients::managers(), '관리 role + 업무관리자(manager), 대표·super 제외');
        $this->assertSame(['010-4444-0000'], AlimtalkRecipients::payoutApprovers(2), '배치 level2 = 업무관리자');
        $this->assertSame(['010-1111-0000'], AlimtalkRecipients::payoutApprovers(3), '배치 level3 = 대표');
    }

    public function test_recipients_override_setting_wins(): void
    {
        $this->admin('010-1111-0000');
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_recipients_admin_{$set}"], ['value' => '010-7777-7777, 010-8888-8888', 'type' => 'string']);

        $this->assertSame(['010-7777-7777', '010-8888-8888'], AlimtalkRecipients::admins());
    }

    public function test_daily_summary_sends_to_admins_when_enabled(): void
    {
        $this->admin('010-1111-0000');
        $this->enableAlimtalk(['erp_daily_summary']);

        $this->artisan('alimtalk:daily-summary')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_daily_summary')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('01011110000', $log->phone);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bizmsg.kr')
            && $req->data()[0]['tmplId'] === 'T_erp_daily_summary');
    }

    public function test_gate_off_skips_send(): void
    {
        $this->admin('010-1111-0000');   // 수신자는 있으나 게이트 미설정

        $this->artisan('alimtalk:daily-summary')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_daily_summary')->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_pickup_sends_only_to_vehicle_salesman(): void
    {
        $this->enableAlimtalk(['erp_pickup_reminder']);
        $sm = Salesman::create(['name' => '김영업', 'phone' => '010-5555-0000', 'is_active' => true]);
        // 매입일 3일 전 + 매입 미완납(지급 0)
        Vehicle::create([
            'vehicle_number' => '11가1234', 'sales_channel' => 'export',
            'purchase_price' => 5_000_000, 'purchase_date' => now()->subDays(3)->toDateString(),
            'salesman_id' => $sm->id,
        ]);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_pickup_reminder')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('01055550000', $log->phone);
    }

    public function test_pickup_skips_when_purchase_paid(): void
    {
        $this->enableAlimtalk(['erp_pickup_reminder']);
        $sm = Salesman::create(['name' => '박영업', 'phone' => '010-6666-0000', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2345', 'sales_channel' => 'export',
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(3)->toDateString(),
            'salesman_id' => $sm->id,
        ]);
        $v->purchaseBalancePayments()->create(['amount' => 1_000_000, 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_pickup_reminder')->count());
    }

    public function test_settle_pending_hook_notifies_managers_on_created(): void
    {
        $this->manager('010-2222-0000');
        $this->enableAlimtalk(['erp_settle_pending']);
        $v = Vehicle::create(['vehicle_number' => '33다3456', 'sales_channel' => 'export']);

        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'per_unit',
            'settlement_status' => 'pending',
        ]);

        $log = AlimtalkLog::where('template_code', 'erp_settle_pending')->first();
        $this->assertNotNull($log, '정산 pending 생성 시 관리 알림');
        $this->assertSame('sent', $log->status);
        $this->assertSame('01022220000', $log->phone);
    }

    public function test_sale_unpaid_sends_one_list_message_not_per_vehicle(): void
    {
        $this->manager('010-2222-0000');
        $this->enableAlimtalk(['erp_sale_unpaid']);
        $buyer = Buyer::create(['name' => 'DONI', 'is_active' => true]);
        foreach (['11가1111', '22나2222'] as $no) {
            Vehicle::create([
                'vehicle_number' => $no, 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
                'sale_price' => 10_000_000, 'sale_date' => now()->subDays(30)->toDateString(),
                'currency' => 'KRW', 'exchange_rate' => 1,
            ]);
        }

        $this->artisan('alimtalk:sale-unpaid')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_sale_unpaid')->get();
        $this->assertCount(1, $logs, '차량 2대여도 목록형 1건(건건이 아님)');
        $this->assertStringContainsString('11가1111', $logs[0]->message);
        $this->assertStringContainsString('22나2222', $logs[0]->message);
        $this->assertStringContainsString('채권관리', $logs[0]->message, 'ERP 위치 안내 포함');
    }

    public function test_all_trigger_template_codes_exist(): void
    {
        // 배선한 코드가 모두 템플릿 단일출처에 존재해야(오타 방지).
        foreach ([
            'erp_daily_summary', 'erp_weekly_summary', 'erp_monthly_closing',
            'erp_purchase_unpaid', 'erp_sale_unpaid', 'erp_eta_balance_due',
            'erp_shipping_due', 'erp_pickup_reminder', 'erp_vehicle_new', 'erp_settle_pending',
            'erp_payout_request', 'erp_payout_done', 'erp_payout_rejected',
        ] as $code) {
            $this->assertArrayHasKey($code, AlimtalkTemplates::TEMPLATES, $code.' 템플릿 존재');
        }
    }

    /** 월배치 정산지급 — 제출→계단 전진→최종 승인마다 상대측에 알림톡. */
    public function test_payout_batch_flow_notifies_each_party(): void
    {
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '김제출', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $mgr = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $adm = User::factory()->create(['permission' => 'admin', 'phone' => '010-3000-0000', 'email_verified_at' => now()]);
        $this->enableAlimtalk(['erp_payout_request', 'erp_payout_done']);

        $sm = Salesman::create(['name' => '제출영업', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '99가9999', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-06-15 10:00:00',
        ]);

        // 제출 → level2(업무관리자)에게 요청.
        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-06');
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_request')->where('phone', '01020000000')->where('status', 'sent')->count(), '제출→업무관리자 요청');

        // 업무관리자 승인 → level3(대표)에게 요청.
        $batch->approveBy($mgr);
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_request')->where('phone', '01030000000')->where('status', 'sent')->count(), '전진→대표 요청');

        // 대표 최종 승인 → 제출자에게 완료.
        $batch->approveBy($adm);
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_done')->where('phone', '01010000000')->where('status', 'sent')->count(), '최종→제출자 완료');
        $this->assertSame('approved', $batch->fresh()->status);
    }

    /** 반려 → 제출자에게 사유 포함 알림톡. */
    public function test_payout_batch_reject_notifies_submitter(): void
    {
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '박제출', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $mgr = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $this->enableAlimtalk(['erp_payout_request', 'erp_payout_rejected']);

        $sm = Salesman::create(['name' => '반려영업', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '88나8888', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-06-15 10:00:00',
        ]);

        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-06');
        $batch->rejectBy($mgr, '금액 오류');

        $log = AlimtalkLog::where('template_code', 'erp_payout_rejected')->first();
        $this->assertNotNull($log);
        $this->assertSame('01010000000', $log->phone, '반려는 제출자에게');
        // 사유는 아이템리스트형 카드(items)로 이동 — 발송 payload 로 확인(body 아님).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bizmsg.kr')
            && str_contains(json_encode($req->data()[0], JSON_UNESCAPED_UNICODE), '금액 오류'));
        $this->assertSame('rejected', $batch->fresh()->status);
    }
}
