<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Dashboard\WorkDashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 업무 대시보드 역할 뷰 5개 (jin 2026-10-17 「적용해줘」) — 누가 어느 뷰를 여는가 · 관리의 담당 영업 범위 · 뷰마다 그 블록이 그려지는가.
 */
class WorkDashboardRoleViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function vehicle(array $over): Vehicle
    {
        $defaults = ['sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false];
        if (($over['sale_price'] ?? 0) > 0) {
            $defaults['buyer_id'] = Buyer::firstOrCreate(['name' => 'TEST BUYER'], ['is_active' => true])->id;
            $defaults['sale_date'] = now()->subDays(40)->toDateString();
        }

        return Vehicle::create(array_merge($defaults, $over));
    }

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role]);
    }

    public function test_admin_and_work_manager_can_open_all_five_views_and_land_on_supervisor(): void
    {
        foreach (['admin', 'manager'] as $perm) {
            $this->actingAs($this->user($perm));
            $c = Volt::test('erp.dashboard');
            $this->assertSame('업무관리자', $c->get('roleView'), "$perm 첫 화면");
            foreach (['영업', '수출통관', '재무', '관리', '업무관리자'] as $v) {
                $c->set('roleView', $v);
                $this->assertSame($v, $c->get('roleView'), "$perm → $v");
            }
        }
    }

    public function test_management_role_gets_four_views_and_not_supervisor(): void
    {
        $this->actingAs($this->user('user', '관리'));
        $c = Volt::test('erp.dashboard');
        $this->assertSame('관리', $c->get('roleView'));
        $c->set('roleView', '업무관리자');
        $this->assertSame('영업', $c->get('roleView'), '관리는 업무관리자 뷰를 못 연다 — 첫 뷰로');
        $c->set('roleView', '재무');
        $this->assertSame('재무', $c->get('roleView'));
    }

    public function test_single_role_users_are_locked_to_their_own_view(): void
    {
        foreach (['재무', '수출통관'] as $role) {
            $this->actingAs($this->user('user', $role));
            $c = Volt::test('erp.dashboard')->set('roleView', '관리');
            $this->assertSame($role, $c->get('roleView'));
            $c->assertDontSee('data-role-pills', false);
        }
    }

    /** 관리의 「담당 영업」 뷰는 배정된 영업만 — 다른 팀 차량은 돈 막힌 차 표·팀 합계에 안 나온다. */
    public function test_management_team_view_is_scoped_to_assigned_salesmen(): void
    {
        $manager = $this->user('user', '관리');
        $mine = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $other = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager->managedSalesmanUsers()->attach($mine->id);
        $smMine = Salesman::create(['user_id' => $mine->id, 'name' => '팀원영업', 'is_active' => true]);
        $smOther = Salesman::create(['user_id' => $other->id, 'name' => '남의영업', 'is_active' => true]);
        $mk = fn (Salesman $sm, string $no) => $this->vehicle([
            'salesman_id' => $sm->id, 'vehicle_number' => $no, 'sale_price' => 10000, 'currency' => 'USD', 'exchange_rate' => 1300, 'purchase_price' => 5_000_000,
        ]);
        $mk($smMine, '11가1111');
        $mk($smOther, '22나2222');

        $this->actingAs($manager);
        $c = Volt::test('erp.dashboard')->set('roleView', '영업');
        $c->assertSee('11가1111')->assertDontSee('22나2222');
        $this->assertSame(1, $c->instance()->teamTotals['unpaid_count']);
        $this->assertSame([$smMine->id], $c->instance()->teamScope());
    }

    public function test_each_view_renders_its_own_blocks(): void
    {
        $this->actingAs($this->user('admin'));
        $expect = [
            '영업' => ['data-team-totals', 'data-team-blocked', 'data-team-purchase', 'data-team-next'],
            '수출통관' => ['data-clearance-kanban', 'data-clearance-week', 'data-document-table'],
            '재무' => ['data-finance-money', 'data-finance-steps', 'data-finance-risk', 'data-cash-card'],
            '관리' => ['data-manage-two', 'data-document-table'],
            '업무관리자' => ['data-supervisor-submit', 'data-supervisor-stuck', 'data-supervisor-teams', 'data-supervisor-report'],
        ];
        foreach ($expect as $view => $markers) {
            $c = Volt::test('erp.dashboard')->set('roleView', $view);
            $html = $c->html();
            foreach ($markers as $m) {
                $this->assertStringContainsString($m, $html, "$view 뷰에 $m 블록");
            }
            // 모든 뷰 공통 — 처리 필요 항목(관리 뷰는 「오늘 먼저」)과 진행 단계 스트립 (LocaleTest 와 같은 문구).
            $this->assertStringContainsString('data-actions', $html);
            $this->assertStringContainsString(__('domain.pipeline_title'), $html);
        }
    }

    /** 오래 멈춘 차는 새 N일을 만들지 않고 기존 정체 큐를 합친다 — 통관 정체(30일)·ETA 없음이 그대로 잡힌다. */
    public function test_stuck_vehicles_reuse_existing_queues(): void
    {
        $sm = Salesman::create(['name' => 'STUCK 영업', 'is_active' => true]);
        // 통관 정체 = 완납(미수 0) + 면장 없음 + 판매 후 30일 — 잔금을 확정 입금으로 넣어 완납을 만든다.
        $stuck = $this->vehicle(['salesman_id' => $sm->id, 'vehicle_number' => '33다3333', 'sale_price' => 100, 'sale_date' => now()->subDays(45)->toDateString(), 'purchase_price' => 100]);
        $stuck->finalPayments()->create(['type' => 'balance', 'amount' => 100, 'payment_date' => now()->subDays(44)->toDateString(), 'confirmed_at' => now(), 'exchange_rate' => 1]);
        $stuck->refresh()->refreshProgressCache();
        $noEta = $this->vehicle(['salesman_id' => $sm->id, 'vehicle_number' => '44라4444', 'sale_price' => 100, 'shipping_date' => now()->subDays(12)->toDateString(), 'eta_date' => null, 'purchase_price' => 100]);

        $rows = app(WorkDashboardData::class)->stuckVehicles();
        $byNo = $rows->keyBy(fn ($r) => $r['vehicle']->vehicle_number);
        $this->assertSame('clearance', $byNo['33다3333']['where'] ?? null);
        $this->assertSame('eta', $byNo['44라4444']['where'] ?? null);
        $this->assertSame(12, $byNo['44라4444']['days']);
    }
}
