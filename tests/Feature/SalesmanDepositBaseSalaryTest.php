<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💰 **예치금(프리랜서) · 기본급(사내직원)** — 영업담당자 탭 (jin 2026-09-18).
 *
 * jin: *「프리랜서는 예치금, 사내직원은 기본급 … 영업담당자탭에 나타내게 하는데
 *        **사용자관리에서 프리랜서냐 사내직원이냐에 따라서** 그걸 기입 할 수 있게 해주고」*
 *
 * 🔑 `Salesman.type` 은 `User.type` 의 미러다(2026-05-21 결정) — 유형은 `/admin/users` 에서만 바꾼다.
 *    그래서 **연결된 계정의 유형**으로 칸을 고른다.
 *
 * 🚫 두 값 모두 **표시 전용**이다 — 지급액·배치 총액·회사이익에 들어가지 않는다.
 */
class SalesmanDepositBaseSalaryTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    /** ERP 는 들어가지만 [관리] 이상은 아닌 사람 — 금액칸이 보이면 안 된다. */
    private function sales(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
    }

    private function salesman(?string $userType): Salesman
    {
        $u = $userType === null
            ? null
            : User::factory()->create([
                'permission' => 'user', 'role' => '영업', 'type' => $userType, 'email_verified_at' => now(),
            ]);

        return Salesman::create([
            'name' => '표본', 'user_id' => $u?->id,
            'type' => $userType === 'freelance' ? 'freelance' : 'employee', 'is_active' => true,
        ]);
    }

    // ── 칸이 보이는 조건 ────────────────────────────────────────────────

    /** 프리랜서 → 예치금만. 기본급칸은 없다. */
    public function test_a_freelancer_sees_only_the_deposit_field(): void
    {
        $sm = $this->salesman('freelance');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->assertSee(__('salesman.field.deposit'))
            ->assertDontSee(__('salesman.field.base_salary'));
    }

    /** 사내직원 → 기본급만. */
    public function test_an_employee_sees_only_the_base_salary_field(): void
    {
        $sm = $this->salesman('employee');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->assertSee(__('salesman.field.base_salary'))
            ->assertDontSee(__('salesman.field.deposit'));
    }

    /**
     * 🚫 **유형이 안 붙은 담당자에겐 둘 다 안 보인다** — 엉뚱한 칸에 금액이 들어가는 것보다
     *    비어 있는 게 낫다. 계정이 없거나 계정의 유형이 비어 있는 담당자가 실제로 있다.
     */
    public function test_an_untyped_salesman_sees_neither(): void
    {
        $sm = $this->salesman(null);   // 연결 계정 없음
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->assertDontSee(__('salesman.field.deposit'))
            ->assertDontSee(__('salesman.field.base_salary'));
    }

    /** 돈 직결이라 [관리] 이상만 — tier 와 같은 선(jin 2026-09-18 「관리이상으로 유지」). */
    public function test_below_manager_sees_neither(): void
    {
        $sm = $this->salesman('freelance');
        $this->actingAs($this->sales());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->assertDontSee(__('salesman.field.deposit'))
            ->assertDontSee(__('salesman.field.base_salary'));
    }

    // ── 저장 왕복 ───────────────────────────────────────────────────────

    /**
     * 🔁 **왕복** — 저장 → 다시 열기 → 아무것도 안 바꾸고 재저장해도 값이 살아 있어야 한다.
     *    불러오는 쪽이 값을 깎으면 다음 저장이 원본을 덮는다(§8 #91 의 그 형태).
     */
    public function test_the_deposit_survives_a_round_trip(): void
    {
        $sm = $this->salesman('freelance');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('deposit_krw_str', '10,000,000')     // 콤마 포함 — 화면이 넣는 모양 그대로
            ->call('save');

        $this->assertSame(10_000_000, $sm->fresh()->deposit_krw);

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->call('save');
        $this->assertSame(10_000_000, $sm->fresh()->deposit_krw, '재저장이 값을 깎았다');
    }

    /** 사내직원도 같다. */
    public function test_the_base_salary_survives_a_round_trip(): void
    {
        $sm = $this->salesman('employee');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('base_salary_krw_str', '2740000')
            ->call('save');

        $this->assertSame(2_740_000, $sm->fresh()->base_salary_krw);
    }

    /**
     * 🔑 **0 과 미입력은 다르다** — 빈칸은 null 로 남아 화면에 「−」가 뜨고, 0 은 「없음」을 명시한 것이다.
     *    default 0 으로 두면 전 담당자가 「기본급 0원」으로 보여 미입력을 영영 못 찾는다.
     */
    public function test_blank_stays_null_and_zero_stays_zero(): void
    {
        $sm = $this->salesman('employee');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->call('save');
        $this->assertNull($sm->fresh()->base_salary_krw, '빈칸이 0 으로 저장됐다');

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)->set('base_salary_krw_str', '0')->call('save');
        $this->assertSame(0, $sm->fresh()->base_salary_krw);
    }

    /**
     * 🚫 **반대쪽 칸은 안 건드린다** — 사내직원 화면에서 예치금 값을 밀어 넣어도 저장되지 않는다.
     *    화면에 그 칸이 없으므로 들어올 길은 프로퍼티 직접 주입뿐이다(§8 #26 — 저장 시점 재판정).
     */
    public function test_the_other_field_is_never_written(): void
    {
        $sm = $this->salesman('employee');
        $this->actingAs($this->manager());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('deposit_krw_str', '99,999,999')
            ->call('save');

        $this->assertNull($sm->fresh()->deposit_krw, '사내직원에게 예치금이 저장됐다');
    }

    /** [관리] 미만은 저장도 못 한다 — 화면에서 숨긴 것과 별개로 저장 시점에 다시 본다. */
    public function test_below_manager_cannot_save_the_amount(): void
    {
        $sm = $this->salesman('freelance');
        $this->actingAs($this->sales());

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('deposit_krw_str', '5000000')
            ->call('save');

        $this->assertNull($sm->fresh()->deposit_krw, '권한 없는 사람이 금액을 넣었다');
    }

    // ── 감사 ────────────────────────────────────────────────────────────

    /** 🧾 돈이 오가는 값이라 누가 언제 바꿨는지 남는다 (Salesman 엔 감사 훅이 없어 저장부에서 직접). */
    public function test_a_change_is_audited_and_a_no_op_is_not(): void
    {
        $sm = $this->salesman('freelance');
        $this->actingAs($this->manager());
        AuditLog::query()->delete();

        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)->set('deposit_krw_str', '3000000')->call('save');

        $this->assertSame(1, AuditLog::query()->where('column_name', 'deposit_krw')->count(),
            '예치금 변경이 감사로그에 안 남았다');

        // 같은 값을 다시 저장 — 소음이 쌓이면 안 된다(§8 #108).
        Volt::test('erp.salesmen.index')
            ->call('openEdit', $sm->id)->call('save');

        $this->assertSame(1, AuditLog::query()->where('column_name', 'deposit_krw')->count(),
            '값을 안 바꿨는데 감사로그가 또 쌓였다');
    }

    // ── 월수령액 ────────────────────────────────────────────────────────

    /**
     * 💴 **월수령액 = 기본급 + 정산.** 🚫 이름을 「실지급액」으로 쓰지 말 것 —
     *    ERP 에서 실지급액은 `Settlement::actual_payout` 이고 정산관리·월배치·엑셀 3곳이 이미 쓴다.
     */
    public function test_monthly_take_home_adds_the_base_salary(): void
    {
        $employee = $this->salesman('employee');
        $employee->update(['base_salary_krw' => 2_740_000]);
        $this->assertSame(4_540_000, $employee->fresh()->monthlyTakeHome(1_800_000));

        // 프리랜서는 기본급이 없으므로 정산액 그대로.
        $freelancer = $this->salesman('freelance');
        $this->assertSame(11_842_007, $freelancer->monthlyTakeHome(11_842_007));
    }
}
