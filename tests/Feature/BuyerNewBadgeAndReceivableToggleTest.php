<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🆕 바이어 「신규」 뱃지(등록 후 30일, 2026-10-21 부터) · 💰 차량관리 「미수금」 토글 (jin 2026-10-22).
 */
class BuyerNewBadgeAndReceivableToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buyerAt(string $name, string $createdAt): Buyer
    {
        $b = Buyer::create(['name' => $name]);
        $b->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $b->fresh();
    }

    public function test_new_badge_lasts_30_days_and_starts_from_the_first_7_3_buyer(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $vil = $this->buyerAt('VIL LOGICSTICS', '2026-10-21 22:06:07');
        $before = $this->buyerAt('ALPHA', '2026-10-20 09:00:00');   // 30일 안이지만 시작점 전
        $this->assertTrue($vil->isNewBuyer());
        $this->assertFalse($before->isNewBuyer(), 'VIL 보다 먼저 등록된 바이어는 안 붙는다(jin «그사람부터»)');

        Carbon::setTestNow('2026-11-20 22:00:00');
        $this->assertTrue($vil->fresh()->isNewBuyer(), '30일째까지');
        Carbon::setTestNow('2026-11-21 00:00:00');
        $this->assertFalse($vil->fresh()->isNewBuyer(), '30일 지나면 떨어진다');
    }

    public function test_buyer_list_shows_the_badge(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->buyerAt('VIL LOGICSTICS', '2026-10-21 22:06:07');
        $this->buyerAt('OLD BUYER', '2026-06-01 09:00:00');
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $html = Volt::test('erp.buyers.index')->html();
        $this->assertSame(2, substr_count($html, 'data-buyer-new'), '표 + 폰 카드에 각 1번 = VIL 1명만');
    }

    public function test_receivable_toggle_lists_only_vehicles_with_unpaid_amount(): void
    {
        $mk = function (string $no, ?int $unpaid) {
            $v = Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'purchase_price' => 1_000_000, 'purchase_date' => now()->subMonth()->toDateString()]);
            \DB::table('vehicles')->where('id', $v->id)->update(['sale_unpaid_amount_krw_cache' => $unpaid]);

            return $v;
        };
        $mk('미수1', 500_000);
        $mk('완납1', 0);
        $mk('과입금1', -1000);
        $mk('환율없음', null);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $this->assertSame(['미수1'], Vehicle::hasReceivable()->pluck('vehicle_number')->all());

        $c = Volt::test('erp.vehicles.index')->assertSeeHtml('data-filter-receivable')
            ->call('toggleTask', 'receivable')->assertSet('taskFilter', 'receivable');
        $html = $c->html();
        $this->assertStringContainsString('미수1', $html);
        $this->assertStringNotContainsString('완납1', $html);
        $this->assertStringNotContainsString('과입금1', $html);

        $c->call('toggleTask', 'receivable')->assertSet('taskFilter', '');   // 다시 누르면 해제
    }

    /**
     * 🏷️ 바이어 미정 토글 (jin 2026-11-05) — 목록 뱃지 「바이어미정」 차만. 다시 누르면 해제.
     * 계약금·매입잔금(board 요청) 줄에 있고, 미정 차가 없으면 안 뜬다(«있다가 없어지기도 하는 토글»).
     */
    public function test_buyer_undecided_toggle_lists_only_badged_vehicles(): void
    {
        $mk = fn (string $no, bool $undecided) => Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export',
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subMonth()->toDateString(), 'buyer_undecided' => $undecided]);
        $mk('정상1', false);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        Volt::test('erp.vehicles.index')->assertDontSeeHtml('data-filter-buyer-undecided');   // 미정 차 0 = 토글 없음

        $mk('미정1', true);
        $c = Volt::test('erp.vehicles.index')->assertSeeHtml('data-filter-buyer-undecided')
            ->assertSee(__('vehicle.buyer_undecided_badge').' (1)')
            ->call('toggleTask', 'receivable')->assertSeeHtml('data-filter-buyer-undecided')   // 다른 작업 필터가 켜져도 바꿔 누를 수 있다
            ->call('toggleTask', 'receivable')
            ->assertSee('정상1')   // 끄면 둘 다 보인다 — 아래 「안 보임」이 토글 때문임을 확인
            ->call('toggleTask', 'buyer_undecided')->assertSet('taskFilter', 'buyer_undecided');
        $html = $c->html();
        $this->assertStringContainsString('미정1', $html);
        $this->assertStringNotContainsString('정상1', $html);

        $c->call('toggleTask', 'buyer_undecided')->assertSet('taskFilter', '');
    }

    public function test_new_only_button_filters_the_list_like_the_sailing_pill(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->buyerAt('NEW ONE', '2026-11-10 09:00:00');
        $this->buyerAt('NEW TWO', '2026-11-01 09:00:00');
        $this->buyerAt('OLD BUYER', '2026-06-01 09:00:00');
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $c = Volt::test('erp.buyers.index');
        $this->assertSame(2, $c->instance()->newBuyerCount);
        $this->assertStringContainsString('OLD BUYER', $c->html());
        $this->assertMatchesRegularExpression('/data-new-only-filter[\s\S]*?신규 \(2\)/u', $c->html());

        $c->call('toggleNewOnly');
        $names = $c->instance()->buyers->pluck('name')->all();
        sort($names);
        $this->assertSame(['NEW ONE', 'NEW TWO'], $names, '배지와 같은 기준(Buyer::scopeNewBuyers)');
        $this->assertStringNotContainsString('OLD BUYER', $c->html());

        $c->call('toggleNewOnly');   // 다시 누르면 해제
        $this->assertCount(3, $c->instance()->buyers);

        $this->assertSame(
            Buyer::all()->filter->isNewBuyer()->pluck('id')->sort()->values()->all(),
            Buyer::newBuyers()->pluck('id')->sort()->values()->all(),
            '배지와 필터가 같은 바이어를 고른다'
        );
    }
}
