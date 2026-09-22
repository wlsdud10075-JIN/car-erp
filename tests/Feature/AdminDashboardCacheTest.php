<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * ⚡ 관리자 대시보드 집계 캐시 (jin 2026-09-22).
 *
 * 운영 ssancarerp 문서 요청 10초 · 30초 poll 마다 반복. 남은 비용이 정산 마진 사슬 재계산이라
 * 집계 결과를 사용자·스코프·기간별로 짧게 캐시한다. 규칙 3줄:
 *   ① TTL 안에서는 데이터가 바뀌어도 같은 값(poll 은 캐시를 읽는다)
 *   ② [조회](applyFilters) 는 salt 를 올려 항상 새로 계산한다
 *   ③ TTL 0 이면 캐시 없음 — phpunit 은 0 이라 다른 테스트는 종전대로 라이브 값을 본다
 */
class AdminDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function paidSettlement(Salesman $sm): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'CA-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1000,
            'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 1_000_000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10_000,
            'payment_date' => '2026-05-05', 'confirmed_at' => now(), 'exchange_rate_at_payment' => 1000,
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => '2026-05-20',
            'secondary_status' => 'closed', 'confirmed_at' => '2026-05-10',
        ]);
    }

    private function profit(User $admin): array
    {
        $this->actingAs($admin);

        return Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')->set('dateTo', '2026-05-31')
            ->instance()->companyProfit;
    }

    public function test_within_ttl_the_figure_is_served_from_cache_and_apply_filters_bypasses_it(): void
    {
        config(['services.admin_dashboard.cache_seconds' => 60]);
        $admin = $this->admin();
        $sm = Salesman::create(['name' => 'A', 'is_active' => true, 'type' => 'freelance']);
        $this->paidSettlement($sm);

        $first = $this->profit($admin)['payout_sum'];
        $this->paidSettlement($sm);                       // 데이터가 늘었다

        $this->assertSame($first, $this->profit($admin)['payout_sum'], 'TTL 안인데 새로 계산했다 — poll 마다 10초가 다시 돈다');

        $this->actingAs($admin);
        $fresh = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->instance()->companyProfit['payout_sum'];
        $this->assertGreaterThan($first, $fresh, '[조회] 를 눌렀는데 캐시를 그대로 돌려줬다');
    }

    public function test_ttl_zero_means_live_figures(): void
    {
        config(['services.admin_dashboard.cache_seconds' => 0]);
        $admin = $this->admin();
        $sm = Salesman::create(['name' => 'A', 'is_active' => true, 'type' => 'freelance']);
        $this->paidSettlement($sm);

        $first = $this->profit($admin)['payout_sum'];
        $this->paidSettlement($sm);

        $this->assertGreaterThan($first, $this->profit($admin)['payout_sum']);
    }

    public function test_cache_is_scoped_per_user(): void
    {
        config(['services.admin_dashboard.cache_seconds' => 60]);
        $sm = Salesman::create(['name' => 'A', 'is_active' => true, 'type' => 'freelance']);
        $this->paidSettlement($sm);

        $a = $this->profit($this->admin())['payout_sum'];
        $this->paidSettlement($sm);
        $b = $this->profit($this->admin())['payout_sum'];   // 다른 사용자 = 다른 키 = 새로 계산

        $this->assertGreaterThan($a, $b, '다른 사용자가 남의 캐시를 읽었다');
    }
}
