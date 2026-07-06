<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
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
        Http::fake(['*bizmsg.kr*' => Http::response([['msgid' => 'MSG-TEST']], 200)]);
    }

    /** 알림톡 계정·게이트 켜기 (현재 회사 set). 전달한 코드의 tmplId 세팅. */
    private function enableAlimtalk(array $codes): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        foreach ($codes as $c) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$c}_{$set}"], ['value' => 'T_'.$c, 'type' => 'string']);
        }
    }

    private function admin(string $phone): User
    {
        return User::factory()->create(['permission' => 'admin', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    private function manager(string $phone): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    public function test_recipients_resolver_by_role(): void
    {
        $this->admin('010-1111-0000');
        User::factory()->create(['permission' => 'super', 'phone' => '010-9999-0000', 'email_verified_at' => now()]);  // super 제외
        $this->manager('010-2222-0000');
        User::factory()->create(['permission' => 'user', 'role' => '영업', 'phone' => '010-3333-0000', 'email_verified_at' => now()]);

        $this->assertSame(['010-1111-0000'], AlimtalkRecipients::admins(), '대표=admin만(super 제외)');
        $this->assertSame(['010-2222-0000'], AlimtalkRecipients::managers(), '관리 role만');
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

    public function test_all_trigger_template_codes_exist(): void
    {
        // 배선한 코드가 모두 템플릿 단일출처에 존재해야(오타 방지).
        foreach ([
            'erp_daily_summary', 'erp_weekly_summary', 'erp_monthly_closing',
            'erp_purchase_unpaid', 'erp_sale_unpaid', 'erp_eta_balance_due',
            'erp_shipping_due', 'erp_pickup_reminder', 'erp_vehicle_new', 'erp_settle_pending',
        ] as $code) {
            $this->assertArrayHasKey($code, AlimtalkTemplates::TEMPLATES, $code.' 템플릿 존재');
        }
    }
}
