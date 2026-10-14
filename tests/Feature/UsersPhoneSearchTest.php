<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 사용자 관리 검색에 전화번호 (jin 2026-10-14). 저장값은 하이픈 포함이라 숫자만 쳐도 걸려야 한다. */
class UsersPhoneSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_phone_with_or_without_hyphens(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        User::factory()->create(['name' => '홍길동', 'phone' => '010-4613-6834', 'permission' => 'user', 'role' => '영업', 'type' => 'employee']);
        User::factory()->create(['name' => '김철수', 'phone' => '010-1111-2222', 'permission' => 'user', 'role' => '영업', 'type' => 'employee']);
        $this->actingAs($admin);

        Volt::test('admin.users.index')->set('search', '4613-68')->assertSee('홍길동')->assertDontSee('김철수');
        Volt::test('admin.users.index')->set('search', '46136834')->assertSee('홍길동')->assertDontSee('김철수');
    }
}
