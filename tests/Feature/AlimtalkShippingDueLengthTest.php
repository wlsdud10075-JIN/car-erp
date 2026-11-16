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
 * 선적 임박 미수 알림톡 — 배(VSL)별 한 줄 · 한 통 (jin 2026-11-16).
 *
 * 경위: ssancarerp 11-16 09:00 우회 차 33대 × 3줄(+긴 사유) = 1,688자 → BizM E112 로 수신자 7명 전원 실패.
 *   글자 수로 잘라 2통으로 나눴더니 jin «09시에 4~6통 받는데 2통이면 밀려서 안 본다» → 배마다 대표 차 1대 + 「외 N대」, 사유 뺌, 한 통.
 */
class AlimtalkShippingDueLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);
        $set = Setting::companyTemplateSet();
        foreach (['enabled' => '1', 'itemlist' => '1', 'userid' => 'uid', 'profile' => 'prof'] as $k => $v) {
            Setting::updateOrCreate(['key' => "alimtalk_{$k}_{$set}"], ['value' => $v, 'type' => 'string']);
        }
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_shipping_due_{$set}"], ['value' => 'T_SD', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_toggle_erp_shipping_due_{$set}"], ['value' => '1', 'type' => 'boolean']);
        User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-7777-0000', 'email_verified_at' => now()]);
    }

    /** 배 하나에 n대 — 첫 차가 미수 가장 큼. bypass 대수만큼 우회 승인. */
    private function ship(string $vessel, int $days, int $n, int $bypass = 0, string $buyerName = 'Korea Auto'): void
    {
        static $seq = 0;
        $approver = User::first();
        $buyer = Buyer::create(['name' => $buyerName.' '.$vessel, 'is_active' => true]);
        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $v = Vehicle::create([
                'vehicle_number' => sprintf('%02d가%04d', $seq % 100, 1000 + $seq), 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
                'sale_date' => now()->subDays(10)->toDateString(), 'sale_price' => 10_000_000 - $i * 100_000, 'currency' => 'KRW', 'exchange_rate' => 1,
                'shipping_date' => now()->addDays($days)->toDateString(), 'vessel_name' => $vessel,
            ]);
            if ($i < $bypass) {
                UnpaidExportOverride::create(['vehicle_id' => $v->id, 'stage' => 'shipping', 'approved_by' => $approver->id,
                    'reason' => '입금예정임'.str_repeat('ㄱ', 25), 'approved_at' => now()]);
            }
        }
    }

    public function test_one_message_one_line_per_vessel_with_a_representative_car(): void
    {
        $this->ship('GLOVIS SUNRISE', 2, 12, 5);
        $this->ship('HYUNDAI GLORY', 3, 8);
        $this->ship('', 4, 1);

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_shipping_due')->get();
        $this->assertCount(1, $logs, '한 통만 — 2통 나누기는 없앴다');
        $msg = $logs[0]->message;
        $this->assertSame('sent', $logs[0]->status);
        $this->assertStringContainsString('⚠ 우회 진행 5대 포함 · 전체 21대', $msg);
        $this->assertMatchesRegularExpression('/🚢 GLOVIS SUNRISE · \d\d\/\d\d \(D-2\)\n  Korea Auto \d\d가\d{4} 외 11대 \(우회 5\)/u', $msg);
        $this->assertStringContainsString('외 7대', $msg);
        $this->assertStringNotContainsString('(우회', substr($msg, strpos($msg, 'HYUNDAI GLORY')), '우회 없는 배엔 표시 없음');
        $this->assertStringContainsString('🚢 선박 미입력', $msg);
        $this->assertStringNotContainsString('사유:', $msg, '사유 줄은 뺀다(끝의 「승인 사유가 함께 표시」는 BizM 승인 고정문구 — 재등록 때 고칠 것)');
        $this->assertStringNotContainsString('(1/', $msg);
        $this->assertLessThan(strpos($msg, 'HYUNDAI GLORY'), strpos($msg, 'GLOVIS SUNRISE'), '선적일 빠른 배가 위');
    }

    /** 배가 아주 많아도 한 통 · 1,000자 안 · 남은 배는 「외 N척 M대」 — 11-16 E112 재발 방지 */
    public function test_many_vessels_stay_within_the_limit_and_count_the_rest(): void
    {
        for ($s = 1; $s <= 40; $s++) {
            $this->ship('VESSEL NUMBER '.$s, 1 + $s % 4, 2, 1, 'Very Long Buyer Name Trading Company');
        }

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_shipping_due')->sole();
        $this->assertSame('sent', $log->status);
        $this->assertLessThanOrEqual(AlimtalkShippingDue::MAX_MESSAGE_CHARS, mb_strlen($log->message), 'BizM 1,000자 상한(E112)');
        $this->assertMatchesRegularExpression('/외 (\d+)척 (\d+)대 → 채권관리/u', $log->message);
        preg_match('/외 (\d+)척 (\d+)대/u', $log->message, $m);
        $shown = substr_count($log->message, '🚢 ');
        $this->assertSame(40, $shown + (int) $m[1], '보인 배 + 외 N척 = 전체 배');
        $this->assertSame(80, $shown * 2 + (int) $m[2], '보인 차 + 외 M대 = 전체 차');
    }

    /** 09:40 따라잡기 — 이미 보낸 사람에게 다시 안 간다 */
    public function test_catchup_does_not_resend(): void
    {
        $this->ship('GLOVIS', 2, 3);

        $this->artisan('alimtalk:shipping-due')->assertSuccessful();
        $this->artisan('alimtalk:shipping-due')->assertSuccessful();

        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_shipping_due')->count());
    }
}
