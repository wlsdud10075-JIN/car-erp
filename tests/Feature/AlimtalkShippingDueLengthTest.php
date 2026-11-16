<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkShippingDue;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Setting;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 선적 임박 미수 알림톡 — 글자 수로 자르고 길면 2통 (jin 2026-11-16).
 *
 * 실사고: ssancarerp 11-16 09:00·09:10 — 우회 진행 차 33대 중 20대 × 3줄(+긴 사유) = 1,688자 →
 * BizM E112:OverLimitMessageLength 로 수신자 7명 전원 실패. 구 상한은 「차 20대」였고 글자는 안 셌다.
 */
class AlimtalkShippingDueLengthTest extends TestCase
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
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_shipping_due_{$this->set}"], ['value' => 'T_SD', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_toggle_erp_shipping_due_{$this->set}"], ['value' => '1', 'type' => 'boolean']);
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-7777-0000', 'email_verified_at' => now()]);
    }

    /** 우회 진행 차 n대 — 11-16 운영처럼 긴 사유를 단다. */
    private function bypassCars(int $n, User $approver): void
    {
        $buyer = Buyer::create(['name' => 'Korea Auto', 'is_active' => true]);
        for ($i = 1; $i <= $n; $i++) {
            $v = Vehicle::create([
                'vehicle_number' => sprintf('%02d가%04d', $i, 1000 + $i), 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
                'sale_date' => now()->subDays(10)->toDateString(), 'sale_price' => 10_000_000, 'currency' => 'KRW', 'exchange_rate' => 1,
                'shipping_date' => now()->addDays(3)->toDateString(),
            ]);
            UnpaidExportOverride::create(['vehicle_id' => $v->id, 'stage' => 'shipping', 'approved_by' => $approver->id,
                'reason' => '입금예정임'.str_repeat('ㄱ', 25).' 서류 마감 임박으로 인한 면장 진행', 'approved_at' => now()]);
        }
    }

    public function test_long_list_is_split_into_two_messages_each_under_the_limit(): void
    {
        $this->bypassCars(33, $this->manager());

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_shipping_due')->orderBy('id')->get();
        $this->assertCount(2, $logs, '길면 2통으로 나눠야 한다');
        foreach ($logs as $log) {
            $this->assertSame('sent', $log->status);
            $this->assertLessThanOrEqual(AlimtalkShippingDue::MAX_MESSAGE_CHARS, mb_strlen($log->message), 'BizM 1,000자 상한을 넘었다(E112)');
        }
        $this->assertStringContainsString('(1/2)', $logs[0]->message);
        $this->assertStringContainsString('(2/2)', $logs[1]->message);
        $this->assertStringContainsString('⚠ 우회 진행 33대 (이어서)', $logs[1]->message, '2통째도 무슨 목록인지 머리가 있어야 한다');
        $this->assertStringContainsString('…', $logs[0]->message, '긴 사유는 잘라야 한다');
        $this->assertMatchesRegularExpression('/외 \d+건 → 채권관리/u', $logs[1]->message, '2통에 다 못 담으면 남은 대수를 알린다');

        // 차가 빠지거나 두 번 나오지 않는다 — 실은 대수 + 「외 N건」 = 33
        $shown = preg_match_all('/★ Korea Auto \d{2}가\d{4}/u', $logs[0]->message.$logs[1]->message);
        preg_match('/외 (\d+)건/u', $logs[1]->message, $m);
        $this->assertSame(33, $shown + (int) ($m[1] ?? 0));
    }

    public function test_short_list_stays_one_message_without_part_tag(): void
    {
        $this->bypassCars(2, $this->manager());

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_shipping_due')->get();
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('(1/', $logs[0]->message);
        $this->assertStringNotContainsString('외 ', $logs[0]->message);
    }

    /** 09:10 따라잡기 — 1통째가 이미 나갔으면 2통째도 다시 안 보낸다(중복 카톡 방지). */
    public function test_catchup_does_not_resend_either_part(): void
    {
        $this->bypassCars(33, $this->manager());

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();
        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $this->assertSame(2, AlimtalkLog::where('template_code', 'erp_shipping_due')->count());
    }

    /** 2통째가 차량 줄로 시작하면 선박·바이어 머리를 다시 붙이고, 1통째 끝에 머리줄만 덩그러니 남기지 않는다. */
    public function test_pack_repeats_context_and_drops_orphan_headers(): void
    {
        $ship = '🚢 GLOVIS · 11/20 (D-3)';
        $blocks = [['text' => $ship, 'vehicle' => false, 'context' => null]];
        for ($b = 1; $b <= 3; $b++) {
            $buyer = '★ BUYER'.$b;
            $blocks[] = ['text' => $buyer, 'vehicle' => false, 'context' => $ship];
            for ($i = 1; $i <= 8; $i++) {
                $blocks[] = ['text' => sprintf('  %d가%04d · 미수100%%', $b, $i), 'vehicle' => true, 'context' => $ship."\n".$buyer];
            }
        }

        $parts = AlimtalkShippingDue::pack($blocks, 260);

        $this->assertCount(2, $parts);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(260, mb_strlen($p));
        }
        $this->assertStringStartsWith("(2/2)\n".$ship."\n★ BUYER", $parts[1], '2통째 머리에 선박·바이어를 다시 붙인다');
        $lastLine = last(explode("\n", explode("\n\n외", $parts[0])[0]));
        $this->assertStringStartsWith('  ', $lastLine, '1통째가 머리줄로 끝나면 안 된다');
    }
}
