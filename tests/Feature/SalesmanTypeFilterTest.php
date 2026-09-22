<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔎 영업담당자 탭 — 사내직원 / 프리랜서 pill 필터 (jin 2026-09-22).
 *
 * jin: *「ssancarerp 의 경우는 사람이 많은데 사람을 검색해서 하나하나 찾기에는 쉽지않을것 같아.」*
 *
 * 기준은 `salesmen.type` — 정산 훅이 읽는 그 값(user.type 의 미러). 화면과 지급 계산이 같은 값을 본다.
 * `salesmen.type` 은 NOT NULL default 'employee' 라(마이그 2026_05_20_000011) 계정 미연결 담당자는
 * 「사내직원」 pill 에 잡힌다 — 세 번째 칸을 만들지 않는다.
 * 🚫 표시·필터뿐이다 — 정산액·지급액은 건드리지 않는다.
 */
class SalesmanTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function seedThree(): void
    {
        Salesman::create(['name' => '직원갑', 'type' => 'employee', 'is_active' => true]);
        Salesman::create(['name' => '프리을', 'type' => 'freelance', 'is_active' => true]);
        Salesman::create(['name' => '미연결병', 'is_active' => true]);   // type 생략 → DB default 'employee'
    }

    public function test_all_shows_every_salesman_including_unlinked(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')
            ->assertSee('직원갑')->assertSee('프리을')->assertSee('미연결병');
    }

    /** 계정 미연결 담당자는 DB 기본값 employee 라 「사내직원」에 같이 잡힌다. */
    public function test_employee_pill_hides_freelancers_and_keeps_unlinked(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')
            ->call('setTypeFilter', 'employee')
            ->assertSet('typeFilter', 'employee')
            ->assertSee('직원갑')->assertSee('미연결병')->assertDontSee('프리을');
    }

    public function test_freelance_pill_hides_employees_and_unlinked(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')
            ->call('setTypeFilter', 'freelance')
            ->assertSee('프리을')->assertDontSee('직원갑')->assertDontSee('미연결병');
    }

    /** `?type=freelance` 로 들어와도 같은 결과 — 새로고침·링크 공유용. */
    public function test_filter_survives_in_the_url(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['type' => 'freelance']);
        try {
            Volt::test('erp.salesmen.index')
                ->assertSet('typeFilter', 'freelance')
                ->assertSee('프리을')->assertDontSee('직원갑');
        } finally {
            Livewire::withQueryParams([]);
        }
    }

    /** 모르는 값은 「전체」로 떨어진다 — URL 을 손으로 고쳐도 빈 목록이 되지 않는다. */
    public function test_unknown_type_falls_back_to_all(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')
            ->call('setTypeFilter', 'ghost')
            ->assertSet('typeFilter', '')
            ->assertSee('직원갑')->assertSee('프리을')->assertSee('미연결병');
    }

    /** 유형 열 — 라벨이 목록에 실제로 찍힌다. */
    public function test_type_column_is_rendered(): void
    {
        $this->seedThree();
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')
            ->assertSeeInOrder(['직원갑', Salesman::TYPES['employee']])
            ->assertSeeInOrder(['프리을', Salesman::TYPES['freelance']]);
    }
}
