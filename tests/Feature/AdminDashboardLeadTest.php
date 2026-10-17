<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 관리자 대시보드 10-17 재편 (jin 「적용해줘」) — 리드 2카드 · 탭이 기준일을 정한다 · 기준일 선택기 없음 · 미수 카드는 한 번만.
 */
class AdminDashboardLeadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리']);
    }

    /** 탭 = 기준일. 영업 → 판매일, 수출통관 → 선적일(통관완료 날짜 컬럼이 없다), 재무 → 거래완료일(차량 표), 채권 → 기간 무관. */
    public function test_tab_sets_the_date_basis(): void
    {
        $this->actingAs($this->admin());
        $c = Volt::test('admin.dashboard');
        $this->assertSame('sale', $c->get('dateType'), '첫 탭 = 영업 → 판매일');
        foreach (['clearance' => 'shipping', 'settlement' => 'completed', 'receivable' => 'purchase', 'sales' => 'sale'] as $tab => $type) {
            $c->set('tab', $tab);
            $this->assertSame($type, $c->get('dateType'), "$tab 탭");
        }
        $c->set('tab', 'bogus');
        $this->assertSame('sales', $c->get('tab'));
    }

    /** 옛 링크·테스트가 dateType 을 직접 실으면 그 값을 존중한다(mount 가 덮지 않는다). */
    public function test_explicit_date_type_in_url_is_kept(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard', ['dateType' => 'purchase']))->assertOk()->assertSee('data-period-basis', false);
        $this->assertSame('purchase', Livewire::withQueryParams(['dateType' => 'purchase'])->test('admin.dashboard')->get('dateType'), 'URL 의 dateType 은 탭이 덮지 않는다');
        $this->assertSame('sale', Livewire::withQueryParams([])->test('admin.dashboard')->get('dateType'), '없으면 탭(영업) 기준 = 판매일');
    }

    /** 탭 전환은 캐시 salt 를 안 올린다 — remembered 키에 dateType 이 있어 되돌아온 탭은 캐시 히트(회사이익 10초 재계산 방지). */
    public function test_switching_tabs_does_not_bust_the_cache(): void
    {
        $this->actingAs($this->admin());
        $c = Volt::test('admin.dashboard');
        $salt = $c->get('cacheSalt');
        $c->set('tab', 'receivable')->set('tab', 'sales');
        $this->assertSame($salt, $c->get('cacheSalt'));
        $c->call('applyFilters');
        $this->assertSame($salt + 1, $c->get('cacheSalt'), '[조회]는 종전대로 새로 센다');
    }

    /** 리드 2카드(회사 순이익·받을 돈)가 탭 위에 있고, 기준일 select 와 채권 탭의 중복 미수 카드는 없다. */
    public function test_lead_cards_replace_the_date_selector_and_duplicate_receivable_cards(): void
    {
        $this->actingAs($this->admin());
        $html = Volt::test('admin.dashboard')->html();
        $this->assertStringContainsString('data-lead-cards', $html);
        $this->assertStringContainsString('data-lead-profit', $html);
        $this->assertStringContainsString('data-lead-recv', $html);
        $this->assertStringContainsString('data-period-basis', $html);
        $this->assertStringNotContainsString('wire:model="dateType"', $html, '기준일 선택기는 10-17 에 탭으로 대체됐다');
        $src = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $this->assertSame(1, substr_count($src, "vehiclesUrlAllTime(['action' => 'receivable_'.\$key])"), '등급 링크는 리드 카드 한 곳에만');
        $this->assertStringNotContainsString("recv_total_unpaid')", $src, '채권 탭의 총 미수 카드는 리드로 올라갔다');
    }

    /** 리드 회사 순이익은 조회 기간과 무관하게 이번 달 — 기간을 바꿔도 같은 값. */
    public function test_lead_profit_is_this_month_regardless_of_the_period(): void
    {
        $this->actingAs($this->admin());
        $c = Volt::test('admin.dashboard');
        $a = $c->instance()->companyProfitThisMonth['company_net'];
        $c->set('dateFrom', now()->subYear()->startOfYear()->toDateString())->set('dateTo', now()->subYear()->endOfYear()->toDateString())->call('applyFilters');
        $this->assertSame($a, $c->instance()->companyProfitThisMonth['company_net']);
    }
}
