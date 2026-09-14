<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🚚 **픽업 재촉은 매입취소 차를 안 부른다 — 가져올 차가 없기 때문** (jin 2026-09-14).
 *
 * 매입취소는 **마커만** 바꾸고 매입가·지급기록을 그대로 둔다(위약금을 채권 배관으로 추적하는 설계).
 * ⇒ `purchase_unpaid_amount > 0` 이 **영원히 참**이라 안 빼면 취소한 차의 독촉이 영구히 나간다.
 * 실측 2026-09-14: heymanerp 대상 10대 중 3대 · 최근 30일 발송 33대 중 3대가 취소분이었다.
 *
 * 🚨 **이 커맨드가 2026-09-09 매입취소 제외를 못 물려받은 이유** = 조건을 `scopeAction` 에서
 *    가져오지 않고 **직접 옮겨 적었기 때문**이다(SKILLS §8 #38·#45).
 *
 * ⚠️ **매입 미지급 알림톡은 반대로 취소분을 포함한다**(jin 2026-09-09 —「딜러 대금은 재무가 볼 돈」).
 *    같은 「미지급」 조건인데 **소비자가 달라 정답이 다르다.** 🚫 둘을 통일하려 하지 말 것 —
 *    그래서 이 파일이 **두 커맨드를 나란히** 검사한다.
 *
 * 🧪 **검사는 실제 발송 경로로 한다** — 조건을 테스트에 옮겨 적으면 커맨드를 안 고쳐도 통과한다
 *    (처음에 그렇게 썼다가 되돌려 보고 4건 중 1건만 빨개져서 알았다, §8 #73).
 */
class PickupExcludesCancelledTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);

        $set = Setting::companyTemplateSet();
        foreach ([
            "alimtalk_enabled_{$set}" => '1',
            "alimtalk_itemlist_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'uid',
            "alimtalk_profile_{$set}" => 'prof',
            "alimtalk_tmpl_erp_pickup_reminder_{$set}" => 'T_pickup',
        ] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
    }

    /** 매입일 D+5 · 지급 0 = 미지급 최대 → 픽업 대상 조건을 만족하는 차. */
    private function vehicle(string $cancelStatus = Vehicle::CANCEL_NONE): Vehicle
    {
        $s = Salesman::create([
            'name' => 'S'.++$this->n,
            'phone' => '010-5555-'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);

        return Vehicle::create([
            'vehicle_number' => '33다'.str_pad((string) (3000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'purchase_price' => 5_000_000,
            'purchase_date' => now()->subDays(5)->toDateString(),
            'salesman_id' => $s->id,
            'cancel_status' => $cancelStatus,
        ]);
    }

    /** 커맨드를 실제로 돌린 뒤, 발송 로그에 실린 차량 id 들. */
    private function notifiedVehicleIds(): array
    {
        $this->artisan('alimtalk:pickup')->assertSuccessful();

        return AlimtalkLog::where('template_code', 'erp_pickup_reminder')
            ->pluck('vehicle_id')->filter()->unique()->values()->all();
    }

    /** 🚨 이번 결함 그 자체 — 취소한 차에 독촉이 나가던 것. */
    public function test_a_cancelled_purchase_gets_no_pickup_notice(): void
    {
        $normal = $this->vehicle();
        $cancelled = $this->vehicle(Vehicle::CANCEL_ACTIVE);
        $closed = $this->vehicle(Vehicle::CANCEL_CLOSED);

        // 전제 — 셋 다 미지급이 남아 있다(취소해도 금액은 그대로라는 것이 이 건의 핵심).
        foreach ([$normal, $cancelled, $closed] as $v) {
            $this->assertGreaterThan(0, (int) $v->fresh()->purchase_unpaid_amount,
                '전제가 안 선다 — 매입취소가 금액까지 지웠다면 이 테스트는 의미가 없다');
        }

        $ids = $this->notifiedVehicleIds();

        $this->assertContains($normal->id, $ids, '정상 차에 픽업 독촉이 안 나갔다');
        $this->assertNotContains($cancelled->id, $ids, '매입취소 차에 픽업 독촉이 나간다');
        $this->assertNotContains($closed->id, $ids, '미수 마감된 취소 차에 픽업 독촉이 나간다');
    }

    /** 대상이 취소분뿐이면 **한 통도 안 나가야** 한다 — 「0건 skip」으로 끝나는지. */
    public function test_only_cancelled_means_nothing_is_sent(): void
    {
        $this->vehicle(Vehicle::CANCEL_ACTIVE);
        $this->vehicle(Vehicle::CANCEL_CLOSED);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_pickup_reminder')->count(),
            '취소 차만 남았는데 알림톡 로그가 생겼다');
    }

    /** 취소를 풀면 다시 나간다 — 되돌릴 수 있어야 한다. */
    public function test_restoring_a_cancelled_purchase_brings_the_notice_back(): void
    {
        $v = $this->vehicle(Vehicle::CANCEL_ACTIVE);
        $this->assertNotContains($v->id, $this->notifiedVehicleIds());

        AlimtalkLog::query()->delete();
        $v->update(['cancel_status' => Vehicle::CANCEL_NONE]);

        $this->assertContains($v->id, $this->notifiedVehicleIds(),
            '취소를 풀었는데 픽업 독촉이 안 돌아온다');
    }

    /**
     * 🚫 **매입 미지급(재무용)은 반대로 포함해야 한다** — jin 2026-09-09 결정.
     * 「일관성」을 이유로 여기까지 취소 제외를 넣으면 재무가 확인할 딜러 대금이 큐에서 사라진다.
     */
    public function test_the_finance_queue_still_includes_cancelled_purchases(): void
    {
        $cancelled = $this->vehicle(Vehicle::CANCEL_ACTIVE);

        $ids = Vehicle::query()->action('purchase_unpaid')->pluck('id')->all();

        $this->assertContains($cancelled->id, $ids, implode("\n", [
            '매입취소 차가 매입 미지급 큐에서도 빠졌다 — 재무가 못 본다.',
            '픽업(가져올 차)과 재무(줄 돈)는 소비자가 달라 정답이 다르다(jin 2026-09-09).',
        ]));

        $src = file_get_contents(base_path('app/Console/Commands/AlimtalkPurchaseUnpaid.php'));
        $this->assertStringNotContainsString("where('cancel_status'", $src,
            '매입 미지급 알림톡이 매입취소를 거르기 시작했다 — jin 결정과 반대다');
    }
}
