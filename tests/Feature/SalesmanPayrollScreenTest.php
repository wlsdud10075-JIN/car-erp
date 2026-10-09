<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧑‍💼 사내직원관리(구 영업담당자) — 월정산 v3 2일차 (2026-10-09).
 *
 * - 접근 = 관리 이상 + **재무**(급여 입력). 재무는 급여·예치금만, 나머지(보충 필드·tier·제외·삭제·승계)는 관리 이상.
 * - 검차직원 = 계정 없이 이름만 등록. 유형의 출처는 `salesmen.type`.
 * - 급여 항목 = 귀속월별 18항목 + 직접 추가, 매달 빈칸. 구 「기본급」 칸은 화면에서 사라진다.
 * - 검차직원은 차량·바이어 담당자 목록(`scopeSales`)에 안 나온다.
 */
class SalesmanPayrollScreenTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'email_verified_at' => now()]);
    }

    private function employee(): Salesman
    {
        return Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'phone' => '010-1111-2222']);
    }

    // ── 접근 ───────────────────────────────────────────────────────────

    public function test_finance_and_managers_can_open_the_screen_but_sales_cannot(): void
    {
        $this->actingAs($this->user('user', '재무'))->get(route('erp.salesmen.index'))->assertOk();
        $this->actingAs($this->user('user', '관리'))->get(route('erp.salesmen.index'))->assertOk();
        $this->actingAs($this->user('manager'))->get(route('erp.salesmen.index'))->assertOk();
        $this->actingAs($this->user('user', '영업'))->get(route('erp.salesmen.index'))->assertForbidden();
        $this->actingAs($this->user('user', '수출통관'))->get(route('erp.salesmen.index'))->assertForbidden();
    }

    public function test_sidebar_and_route_use_the_same_gate(): void
    {
        $sidebar = file_get_contents(resource_path('views/components/layouts/app/sidebar.blade.php'));
        $i = strpos($sidebar, "__('nav.menu.salesmen')");
        $window = substr($sidebar, $i, 400);
        $this->assertStringContainsString('canAccessSettlement()', $window, '사이드바 노출 조건이 라우트(settlement)와 갈렸다');
        $this->assertStringNotContainsString('canAccessAdmin()', $window);
    }

    // ── 검차직원 등록 ─────────────────────────────────────────────────

    public function test_an_inspector_is_registered_by_name_only(): void
    {
        $this->actingAs($this->user('admin'));

        Volt::test('erp.salesmen.index')->call('openCreate')
            ->assertSet('create_type', 'inspector')
            ->assertDontSee(__('salesman.field.account_none'))   // 검차 등록엔 계정 select 자체가 없다
            ->set('name', '오검차')->call('save')->assertHasNoErrors();

        $sm = Salesman::where('name', '오검차')->firstOrFail();
        $this->assertSame('inspector', $sm->type);
        $this->assertNull($sm->user_id);
        $this->assertTrue($sm->isInspector());
        $this->assertSame(0, Salesman::query()->sales()->where('id', $sm->id)->count(), '검차직원은 영업 목록(sales)에서 빠져야 한다');
    }

    public function test_finance_cannot_register_or_delete_or_hand_over(): void
    {
        $sm = $this->employee();
        $this->actingAs($this->user('user', '재무'));

        Volt::test('erp.salesmen.index')->call('openCreate')->set('name', '몰래')->call('save')->assertStatus(403);
        $this->assertNull(Salesman::where('name', '몰래')->first());

        Volt::test('erp.salesmen.index')->call('delete', $sm->id)->assertStatus(403);
        $this->assertNotNull($sm->fresh());

        $to = Salesman::create(['name' => '받을사람', 'type' => 'employee', 'is_active' => true]);
        Volt::test('erp.salesmen.index')->set('handoverFromId', $sm->id)->set('handoverToId', (string) $to->id)
            ->call('runHandover')->assertStatus(403);
    }

    // ── 급여 항목 ─────────────────────────────────────────────────────

    public function test_employee_and_inspector_see_payroll_and_freelancer_sees_deposit(): void
    {
        $this->actingAs($this->user('admin'));
        $inspector = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        $free = Salesman::create(['name' => '최딜러', 'type' => 'freelance', 'is_active' => true]);

        Volt::test('erp.salesmen.index')->call('openEdit', $this->employee()->id)
            ->assertSee(__('salesman.payroll.title'))->assertDontSee(__('salesman.field.base_salary'))->assertDontSee(__('salesman.field.deposit'));
        Volt::test('erp.salesmen.index')->call('openEdit', $inspector->id)
            ->assertSee(__('salesman.payroll.title'))->assertDontSee(__('salesman.field.per_unit_tier'));
        Volt::test('erp.salesmen.index')->call('openEdit', $free->id)
            ->assertSee(__('salesman.field.deposit'))->assertDontSee(__('salesman.payroll.title'));
    }

    public function test_finance_saves_payroll_by_month_and_nothing_else(): void
    {
        $sm = $this->employee();
        $sm->update(['per_unit_tier_enabled' => false, 'payout_excluded' => false]);
        $this->actingAs($this->user('user', '재무'));

        $c = Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)
            ->assertSee(__('salesman.payroll.title'))->assertSee(__('salesman.payroll.not_entered'))
            ->set('payrollMonth', '2026-10')
            ->set('payrollItems.0', '2,340,000')     // 기본급 — 화면이 넣는 모양(콤마) 그대로
            ->set('payrollItems.2', '200000')        // 식대
            ->set('payrollItems.8', '-50,000')       // 전월소급 — 음수
            ->call('addPayrollRow')
            ->set('payrollCustom.0.label', '명절상여')->set('payrollCustom.0.amount', '300000')
            // 재무가 폼 변조로 보낸 값들 — 전부 버려져야 한다
            ->set('per_unit_tier_enabled', true)->set('payout_excluded', true)->set('phone', '010-9999-9999')->set('memo', '몰래')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(2_790_000, PayrollEntry::totalFor($sm->id, '2026-10'));
        $this->assertNull(PayrollEntry::totalFor($sm->id, '2026-11'), '다른 달은 그대로 빈칸');
        $this->assertSame(['기본급', '식대', '전월소급', '명절상여'],
            PayrollEntry::where('salesman_id', $sm->id)->forMonth('2026-10')->orderBy('sort')->pluck('label')->all());

        $fresh = $sm->fresh();
        $this->assertFalse((bool) $fresh->per_unit_tier_enabled, '재무가 tier 를 켰다');
        $this->assertFalse((bool) $fresh->payout_excluded, '재무가 지급 제외를 켰다');
        $this->assertSame('010-1111-2222', $fresh->phone, '재무가 보충 필드를 바꿨다');
        $this->assertNull($fresh->memo);
        $this->assertNull($fresh->base_salary_krw, '구 기본급 칸에 값이 들어갔다 — v3 부터 안 쓴다');

        $this->assertSame(1, AuditLog::query()->where('column_name', 'payroll_2026-10')->count(), '지급합계 변화는 감사로그 1줄');

        // 다시 열면 그 달 값이 그대로(왕복) — 불러오는 쪽이 값을 깎으면 다음 저장이 원본을 덮는다(§8 #91)
        $c->call('openEdit', $sm->id)->set('payrollMonth', '2026-10')
            ->assertSet('payrollItems.0', '2340000')->assertSet('payrollItems.8', '-50000')
            ->assertSet('payrollCustom.0.label', '명절상여')->assertSet('payrollEntered', true)
            ->call('save');
        $this->assertSame(2_790_000, PayrollEntry::totalFor($sm->id, '2026-10'), '재저장이 값을 깎았다');
        $this->assertSame(1, AuditLog::query()->where('column_name', 'payroll_2026-10')->count(), '무변경 재저장이 감사로그를 또 남겼다');
    }

    public function test_blank_is_not_entered_and_zero_is_explicit(): void
    {
        $sm = $this->employee();
        $this->actingAs($this->user('admin'));

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('payrollMonth', '2026-10')->call('save');
        $this->assertNull(PayrollEntry::totalFor($sm->id, '2026-10'), '전부 빈칸이면 미입력');

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('payrollMonth', '2026-10')->set('payrollItems.0', '0')->call('save');
        $this->assertSame(0, PayrollEntry::totalFor($sm->id, '2026-10'), '0 은 「없음」을 적은 것');
    }

    public function test_switching_the_month_reloads_that_month(): void
    {
        $sm = $this->employee();
        PayrollEntry::replaceFor($sm->id, '2026-09', [['label' => '기본급', 'amount' => 1_000_000]]);
        $this->actingAs($this->user('admin'));

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)
            ->set('payrollMonth', '2026-10')->assertSet('payrollItems.0', '')->assertSet('payrollEntered', false)
            ->set('payrollMonth', '2026-09')->assertSet('payrollItems.0', '1000000')->assertSet('payrollEntered', true)
            ->set('payrollMonth', '2026-13')->assertSet('payrollMonth', now()->format('Y-m'));
    }

    public function test_payroll_amount_inputs_accept_a_minus_sign(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/erp/salesmen/index.blade.php'));
        preg_match_all('/<input[^>]*wire:model[^>]*payroll(Items|Custom)[^>]*amount[^>]*>|<input[^>]*payrollItems\.\{\{ \$i \}\}[^>]*>/', $blade, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('data-money-signed', $tag, '급여 금액칸은 음수(전월소급)를 받아야 한다 — §8 #58');
        }
    }
}
