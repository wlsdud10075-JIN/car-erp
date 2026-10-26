<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사용자관리 권한 → 하위 묶음 칩 (jin 2026-10-26) — 일반사용자는 역할(영업·재무·수출통관·관리), 최고관리자는 결재 직급(부장·전무·대표·미지정).
 * 목록은 두 열 카드(표 아님).
 */
class UsersFilterChipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_title_chips_narrow_the_list_and_toggle_off(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'name' => '대표님', 'approval_title' => '대표']);
        $bujang = User::factory()->create(['permission' => 'admin', 'name' => '부장님', 'approval_title' => '부장']);
        $plainAdmin = User::factory()->create(['permission' => 'admin', 'name' => '직급없음']);
        $mgr = User::factory()->create(['permission' => 'manager', 'name' => '업무관리자님', 'role' => '관리']);
        $gwanri = User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '관리담당']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'name' => '재무담당']);
        $this->actingAs($admin);

        $names = fn ($c) => $c->instance()->users->pluck('name')->sort()->values()->all();

        $c = Volt::test('admin.users.index')->call('pickRole', '관리');
        $this->assertSame(['관리담당'], $names($c), '일반사용자 중 관리만 — 업무관리자(role 관리)는 섞이지 않는다');
        $this->assertSame('user', $c->get('permFilter'));

        $c->call('pickRole', '관리');
        $this->assertSame('', $c->get('roleFilter'), '다시 누르면 해제');
        $this->assertSame(['관리담당', '재무담당'], $names($c), '일반사용자 전체로 돌아간다');

        $c->call('pickTitle', '부장');
        $this->assertSame(['부장님'], $names($c));
        $c->call('pickTitle', '__none');
        $this->assertSame(['직급없음'], $names($c), '직급 미지정 최고관리자');

        $c->call('pickPerm', 'manager');
        $this->assertSame(['업무관리자님'], $names($c));
        $this->assertSame('', $c->get('titleFilter'));

        $c->call('pickPerm', '');
        $this->assertCount(6, $names($c));

        $c->call('pickRole', '해커');
        $this->assertSame('', $c->get('roleFilter'), '허용 밖 값은 무시');
    }

    public function test_list_is_a_two_column_card_grid(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        $html = Volt::test('admin.users.index')->html();
        $this->assertStringContainsString('lg:grid-cols-2', $html);
        $this->assertStringNotContainsString('<table', $html, '사용자 목록은 표가 아니라 두 열 카드');
    }
}
