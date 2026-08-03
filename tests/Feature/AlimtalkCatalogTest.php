<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 안내 카탈로그 (2026-07-23, jin) — super 전용 + 알림별 수신 역할 선택.
 */
class AlimtalkCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_sees_catalog_with_templates(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->assertOk()
            ->assertSee('보증금매입독촉')
            ->assertSee('erp_deposit_cash_due')
            ->assertSee('담당 영업 본인');   // 본인형 자동 라벨
    }

    public function test_admin_forbidden_super_only(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        Volt::actingAs($admin)->test('admin.alimtalk-catalog.index')->assertStatus(403);
    }

    public function test_save_roles_persists_and_changes_recipients(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        // 영업 역할 사용자(전화 있음) — 기본값엔 영업이 없어 안 받다가, 선택하면 받아야.
        User::factory()->create(['permission' => 'user', 'role' => '영업', 'phone' => '010-7777-0000', 'email_verified_at' => now()]);

        $set = Setting::companyTemplateSet();
        $this->assertNotContains('010-7777-0000', AlimtalkRecipients::forBroadcast('erp_sale_unpaid'));

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('roles.erp_sale_unpaid', ['영업'])
            ->call('saveRoles', 'erp_sale_unpaid');

        $this->assertSame('영업', Setting::get("alimtalk_roles_erp_sale_unpaid_{$set}"));
        $this->assertSame(['010-7777-0000'], AlimtalkRecipients::forBroadcast('erp_sale_unpaid'));
    }

    public function test_default_roles_preserve_current_behavior(): void
    {
        // 미설정이면 기본값(관리·업무관리자)로 해석 — 기존 managers() 와 동일 인원.
        $mgr = User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);

        $this->assertSame(['010-2222-0000'], AlimtalkRecipients::forBroadcast('erp_sale_unpaid'));
    }

    /**
     * 시스템관리자(super) 는 **명시 선택 시에만** 수신한다 (jin 2026-08-03 — 개발자가 실물을 봐야 검증 가능).
     * ⚠️ 기본값·다른 역할로는 절대 딸려오면 안 된다.
     */
    public function test_super_receives_only_when_explicitly_selected(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'role' => '관리', 'phone' => '010-9999-0000', 'email_verified_at' => now()]);
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-2222-0000', 'email_verified_at' => now()]);

        // 기본값(관리·업무관리자) — role='관리' 를 겸직해도 super 는 안 받는다.
        $this->assertSame(['010-2222-0000'], AlimtalkRecipients::forBroadcast('erp_sale_unpaid'));

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('roles.erp_sale_unpaid', ['관리', 'super'])
            ->call('saveRoles', 'erp_sale_unpaid');

        $phones = AlimtalkRecipients::forBroadcast('erp_sale_unpaid');
        $this->assertContains('010-9999-0000', $phones);
        $this->assertContains('010-2222-0000', $phones);
        $this->assertArrayHasKey('super', AlimtalkRecipients::BROADCAST_GROUPS);
        // 자동 발송 대상 목록에는 절대 없어야 — 넣으면 전 알림이 super 에게 간다.
        foreach (AlimtalkRecipients::DEFAULT_ROLES as $code => $roles) {
            $this->assertNotContains('super', $roles, "DEFAULT_ROLES[{$code}] 에 super 가 있으면 안 됩니다.");
        }
    }
}
