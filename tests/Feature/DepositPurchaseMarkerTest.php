<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 보증금 매입 마커 — 매입 탭 체크박스 트리거 (jin 2026-07-30).
 *
 * 배경: 구 트리거였던 재무 '매입 선지급 확정'(confirmPurchaseFundingByFinance)이 2026-07-29 에
 *   승인 사다리와 함께 제거되면서 is_deposit_purchase 를 찍는 코드가 **한 곳도 없어졌다**.
 *   그 결과 독촉 알림톡(erp_deposit_cash_due/overdue)이 살아 있는데도 대상이 영구 0건이었다.
 *   (운영 실측 heymanerp: is_deposit_purchase=true 인 차량 0건 — 삭제분 포함.)
 *
 * 이제 매입 탭 「보증금으로 매입」 체크박스가 유일한 진입점이고, 도장 시각(deposit_purchase_at)은
 * Vehicle::saving 이 찍는다. 이 테스트가 그 배선이 끊기지 않았는지를 지킨다.
 */
class DepositPurchaseMarkerTest extends TestCase
{
    use RefreshDatabase;

    /** 영업 role 사용자 + 그 사람의 salesman 레코드. 연결은 salesmen.user_id (users 엔 salesman_id 없음). */
    private function salesUser(): array
    {
        $user = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['user_id' => $user->id, 'name' => '김영업', 'is_active' => true]);

        return [$user, $sm];
    }

    private function car(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '11가1111',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_date' => '2026-06-01',
            'purchase_price' => 10_000_000,
        ], $attrs));
    }

    // ── 도장 규칙 (Vehicle::saving) ────────────────────────────────

    public function test_checking_the_marker_stamps_the_timer(): void
    {
        $v = $this->car();
        $this->assertNull($v->deposit_purchase_at);

        $v->update(['is_deposit_purchase' => true]);

        $this->assertNotNull($v->fresh()->deposit_purchase_at, '체크하면 독촉 타이머 기산점이 찍혀야 한다');
    }

    /**
     * 🚩 회귀 방지 핵심 — 저장할 때마다 도장이 갱신되면 D+5 가 영원히 안 와서 독촉이 안 나간다.
     */
    public function test_later_saves_preserve_the_first_stamp(): void
    {
        $v = $this->car(['is_deposit_purchase' => true, 'deposit_purchase_at' => now()->subDays(8)]);
        $first = $v->fresh()->deposit_purchase_at;

        $v->update(['purchase_price' => 12_000_000]);   // 무관한 저장

        $this->assertTrue($first->equalTo($v->fresh()->deposit_purchase_at), '첫 도장일이 보존돼야 한다');
    }

    /** 해제하면 시각도 비운다 — "플래그가 켜져 있을 때만 시각이 있다"는 불변식. */
    public function test_unchecking_clears_the_timer(): void
    {
        $v = $this->car(['is_deposit_purchase' => true, 'deposit_purchase_at' => now()->subDays(8)]);

        $v->update(['is_deposit_purchase' => false]);

        $this->assertNull($v->fresh()->deposit_purchase_at);
    }

    /** 재체크는 새 타이머 — 옛 날짜가 남아 즉시 '10일 초과' 로 대표에게 튀지 않아야 한다. */
    public function test_rechecking_restarts_the_timer_instead_of_reusing_the_old_date(): void
    {
        $v = $this->car(['is_deposit_purchase' => true, 'deposit_purchase_at' => now()->subDays(30)]);
        $v->update(['is_deposit_purchase' => false]);

        $v->update(['is_deposit_purchase' => true]);

        $this->assertTrue($v->fresh()->deposit_purchase_at->isToday());
    }

    public function test_marker_change_is_audited(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($user);
        $v = $this->car();

        $v->update(['is_deposit_purchase' => true]);

        $this->assertTrue(
            AuditLog::where('auditable_type', Vehicle::class)->where('auditable_id', $v->id)
                ->where('column_name', 'is_deposit_purchase')->exists(),
            '누가 언제 켰는지 없으면 "왜 독촉이 오냐" 를 못 따진다'
        );
    }

    // ── 매입 탭 체크박스 (권한) ───────────────────────────────────

    public function test_finance_can_set_the_marker_from_the_purchase_tab(): void
    {
        $this->actingAs(User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]));
        $v = $this->car();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('is_deposit_purchase', false)
            ->set('is_deposit_purchase', true)
            ->call('save');

        $v->refresh();
        $this->assertTrue((bool) $v->is_deposit_purchase);
        $this->assertNotNull($v->deposit_purchase_at);
    }

    /**
     * 🔒 영업은 체크박스가 disabled 지만, Livewire public 프로퍼티는 클라이언트가 직접 주입할 수 있다.
     *   이 알림은 "바이어에게 입금 독촉하라" 는 실무 지시라 오조작 비용이 커서 save() 에서 재검사한다.
     */
    public function test_sales_cannot_set_the_marker_even_by_injecting_the_property(): void
    {
        [$user, $sm] = $this->salesUser();
        $this->actingAs($user);
        $v = $this->car(['salesman_id' => $sm->id]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_deposit_purchase', true)
            ->call('save');

        $v->refresh();
        $this->assertFalse((bool) $v->is_deposit_purchase, '재무 권한 없이는 마커가 안 켜져야 한다');
        $this->assertNull($v->deposit_purchase_at);
    }

    /** 이미 켜져 있는 마커를 영업이 끄지도 못한다 (독촉 회피 방지). */
    public function test_sales_cannot_clear_an_existing_marker(): void
    {
        [$user, $sm] = $this->salesUser();
        $this->actingAs($user);
        $v = $this->car(['salesman_id' => $sm->id, 'is_deposit_purchase' => true]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_deposit_purchase', false)
            ->call('save');

        $this->assertTrue((bool) $v->fresh()->is_deposit_purchase);
    }

    // ── end-to-end: 체크 → 독촉 알림톡 ────────────────────────────

    /**
     * 🚩 이 테스트가 이번 사고(트리거 소실)의 재발 가드다.
     *   체크박스 → 도장 → cron → 실제 발송까지 한 줄로 이어지는지 본다.
     */
    public function test_checked_vehicle_reaches_the_reminder_after_the_due_window(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M1'], 'message' => 'K000']], 200)]);

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_deposit_cash_due_{$set}"], ['value' => 'T1', 'type' => 'string']);

        // 2026-08-24 — 수신 범위를 역할이 정한다. **배정 0명인 role='관리' 는 0건**이라
        //   「전체를 받는 관리자」는 업무관리자(permission='manager')로 만든다.
        User::factory()->create(['permission' => 'manager', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);

        // 판매됐지만 입금 20% (기준 50% 미달 = 선적 보류) — 독촉 대상 조건
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $v = $this->car([
            'buyer_id' => $buyer->id, 'sale_date' => '2026-06-10',
            'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $v->finalPayments()->create([
            'amount' => 20_000, 'type' => 'balance', 'payment_date' => '2026-06-11',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);
        $v->refreshProgressCache();

        // 재무가 매입 탭에서 체크
        $this->actingAs($finance);
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('is_deposit_purchase', true)->call('save');

        // 체크 직후엔 아직 독촉 창(D+5) 전 — 안 나가야 한다
        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deposit_cash_due')->count());

        // 6일 경과 → 독촉
        $v->fresh()->forceFill(['deposit_purchase_at' => now()->subDays(6)])->saveQuietly();
        $this->artisan('alimtalk:deposit-cash')->assertSuccessful();

        $this->assertSame(
            1,
            AlimtalkLog::where('template_code', 'erp_deposit_cash_due')->where('phone', '01022220000')->count(),
            '체크박스 → 도장 → cron → 발송 배선이 끊기면 안 된다'
        );
    }
}
