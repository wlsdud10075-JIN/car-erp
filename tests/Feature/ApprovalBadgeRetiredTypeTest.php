<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 승인 대기 카운트는 **목록과 같은 집합**이어야 한다 (jin 2026-08-01 제보).
 *
 * 🚨 실사고: 2026-07-29 보증금 선지급 기능을 삭제하면서 목록 쿼리에는
 *    `RETIRED_TYPES` 제외를 넣었는데 **사이드바 뱃지와 화면 헤더 카운트에는 안 넣었다.**
 *    ssancarerp 에 **눌러도 아무것도 없는 「2」** 가 영구히 떠 있었다.
 *    → `scopeActionable()` 단일출처. 이 테스트가 세 곳의 lockstep 을 강제한다.
 */
class ApprovalBadgeRetiredTypeTest extends TestCase
{
    use RefreshDatabase;

    private function retiredPending(User $requester): ApprovalRequest
    {
        return ApprovalRequest::create([
            'requester_id' => $requester->id,
            'action_type' => ApprovalRequest::RETIRED_TYPES[0],
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => '폐기 유형 잔재',
        ]);
    }

    public function test_actionable_scope_excludes_retired_types(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->retiredPending($user);

        $this->assertSame(1, ApprovalRequest::where('status', 'pending')->count(), '원본은 남아 있어야(이력)');
        $this->assertSame(0, ApprovalRequest::actionable()->where('status', 'pending')->count());
    }

    /** 화면 헤더 카운트 — 목록이 0건인데 헤더가 2 면 사용자가 찾아 헤맨다. */
    public function test_screen_pending_count_matches_empty_list(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->retiredPending($user);
        $this->actingAs($user);

        // 헤더 카운트는 amber 스팬으로 렌더된다 — 폐기분만 있으면 0 이어야 한다.
        $this->get(route('erp.approvals.index'))->assertOk()->assertSeeHtml('text-amber-600">0<');

        // 살아있는 요청이 생기면 1 로 늘어난다(카운트가 죽어 있는 게 아님을 확인).
        ApprovalRequest::create([
            'requester_id' => $user->id,
            'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => '살아있는 요청',
        ]);
        $this->get(route('erp.approvals.index'))->assertOk()->assertSeeHtml('text-amber-600">1<');
    }

    /** 🔒 사이드바 뱃지 — 폐기 유형만 있으면 뱃지가 안 떠야 한다. */
    public function test_sidebar_badge_hidden_when_only_retired_pending(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->retiredPending($user);

        $html = $this->actingAs($user)->get(route('erp.approvals.index'))->assertOk()->getContent();

        // 뱃지는 $pendingApprovals > 0 일 때만 렌더된다. 살아있는 요청을 하나 넣었을 때와 대조.
        $before = substr_count($html, 'badge');
        ApprovalRequest::create([
            'requester_id' => $user->id,
            'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => '살아있는 요청',
        ]);
        $after = substr_count($this->actingAs($user)->get(route('erp.approvals.index'))->getContent(), 'badge');

        $this->assertGreaterThan($before, $after, '살아있는 요청이 생기면 뱃지가 늘어야 한다(=폐기분은 안 세었다)');
    }

    /** 🔒 정적 가드 — 대기 건수를 세는 곳이 actionable 스코프를 빠뜨리지 않았는지. */
    public function test_no_unfiltered_pending_count_remains(): void
    {
        $files = [
            resource_path('views/components/layouts/app/sidebar.blade.php'),
            resource_path('views/livewire/erp/approvals/index.blade.php'),
        ];

        foreach ($files as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "/ApprovalRequest::where\(\s*'status'\s*,\s*'pending'\s*\)/",
                $src,
                basename($file).': 폐기 유형을 걸러내지 않는 pending 카운트가 있다 → ::actionable() 을 쓸 것'
            );
        }
    }
}
