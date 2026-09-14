<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🗑️ **「말소 필요」 목록 필터** (jin 2026-09-14).
 *
 * jin: *「완납되고 D+2 일부터 말소중 토글에 솔팅될 수 있도록. 알림톡도 가긴 하지만 화면에서 찾기가 어려움.」*
 *
 * 🚫 **진행상태(cascade)에 「말소중」을 만들지 않기로 했다**(jin 「다」안). 이유 =
 *    cascade 가 **판매를 말소보다 먼저** 보기 때문에 팔린 차는 말소 단계가 안 보인다.
 *    실측 ssancarerp 말소대기 306대 = 판매완료 171 · 판매중 128 · 선적중 4 · **매입완료 3**.
 *    단계를 끼우면 3대만 잡히고, 말소를 판매 위로 올리면 306대가 판매 통계에서 빠져나간다.
 *    ⇒ 「찾기 어렵다」만 푸는 필터로 했고 **기존 숫자는 하나도 안 바뀐다**(그것도 여기서 단언한다).
 *
 * 🔑 일수는 **알림톡 설정이 단일 출처**다 — 화면과 알림톡이 같은 날 걸려야 한다.
 */
class DeregistrationDueFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    /** 매입 완납 차 — `$paidDaysAgo` 일 전에 잔금이 확정됐다. */
    private function paidVehicle(int $paidDaysAgo, array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => '44라'.str_pad((string) (4000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'salesman_id' => $s->id,
            'purchase_price' => 3_000_000,
            'purchase_date' => now()->subDays($paidDaysAgo + 5)->toDateString(),
        ], $attrs));

        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 3_000_000,
            'payment_date' => now()->subDays($paidDaysAgo)->toDateString(),
            'confirmed_at' => now()->subDays($paidDaysAgo),
        ]);

        return $v->fresh();
    }

    private function dueIds(): array
    {
        return Vehicle::query()->action('deregistration_due')->pluck('id')->all();
    }

    public function test_a_car_paid_long_enough_and_not_deregistered_is_due(): void
    {
        $due = $this->paidVehicle(5);

        $this->assertSame(0, (int) $due->purchase_unpaid_amount, '전제가 안 선다 — 완납이어야 한다');
        $this->assertContains($due->id, $this->dueIds(), '완납 후 충분히 지났는데 말소 필요로 안 잡힌다');
    }

    /**
     * ⏳ 완납 **당일**은 아직 아니다 — 실무자에게 하루도 안 주고 독촉하면 안 된다.
     *
     * ⚠️ **이 케이스는 SQLite 에서 원리상 실패할 수 없다 — 가드로 믿지 말 것.**
     *    기존 `Vehicle::purchaseUnpaidRawExpr()` 가 `payment_date <= ?` 를 **DATE() 없이** 쓰는데,
     *    운영 MySQL 은 `date` 컬럼이라 같은 날이 포함되고 테스트 SQLite 는 `2026-09-14 00:00:00` 으로
     *    저장돼 **문자열 비교로 빠진다**(실측: `'…00:00:00' <= '2026-09-14'` → 0).
     *    ⇒ 당일 지급분이 테스트에선 「미지급」이라 이 차는 상위 조건에서 먼저 걸러진다.
     *    즉 **운영은 맞게 돌고 테스트만 같은 날 경계를 못 본다.**
     * 🎯 **실제 경계 가드는 `test_the_boundary_day_counts`** 다 — 그건 깨뜨렸을 때 빨개진다(확인함).
     * 🚫 기존 식에 DATE() 를 씌우는 것은 **매입 미지급을 쓰는 모든 화면이 영향받는 별건**이다.
     */
    public function test_a_car_paid_today_is_not_due_yet(): void
    {
        $fresh = $this->paidVehicle(0);

        $this->assertNotContains($fresh->id, $this->dueIds(), '완납 당일인데 벌써 말소 필요로 뜬다');
    }

    /**
     * 🚨 **경계 — 딱 N일째 되는 날 뜬다.** jin 이 말한 「D+2 **일부터**」가 이것이다.
     *
     * 이 케이스가 없으면 **드라이버 차이로 하루가 갈려도 아무도 모른다**(§8 #36) —
     * 운영 MySQL 은 `date` 컬럼(`2026-09-12`)이고 테스트 SQLite 는 `2026-09-12 00:00:00` 이라
     * `DATE(...)` 로 안 감싸면 같은 날이 한쪽에만 포함된다. 실제로 그렇게 돼 있었다.
     */
    public function test_the_boundary_day_counts(): void
    {
        $days = Vehicle::deregistrationDueDays();

        $onBoundary = $this->paidVehicle($days);
        $oneDayShort = $this->paidVehicle($days - 1);

        $ids = $this->dueIds();

        $this->assertContains($onBoundary->id, $ids,
            "완납 후 정확히 {$days}일째인데 안 뜬다 — 「D+{$days} 일부터」가 아니라 D+".($days + 1).' 이 된다');
        $this->assertNotContains($oneDayShort->id, $ids,
            '아직 하루 이른 차가 벌써 뜬다');
    }

    /** 말소가 끝난 차는 빠진다. */
    public function test_a_deregistered_car_drops_out(): void
    {
        $done = $this->paidVehicle(5, [
            'is_deregistered' => true,
            'deregistration_document' => 'vehicles/dereg.pdf',
        ]);

        $this->assertNotContains($done->id, $this->dueIds(), '말소 끝난 차가 아직 목록에 있다');
    }

    /** 아직 매입 대금이 남은 차는 대상이 아니다(완납이 전제). */
    public function test_an_unpaid_purchase_is_not_due(): void
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $unpaid = Vehicle::create([
            'vehicle_number' => '44라9999', 'sales_channel' => 'export', 'salesman_id' => $s->id,
            'purchase_price' => 3_000_000, 'purchase_date' => now()->subDays(20)->toDateString(),
        ]);

        $this->assertNotContains($unpaid->id, $this->dueIds(), '미지급이 남았는데 말소 필요로 뜬다');
    }

    /**
     * 🚨 **진행상태와 다른 축이다** — 팔렸어도 말소가 안 됐으면 뜬다.
     * 이게 이 필터의 존재 이유다(단계로 만들면 판매 단계에 가려 안 보인다).
     */
    public function test_it_is_independent_of_the_progress_stage(): void
    {
        $sold = $this->paidVehicle(5, [
            'sale_price' => 9_000_000, 'sale_date' => now()->subDays(3)->toDateString(),
            'exchange_rate' => 1, 'currency' => 'KRW',
        ]);

        $this->assertNotSame('매입완료', $sold->fresh()->progress_status,
            '전제가 안 선다 — 판매 단계로 올라간 차여야 한다');
        $this->assertContains($sold->id, $this->dueIds(),
            '판매 단계로 올라갔다고 말소 필요에서 사라졌다 — 필터의 존재 이유가 없어진다');
    }

    /** 🔑 일수는 알림톡 설정을 따라간다 — 설정을 늘리면 목록도 같이 늦게 뜬다. */
    public function test_the_threshold_follows_the_alimtalk_setting(): void
    {
        $v = $this->paidVehicle(3);
        $this->assertContains($v->id, $this->dueIds(), '기본 임계(2일)에서 안 잡힌다');

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(
            ['key' => "alimtalk_escalate_erp_deregistration_reminder_영업_{$set}"],
            ['value' => '7', 'type' => 'string']
        );

        $this->assertSame(7, Vehicle::deregistrationDueDays(), '설정을 안 읽는다');
        $this->assertNotContains($v->id, $this->dueIds(),
            '임계를 7일로 늘렸는데 3일 된 차가 아직 뜬다 — 화면과 알림톡이 다른 날에 걸린다');
    }

    /** 🖥️ 화면에서 실제로 켜지는가 + 라벨이 번역되는가(§8 #73 — 키 문자열 노출 방지). */
    public function test_the_toggle_works_on_the_screen(): void
    {
        $due = $this->paidVehicle(5);
        $other = $this->paidVehicle(5, [
            'is_deregistered' => true, 'deregistration_document' => 'vehicles/x.pdf',
        ]);

        $page = Volt::test('erp.vehicles.index');
        $page->assertSee('말소 필요');
        $page->assertDontSee('vehicle.filter_dereg');

        $page->call('toggleTask', 'deregistration')
            ->assertSet('taskFilter', 'deregistration')
            ->assertSee($due->vehicle_number)
            ->assertDontSee($other->vehicle_number);

        // 같은 걸 다시 누르면 해제 — 운항 필터와 같은 동작.
        $page->call('toggleTask', 'deregistration')->assertSet('taskFilter', '');
    }

    /**
     * ⚠️ 새 색 클래스는 **빌드된 CSS 에 있어야 먹는다**(§8 #50).
     * 없으면 pill 이 흰색으로 렌더돼 「눌렀는지 모르겠다」가 된다 — 화면은 정상이고 효과만 없다.
     * 실제로 `bg-rose-100` 이 없어서 `npm run build` 를 돌렸다.
     */
    public function test_the_pill_colours_exist_in_the_built_css(): void
    {
        $files = glob(public_path('build/assets/*.css'));
        if ($files === []) {
            $this->markTestSkipped('빌드 산출물이 없다(배포 서버가 다시 굽는다) — 로컬에서만 의미 있는 검사');
        }

        $css = '';
        foreach ($files as $f) {
            $css .= file_get_contents($f);
        }

        foreach (['.bg-rose-600', '.bg-rose-100', '.text-rose-700', '.hover\\:bg-rose-200'] as $needle) {
            $this->assertStringContainsString($needle, $css,
                "{$needle} 가 빌드된 CSS 에 없다 — npm run build 를 돌릴 것");
        }
    }

    /**
     * 🚫 **기존 숫자를 안 흔든다** — 이 변경의 전제다.
     * 필터를 끈 상태의 목록·진행상태 분포가 필터 도입 전과 같아야 한다.
     */
    public function test_it_changes_nothing_while_switched_off(): void
    {
        $this->paidVehicle(5);
        $this->paidVehicle(0);
        $this->paidVehicle(5, ['is_deregistered' => true, 'deregistration_document' => 'vehicles/y.pdf']);

        $all = Vehicle::count();
        $offCount = Volt::test('erp.vehicles.index')->get('vehicles')->total();

        $this->assertSame($all, $offCount, '필터가 꺼져 있는데 목록 대수가 달라졌다');
    }
}
