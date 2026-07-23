<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 안내 카탈로그 (2026-07-23, jin) — 누가·언제·어떤 내용을 받는지 읽기 전용 화면.
 */
class AlimtalkCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_catalog_with_templates(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        Volt::actingAs($admin)->test('admin.alimtalk-catalog.index')
            ->assertOk()
            ->assertSee('보증금매입독촉')                    // 이름
            ->assertSee('erp_deposit_cash_due')              // 코드
            ->assertSee('담당 영업');                        // 수신자 라벨
    }

    public function test_non_admin_forbidden(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        Volt::actingAs($sales)->test('admin.alimtalk-catalog.index')->assertStatus(403);
    }
}
