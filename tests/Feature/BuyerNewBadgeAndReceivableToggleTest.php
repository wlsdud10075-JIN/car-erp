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
}
