<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 안내 카탈로그 (2026-07-23, jin) — super 전용 + 알림별 수신 역할 선택.
 */
class AlimtalkCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🕑 시각 규칙 편집기 (2026-08-11) — 화면에서 규칙을 실제로 고칠 수 있어야 한다.
     * 엔진만 만들고 편집 UI 가 없으면 jin 이 근무시간을 못 바꾼다(설정이 코드에 박힌 것과 같다).
     */
    public function test_super_can_edit_time_rules_and_holidays(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'phone' => '010-2222-2222', 'email_verified_at' => now(),
        ]);
        Carbon::setTestNow('2026-08-11 10:00:00');   // 화요일 오전

        $c = Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->assertOk()
            ->assertSee('erp_board_request');

        $c->set('timeRules.erp_board_request', [
            ['to' => '재무', 'days' => [1, 2, 3, 4, 5], 'from' => '9:00', 'till' => '18:00'],
        ])->call('saveTimeRules', 'erp_board_request');

        $this->assertSame(
            [['to' => '재무', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'till' => '18:00']],
            AlimtalkRecipients::timeRules('erp_board_request'),
            '규칙이 저장되지 않았거나 시각이 정규화되지 않았다'
        );
        $this->assertSame(['010-2222-2222'], AlimtalkRecipients::forTimeRules('erp_board_request'));

        // 공휴일 — 형식이 틀린 줄은 버려지고, 화면에도 인식된 것만 되돌아온다.
        $c->set('holidays', '2026-08-11
엉터리')->call('saveHolidays');
        $this->assertSame(['2026-08-11'], AlimtalkRecipients::holidays());
        $this->assertSame('2026-08-11', $c->get('holidays'));

        Carbon::setTestNow();
    }

    /**
     * ⚠️ **행을 전부 지운 채 저장해도 "아무도 안 받음"이 되면 안 된다** — 조용히 0명에게
     * 가는 게 최악이라, 빈 규칙은 기본값으로 되돌린다.
     */
    public function test_clearing_every_rule_falls_back_to_defaults(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.erp_board_request', [])
            ->call('saveTimeRules', 'erp_board_request');

        $this->assertSame(AlimtalkRecipients::DEFAULT_TIME_RULES, AlimtalkRecipients::timeRules('erp_board_request'));
    }

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
