<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkEtaBalance;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Port;
use App\Models\Setting;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 「도착 임박 잔금 안내」 보강 (jin 2026-10-26) — 도착 N일 전(기능설정 ETA 임박 일수), 배별 묶음, [100% 미수][우회 승인][선적대기 허용 항로], 바이어 합계.
 * 등록 템플릿의 변수 `#{잔금목록}` 안만 바뀐다 — 제목·첫 줄·끝 줄은 그대로여야 한다(재승인 없음).
 */
class AlimtalkEtaBalanceGroupedTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-T'], 'message' => 'K000']], 200)]);
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_eta_balance_due_{$set}"], ['value' => 'T_eta', 'type' => 'string']);
        User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
    }

    private function car(Buyer $buyer, array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'ETA-'.++$this->counter, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1000,
            'dhl_request' => false, 'purchase_date' => '2026-01-01', 'purchase_price' => 100, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-01-10', 'sale_price' => 1000, 'shipping_date' => now()->subDays(10),
        ], $extra));
        $v->refreshCaches();

        return $v->refresh();
    }

    public function test_message_groups_by_vessel_with_flags_and_buyer_subtotal(): void
    {
        $port = Port::create(['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'code' => 'ALDRZ', 'is_active' => true, 'allow_shipping_wait' => true]);
        $mck = Buyer::create(['name' => 'MCK', 'is_active' => true]);
        $tevi = Buyer::create(['name' => 'Tevi', 'is_active' => true]);
        $eta = now()->addDays(9)->toDateString();

        $full = $this->car($mck, ['vessel_name' => 'MORNING CALYPSO', 'eta_date' => $eta]);                       // 입금 0 → 100% 미수
        UnpaidExportOverride::create(['vehicle_id' => $full->id, 'stage' => 'shipping', 'approved_by' => User::first()->id,
            'reason' => str_repeat('입금 예정 확인 ', 4), 'approved_at' => now(), 'sale_unpaid_amount_snapshot' => 1_000_000]);
        $part = $this->car($mck, ['vessel_name' => 'MORNING CALYPSO', 'eta_date' => $eta]);
        $part->finalPayments()->create(['amount' => 600, 'payment_date' => '2026-02-01', 'confirmed_at' => now(), 'type' => 'balance']);
        $part->refreshCaches();
        $wait = $this->car($tevi, ['vessel_name' => 'MORNING CALYPSO', 'eta_date' => $eta, 'shipping_method' => 'RORO', 'discharge_port_id' => $port->id]);
        $otherShip = $this->car($tevi, ['vessel_name' => 'GLOVIS SUN', 'eta_date' => now()->addDays(3)->toDateString()]);
        $this->car($tevi, ['vessel_name' => 'LATE SHIP', 'eta_date' => now()->addDays(25)->toDateString()]);   // 10일 밖 → 제외

        $this->artisan('alimtalk:eta-balance')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_eta_balance_due')->where('status', 'sent')->first();
        $this->assertNotNull($log, '관리에게 1통');
        $m = $log->message;

        $this->assertStringContainsString("[도착 임박 잔금 안내]\n\n도착 예정일이 임박했으나 잔금이 남은 차량이 있습니다.", $m, '등록 고정 문구 유지');
        $this->assertStringContainsString('자세한 내용은 ERP 「채권관리」 메뉴에서 확인·처리해 주세요.', $m);
        $this->assertLessThan(strpos($m, 'MORNING CALYPSO'), strpos($m, 'GLOVIS SUN'), '도착 빠른 배가 먼저');
        $this->assertStringContainsString("🚢 MORNING CALYPSO\n도착 ".now()->addDays(9)->format('m/d').' (D-9) · 3대 · 240만', $m);
        $this->assertStringContainsString("★ MCK · 2대 · 140만\n  ETA-1 · 100만\n  ⚠100%미수 ·우회승인\n  ETA-2 · 40만", $m, '배 안에서 바이어로 묶고, 바이어 합계는 ★ 줄에, 꼬리표는 차량 줄 아래');
        $this->assertStringContainsString("🚢 GLOVIS SUN\n도착 ".now()->addDays(3)->format('m/d')." (D-3) · 1대 · 100만\n★ Tevi\n  ETA-4 · 100만\n  ⚠100%미수", $m, '배 안에 그 바이어 차가 1대면 ★ 줄에 숫자 없음');
        $this->assertStringContainsString("★ Tevi\n  ETA-3 · 100만\n  ⚠100%미수 ·대기항로", $m);
        $this->assertStringNotContainsString('LATE SHIP', $m, '10일 밖은 안 들어간다');
        $this->assertSame(1, substr_count($m, '★ MCK'), '바이어는 배 안에서 한 번만');
    }

    public function test_lead_days_follow_the_dashboard_eta_setting(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->car($b, ['vessel_name' => 'X', 'eta_date' => now()->addDays(9)->toDateString()]);

        Setting::updateOrCreate(['key' => 'alarm_eta_lead_days'], ['value' => '7', 'type' => 'integer']);
        $this->assertSame(7, AlimtalkEtaBalance::leadDays());
        $this->artisan('alimtalk:eta-balance')->assertSuccessful();
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_eta_balance_due')->count(), '7일 설정이면 D-9 는 아직');

        Setting::updateOrCreate(['key' => 'alarm_eta_lead_days'], ['value' => '10', 'type' => 'integer']);
        $this->artisan('alimtalk:eta-balance')->assertSuccessful();
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_eta_balance_due')->where('status', 'sent')->count());
    }

    public function test_list_is_capped_with_remainder_line(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $rows = collect();
        for ($i = 0; $i < 23; $i++) {
            $rows->push($this->car($b, ['vessel_name' => 'V'.($i % 3), 'eta_date' => now()->addDays(2 + $i % 3)->toDateString()]));
        }
        $text = AlimtalkEtaBalance::buildList(Vehicle::with(['buyer', 'dischargePort', 'unpaidExportOverrides'])->get(), now()->startOfDay());
        $this->assertSame(AlimtalkEtaBalance::MAX_VEHICLE_LINES, preg_match_all('/^  ETA-\d+ · /m', $text), '차량 줄 수 = 상한');
        $this->assertStringEndsWith('외 3대 → 채권관리 ⚓도착예정', $text);
        $this->assertLessThan(1000, mb_strlen($text), '알림톡 본문 1000자 안');
    }
}
