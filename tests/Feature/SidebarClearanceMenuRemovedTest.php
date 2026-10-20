<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🗑️ 사이드바 「수출통관」 메뉴 제거 (jin 2026-10-20 «없애는 게 낫겠다»).
 *
 * 별도 화면이 아니라 차량관리 `?action=clearance_candidates` 링크 + 배지였다. 필터가 진행 중 판매 차를 거의 다 담아
 * (신고서가 있으면 전부) 거르는 효과가 없었고, 14일 접속 ssancarerp 21회 · karabaerp 0회.
 * 대신 차량관리 「말소 필요 · 선적 필요 · 통관 필요」 토글. 옛 URL(즐겨찾기)은 scopeAction 이 남아 계속 열린다.
 */
class SidebarClearanceMenuRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_clearance_role_no_longer_sees_the_menu_but_the_old_url_still_opens(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $this->actingAs($user);

        $html = $this->get('/erp/vehicles')->assertOk()->getContent();
        $this->assertStringNotContainsString('action=clearance_candidates', $html, '사이드바에 수출통관 메뉴가 남아 있다');
        $this->assertStringNotContainsString('>'.__('nav.menu.clearance').'<', $html);

        // 옛 주소는 그대로 열린다(즐겨찾기 보호)
        $this->get('/erp/vehicles?action=clearance_candidates')->assertOk();
        $this->assertStringContainsString('export_declaration_document', Vehicle::query()->action('clearance_candidates')->toSql(), '옛 URL 의 필터가 그대로 살아 있어야 한다');
    }
}
