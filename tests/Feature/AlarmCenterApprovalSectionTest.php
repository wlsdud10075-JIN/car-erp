<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📌 알림 벨 「승인 대기」 구역 (jin 2026-11-19 «승인큐나 결재가 필요한 것들은 오래 떠 있게»).
 *
 * 지키는 것: ①알람이 0건이어도 승인 대기가 있으면 벨이 뜬다 ②승인큐·사이드바 뱃지와 **같은 집합**(폐기 종류 제외)
 *            ③승인·거절돼야 사라진다([확인] 없음) ④승인권자만 본다 — 그 외는 자기가 올린 대기 건만
 *            ⑤본인이 올린 건은 「내가 올린 요청」 ⑥새 요청이 오면 토스트 1번.
 */
class AlarmCenterApprovalSectionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $perm = 'user'): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role, 'email_verified_at' => now()]);
    }

    private function lockRequest(?int $requesterId = null, array $payload = []): ApprovalRequest
    {
        $b = Buyer::create(['name' => 'SAMPLE MOTORS', 'is_active' => true]);

        return ApprovalRequest::create([
            'requester_id' => $requesterId, 'target_type' => Buyer::class, 'target_id' => $b->id,
            'action_type' => ApprovalRequest::TYPE_BOARD_PURCHASE_LOCK, 'status' => 'pending', 'reason' => '잔금 입금 예정',
            'payload' => array_merge(['requested_count' => 3, 'used' => [], 'buyer_name' => $b->name, 'salesman_name' => '김영업',
                'requested_by_name' => '김영업', 'requested_by_email' => 'sales@ex.com'], $payload),
        ]);
    }

    public function test_bell_shows_pending_approvals_even_with_no_alarms_and_they_stay_until_decided(): void
    {
        $req = $this->lockRequest();
        $this->actingAs($this->user('관리'));

        $bell = Volt::test('erp.alarm-center')
            ->assertSeeHtml('data-bell-approvals')
            ->assertSeeHtml('data-bell-approval-count')
            ->assertSee('SAMPLE MOTORS')
            ->assertSee(__('approval.lock.want', ['n' => 3]))   // 제목이 잘려도 대수는 둘째 줄에 보인다
            ->assertSee('김영업')
            ->assertSee(__('alarm.approval_title', ['n' => 1]))
            ->assertSee(__('alarm.approval_go'));

        // [확인]으로는 못 지운다 — 승인 구역에는 확인 버튼 자체가 없다
        $html = $bell->html();
        $section = substr($html, strpos($html, 'data-bell-approvals'));
        $this->assertStringNotContainsString('wire:click="confirm(', $section);

        // 결정되면 사라진다(다른 알람이 없으니 벨도 사라진다)
        $req->update(['status' => 'rejected', 'decided_at' => now()]);
        Volt::test('erp.alarm-center')->assertDontSeeHtml('data-bell-approvals');
    }

    public function test_bell_counts_the_same_set_as_the_queue_badge(): void
    {
        $this->lockRequest();
        // 폐기된 종류의 잔존 pending — 승인큐·뱃지가 안 세는 것은 벨도 안 센다
        ApprovalRequest::create(['requester_id' => $this->user('재무')->id, 'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_DEPOSIT_APPLY, 'status' => 'pending']);
        $this->actingAs($this->user('관리'));

        $queue = ApprovalRequest::actionable()->where('status', 'pending')->count();
        $this->assertSame(1, $queue);
        Volt::test('erp.alarm-center')->assertSee(__('alarm.bell_approval', ['n' => $queue]))->assertSee(__('alarm.approval_title', ['n' => $queue]));
    }

    public function test_only_approvers_see_the_section_others_see_their_own_pending_requests(): void
    {
        $finance = $this->user('재무');
        $this->lockRequest();
        ApprovalRequest::create(['requester_id' => $finance->id, 'action_type' => ApprovalRequest::TYPE_SENSITIVE_ACTION, 'status' => 'pending', 'payload' => ['vehicle_number' => '00다0003']]);

        $this->actingAs($finance);
        Volt::test('erp.alarm-center')
            ->assertDontSeeHtml('data-bell-approvals')
            ->assertSeeHtml('data-bell-my-requests')
            ->assertSee('00다0003')
            ->assertDontSee('SAMPLE MOTORS');

        // 남의 요청은 안 보인다
        $this->actingAs($this->user('수출통관'));
        Volt::test('erp.alarm-center')->assertDontSeeHtml('data-bell-approvals')->assertDontSeeHtml('data-bell-my-requests');
    }

    public function test_an_approvers_own_request_is_marked_as_mine(): void
    {
        $mgr = $this->user('관리');
        $this->lockRequest(null, ['requested_by_email' => $mgr->email, 'requested_by_name' => $mgr->name]);   // board 에서 대리로 누른 관리
        $this->actingAs($mgr);

        Volt::test('erp.alarm-center')->assertSee(__('alarm.approval_mine'))->assertSee(__('alarm.approval_mine_hint'));

        $this->actingAs($this->user('관리'));
        Volt::test('erp.alarm-center')->assertDontSee(__('alarm.approval_mine'))->assertSee(__('alarm.approval_go'));
    }

    public function test_a_new_request_toasts_once(): void
    {
        $this->actingAs($this->user('관리'));
        $bell = Volt::test('erp.alarm-center');

        $bell->call('poll')->assertNotDispatched('notify');
        $this->lockRequest();
        $bell->call('poll')->assertDispatched('notify', message: __('alarm.toast_approval', ['n' => 1]));
        $bell->call('poll')->assertNotDispatched('notify');
    }

    public function test_bell_list_height_is_eighty_percent(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/alarm-center.blade.php'));
        $this->assertStringContainsString('max-h-[80vh] overflow-y-auto', $src);
        $this->assertStringNotContainsString('max-h-[60vh]', $src);
    }
}
