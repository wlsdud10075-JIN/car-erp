<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkArrivedUnpaidWeekly;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 「도착 후 미수 요약」 (jin 2026-10-26 C안) — 도착예정일 지남 & 미수, 바이어별 묶음, 매주 월 09:00, 관리·업무관리자.
 */
class AlimtalkArrivedUnpaidWeeklyTest extends TestCase
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
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_arrived_unpaid_weekly_{$set}"], ['value' => 'T_arr', 'type' => 'string']);
        User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
    }

    private function car(Buyer $buyer, array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'ARR-'.++$this->counter, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-01-01', 'purchase_price' => 100, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-01-10', 'sale_price' => 1_000_000, 'shipping_date' => now()->subDays(40),
        ], $extra));
        $v->refreshCaches();

        return $v->refresh();
    }

    public function test_groups_arrived_unpaid_by_buyer_largest_first(): void
    {
        $mck = Buyer::create(['name' => 'MCK', 'is_active' => true]);
        $troy = Buyer::create(['name' => 'AUTO TROY', 'is_active' => true]);
        $this->car($mck, ['eta_date' => now()->subDays(20)->toDateString()]);
        $this->car($mck, ['eta_date' => now()->subDays(5)->toDateString(), 'sale_price' => 3_000_000]);
        $this->car($troy, ['eta_date' => now()->subDays(2)->toDateString(), 'sale_price' => 500_000]);
        $this->car($troy, ['eta_date' => now()->addDays(3)->toDateString(), 'sale_price' => 9_000_000]);   // 아직 운항중 → 제외
        $paid = $this->car($mck, ['eta_date' => now()->subDays(9)->toDateString()]);
        $paid->finalPayments()->create(['amount' => 1_000_000, 'payment_date' => '2026-02-01', 'confirmed_at' => now(), 'type' => 'balance']);
        $paid->refreshCaches();   // 완납 → 제외

        $this->artisan('alimtalk:arrived-unpaid-weekly')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_arrived_unpaid_weekly')->where('status', 'sent')->first();
        $this->assertNotNull($log);
        $m = $log->message;
        $this->assertStringContainsString("[도착 후 미수 요약]\n\n도착예정일이 지났는데 잔금이 남은 차량을 바이어별로 모았습니다.", $m);
        $this->assertStringContainsString('▶ MCK · 2대 · 4,000,000원 · 가장 오래된 도착 '.now()->subDays(20)->toDateString(), $m);
        $this->assertStringContainsString('▶ AUTO TROY · 1대 · 500,000원', $m);
        $this->assertLessThan(strpos($m, 'AUTO TROY'), strpos($m, 'MCK'), '합계 큰 바이어 먼저');
        $this->assertStringNotContainsString('9,000,000', $m, '아직 운항중인 차는 안 들어간다');
    }

    public function test_skips_when_nothing_arrived_unpaid_and_caps_long_lists(): void
    {
        $this->artisan('alimtalk:arrived-unpaid-weekly')->assertSuccessful();
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_arrived_unpaid_weekly')->count());

        $rows = collect();
        for ($i = 0; $i < 18; $i++) {
            $b = Buyer::create(['name' => 'B'.$i, 'is_active' => true]);
            $rows->push($this->car($b, ['eta_date' => now()->subDays(3)->toDateString(), 'sale_price' => 100_000 * ($i + 1)]));
        }
        $text = AlimtalkArrivedUnpaidWeekly::buildList(Vehicle::with('buyer')->get());
        $this->assertSame(AlimtalkArrivedUnpaidWeekly::MAX_BUYER_LINES + 1, substr_count($text, '▶ '));
        $this->assertStringEndsWith('▶ 외 3곳 · 3대', $text);
    }

    public function test_scheduled_weekly_on_monday_morning(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'alimtalk:arrived-unpaid-weekly'));
        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * 1', $events->first()->expression);
    }
}
