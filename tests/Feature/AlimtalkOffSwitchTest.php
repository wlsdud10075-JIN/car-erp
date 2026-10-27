<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 알림톡 점검 후속 (jin 2026-10-19).
 *   ① 꺼진 알림은 대상을 모으지도, 「건너뜀」 행을 남기지도 않는다 (구: ssancarerp 30일 21,821행 = 로그의 93%)
 *   ③ 선적 임박의 [N%미만] 표시 = 운영 설정·바이어 재정의 (구: 50% 고정)
 *   ⑤ 월결산 지급총액 = 정산 실지급 + 급여 + 조정 · 예치금 반환은 회사이익에서 빼지 않는다
 *   ⑥ 매매상 잔금 알림톡 삭제 · ⑦ 자금보고 말일 발송 폐지
 */
class AlimtalkOffSwitchTest extends TestCase
{
    use RefreshDatabase;

    private string $set;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);
        $this->set = Setting::companyTemplateSet();
        foreach (['enabled' => '1', 'itemlist' => '1', 'userid' => 'uid', 'profile' => 'prof'] as $k => $v) {
            Setting::updateOrCreate(['key' => "alimtalk_{$k}_{$this->set}"], ['value' => $v, 'type' => 'string']);
        }
    }

    private function enable(string $code, bool $on = true): void
    {
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$code}_{$this->set}"], ['value' => 'T_'.$code, 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_toggle_{$code}_{$this->set}"], ['value' => $on ? '1' : '0', 'type' => 'boolean']);
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-7777-0000', 'email_verified_at' => now()]);
    }

    public function test_pickup_off_leaves_no_rows_and_on_still_sends(): void
    {
        $sm = Salesman::create(['name' => '김영업', 'phone' => '010-5555-0000', 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => '11가1234', 'sales_channel' => 'export',
            'purchase_price' => 5_000_000, 'purchase_date' => now()->subDays(3)->toDateString(), 'salesman_id' => $sm->id,
        ]);

        $this->enable('erp_pickup_reminder', false);
        $this->artisan('alimtalk:pickup')->assertSuccessful();
        $this->assertSame(0, AlimtalkLog::count(), '꺼진 알림은 행을 남기지 않는다');

        $this->enable('erp_pickup_reminder', true);
        $this->artisan('alimtalk:pickup')->assertSuccessful();
        $this->assertSame(1, AlimtalkLog::where('status', 'sent')->count(), '켜면 종전대로 나간다 — 위 0 이 대상 없음 탓이 아님');
    }

    public function test_settle_pending_off_leaves_no_rows(): void
    {
        $this->manager();
        $make = function (string $no) {
            $v = Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export', 'purchase_price' => 1_000_000, 'purchase_date' => '2026-09-01']);

            return Settlement::create(['vehicle_id' => $v->id, 'settlement_status' => 'pending', 'settlement_type' => 'per_unit']);
        };

        $this->enable('erp_settle_pending', false);
        $make('SP-1');
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_settle_pending')->count());

        $this->enable('erp_settle_pending', true);
        $make('SP-2');
        $this->assertGreaterThan(0, AlimtalkLog::where('template_code', 'erp_settle_pending')->where('status', 'sent')->count(), '켜면 나간다');
    }

    public function test_shipping_due_marks_with_the_configured_entry_threshold(): void
    {
        $mgr = $this->manager();
        $this->enable('erp_shipping_due');
        Setting::updateOrCreate(['key' => "lock_threshold_shipping_entry_{$this->set}"], ['value' => '60', 'type' => 'integer']);
        $buyer = Buyer::create(['name' => 'SD-B', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => now()->subDays(20)->toDateString(), 'sale_price' => 10_000_000, 'currency' => 'KRW', 'exchange_rate' => 1,
            'shipping_date' => now()->addDays(2)->toDateString(),
        ]);
        UnpaidExportOverride::create(['vehicle_id' => $v->id, 'stage' => 'shipping', 'approved_by' => $mgr->id, 'reason' => '대표 승인', 'approved_at' => now()]);

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $msg = (string) AlimtalkLog::where('template_code', 'erp_shipping_due')->value('message');
        $this->assertStringContainsString("⚠ 우회 진행 1대\n★ SD-B 22나2222\n  D-2 · 미수100% · 60%미만\n  사유: 대표 승인", $msg, '표시 = 설정된 필요 입금률');
        $this->assertStringNotContainsString('· 50%미만', $msg, '줄에 붙는 기준은 설정값(고정 꼬리 문장의 [50%미만]은 승인본이라 그대로)');
    }

    public function test_monthly_closing_payout_includes_payroll_and_adjustments_but_profit_skips_deposit_returns(): void
    {
        $month = '2026-06';
        $admin = User::factory()->create(['permission' => 'admin', 'phone' => '01011112222', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'MC-1', 'sales_channel' => 'export', 'salesman_id' => $sm->id,
            'purchase_date' => '2026-05-01', 'purchase_price' => 5_000_000,
            'sale_date' => '2026-06-01', 'sale_price' => 10_000_000, 'currency' => 'KRW', 'exchange_rate' => 1,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_status' => 'confirmed', 'settlement_type' => 'per_unit',
            'per_unit_amount' => 100_000, 'confirmed_at' => '2026-06-20 10:00:00', 'attributed_month' => '2026-06-01 00:00:00',
        ]);
        $batch = SettlementPayoutBatch::create([
            'month' => $month, 'submitter_id' => $admin->id, 'submitter_rank' => 1, 'current_level' => 3,
            'status' => SettlementPayoutBatch::STATUS_APPROVED, 'total_payout' => 0, 'settlement_count' => 1,
            'submitted_at' => now(), 'decided_at' => now(),
        ]);
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => $month, 'label' => '기본급', 'amount' => 300_000, 'sort' => 1]);
        SettlementPayoutAdjustment::create(['batch_id' => $batch->id, 'salesman_id' => $sm->id, 'amount' => 50_000, 'kind' => 'manual', 'reason' => '조정', 'created_by' => $admin->id]);
        SettlementPayoutAdjustment::create(['batch_id' => $batch->id, 'salesman_id' => $sm->id, 'amount' => 200_000, 'kind' => SettlementPayoutAdjustment::KIND_DEPOSIT, 'reason' => '예치금 반환', 'created_by' => $admin->id]);

        $vars = AlimtalkMonthlyClosing::buildVars($month);
        $s->refresh();

        $this->assertSame(number_format($s->actual_payout + 300_000 + 250_000).'원', $vars['지급총액'], '지급총액 = 실지급 + 급여 + 조정(예치금 반환 포함)');
        $this->assertSame(number_format($s->company_net - 300_000 - 50_000).'원', $vars['회사이익'], '예치금 반환은 회사이익에서 빼지 않는다');
    }

    public function test_dealer_balance_template_is_gone_and_capital_report_is_monday_only(): void
    {
        $this->assertArrayNotHasKey('erp_dealer_balance_due', AlimtalkTemplates::TEMPLATES);
        $this->assertArrayNotHasKey('erp_dealer_balance_due', AlimtalkTemplates::WHEN);

        $capital = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'alimtalk:capital-weekly'));
        $this->assertCount(1, $capital, '자금보고는 월요일 09:00 한 번뿐 — 말일 18:00 폐지(jin 2026-10-19)');
        $this->assertSame('0 9 * * 1', $capital->first()->expression);
    }
}
