<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 보증금 매입 바이어 입금 독촉 알림톡 (2026-07-23, jin).
 *   due(도장+5~10일) → 담당 영업 + 관리 / overdue(+10 초과) → 대표.
 *   완납/기준충족(락 해제)·조기(도장+5일 미만)는 발송 제외. 자동 중단 = unpaid_ratio 재계산.
 */
class AlimtalkDepositCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-T'], 'message' => 'K000']], 200)]);
    }

    private function enableAlimtalk(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        foreach (['erp_deposit_cash_due', 'erp_deposit_cash_overdue'] as $c) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$c}_{$set}"], ['value' => 'T_'.$c, 'type' => 'string']);
        }
    }

    /** 보증금 도장 + 판매(부분입금) 차량 생성. $paidRatio=입금률(0~1), $daysAgo=도장 경과일. */
    private function depositCar(string $plate, Buyer $buyer, ?Salesman $sm, int $daysAgo, float $paidRatio, array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'salesman_id' => $sm?->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
            'is_deposit_purchase' => true, 'deposit_purchase_at' => now()->subDays($daysAgo),
        ], $extra));
        if ($paidRatio > 0) {
            $v->finalPayments()->create([
                'amount' => 100_000 * $paidRatio, 'type' => 'balance', 'payment_date' => '2026-05-02',
                'exchange_rate' => 1300, 'confirmed_at' => now(),
            ]);
        }
        $v->refreshProgressCache();

        return $v->fresh();
    }

    public function test_due_window_sends_to_manager_and_salesman(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $sm = Salesman::create(['name' => '김영업', 'phone' => '010-5555-0000', 'is_active' => true]);

        // 도장 7일 경과 + 20% 입금(기준 50% 미달 = 락) → due
        $this->depositCar('11가1111', $buyer, $sm, 7, 0.20);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_deposit_cash_due')->get();
        $this->assertEqualsCanonicalizing(
            ['01022220000', '01055550000'],
            $logs->pluck('phone')->all(),
            '관리 + 담당 영업 둘 다 독촉'
        );
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deposit_cash_overdue')->count());
    }

    public function test_overdue_sends_to_admin_only(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'admin', 'phone' => '010-1111-0000', 'email_verified_at' => now()]);
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'OSAKA', 'is_active' => true]);

        // 도장 12일 경과 + 10% 입금 → overdue (대표에게만, 독촉엔 안 감)
        $this->depositCar('22나2222', $buyer, null, 12, 0.10);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $ov = AlimtalkLog::where('template_code', 'erp_deposit_cash_overdue')->get();
        $this->assertSame(['01011110000'], $ov->pluck('phone')->all(), '초과분은 대표에게만');
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deposit_cash_due')->count(), '초과분은 독촉 목록에서 제외');
    }

    public function test_skips_when_buyer_paid_threshold(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'PAID', 'is_active' => true]);

        // 도장 7일 경과지만 60% 입금(기준 50% 충족 = 락 해제) → 발송 안 됨(자동 중단)
        $this->depositCar('33다3333', $buyer, null, 7, 0.60);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::whereIn('template_code', ['erp_deposit_cash_due', 'erp_deposit_cash_overdue'])->count());
    }

    public function test_skips_before_due_window(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'EARLY', 'is_active' => true]);

        // 도장 2일 경과(5일 미만) → 아직 독촉 시작 전
        $this->depositCar('44라4444', $buyer, null, 2, 0.20);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::whereIn('template_code', ['erp_deposit_cash_due', 'erp_deposit_cash_overdue'])->count());
    }

    // ── 출고일 pivot (jin 2026-07-30) ─────────────────────────────

    /**
     * 🚩 알바니아(DURRESS) RORO 시나리오 — 「선적대기 허용 항로」는 항구 주차장에 세워두려고
     *   반입지를 먼저 찍는다. 돈은 그대로 안 들어왔는데 주차했다는 이유로 독촉이 꺼지면 안 된다.
     *   (구 규칙 whereNull('bl_loading_location') 이 이걸 조용히 꺼뜨렸다 — 실측 heymanerp 7대.)
     */
    public function test_port_parked_vehicle_still_gets_reminded(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'ALBANIA', 'is_active' => true]);

        // 반입지는 찍혔지만(항구 대기) 출고 전 · 입금 0%
        $this->depositCar('55마5555', $buyer, null, 7, 0, ['bl_loading_location' => '인천항 3부두']);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $this->assertSame(
            1,
            AlimtalkLog::where('template_code', 'erp_deposit_cash_due')->count(),
            '반입지만으로 독촉이 꺼지면 안 된다 — 아직 출항 전이라 "선적 보류 중" 이 맞다'
        );
    }

    /** 실제 출항(출고일 입력) 후에는 멈춘다 — 이후는 일반 채권 알림(선적후 미수)이 담당. */
    public function test_shipped_out_vehicle_stops_the_reminder(): void
    {
        $this->enableAlimtalk();
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'SAILED', 'is_active' => true]);

        $this->depositCar('66바6666', $buyer, null, 7, 0, [
            'bl_loading_location' => '인천항 3부두',
            'warehouse_out_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::whereIn('template_code', ['erp_deposit_cash_due', 'erp_deposit_cash_overdue'])->count());
    }
}
