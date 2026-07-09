<?php

namespace Tests\Feature;

use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ShippingRequestsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function salesUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
    }

    private function managerUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function batch(string $batchId, array $vehicleNumbers, string $status = 'requested'): void
    {
        foreach ($vehicleNumbers as $vn) {
            $v = Vehicle::create(['vehicle_number' => $vn, 'sales_channel' => 'export']);
            ShippingRequest::create([
                'batch_id' => $batchId,
                'vehicle_id' => $v->id,
                'shipping_method' => 'RORO',
                'requested_by_email' => 's@a.com',
                'status' => $status,
                'requested_at' => now(),
            ]);
        }
    }

    public function test_groups_requests_by_batch(): void
    {
        $this->batch('batch-A', ['11가1111', '22나2222', '33다3333']);
        $this->batch('batch-B', ['44라4444']);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->assertSee('11가1111')
            ->assertSee('33다3333')
            ->assertSee('44라4444')
            ->assertViewHas('batches', fn ($b) => $b->count() === 2);   // 3대·1대 = 2배치
    }

    public function test_done_transition_updates_whole_batch_and_resolves_alarm(): void
    {
        $this->batch('batch-A', ['11가1111', '22나2222']);
        // board store 가 만들 듯 연동 알람 부여
        foreach (ShippingRequest::all() as $r) {
            TaskAlarm::create(['type' => 'shipping_requested', 'vehicle_id' => $r->vehicle_id, 'target_role' => '수출통관', 'due_date' => now()]);
        }

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')->call('changeStatus', 'batch-A', 'done');

        // 배치 전체 done + processed_at
        $this->assertSame(2, ShippingRequest::where('batch_id', 'batch-A')->where('status', 'done')->whereNotNull('processed_at')->count());
        // 연동 알람 전부 resolve (벨/알림 카운트 정합)
        $this->assertSame(0, TaskAlarm::where('type', 'shipping_requested')->whereNull('resolved_at')->count());
    }

    public function test_cancel_voids_batch_resolves_alarm_and_hides(): void
    {
        $this->batch('batch-A', ['11가1111', '22나2222']);
        foreach (ShippingRequest::all() as $r) {
            TaskAlarm::create(['type' => 'shipping_requested', 'vehicle_id' => $r->vehicle_id, 'target_role' => '수출통관', 'due_date' => now()]);
        }

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->call('cancel', 'batch-A')
            ->assertViewHas('batches', fn ($b) => $b->isEmpty());   // 취소건은 목록서 사라짐

        $this->assertSame(2, ShippingRequest::where('batch_id', 'batch-A')->where('status', 'cancelled')->count());
        $this->assertSame(0, TaskAlarm::where('type', 'shipping_requested')->whereNull('resolved_at')->count());
    }

    public function test_status_filter(): void
    {
        $this->batch('batch-A', ['11가1111'], 'requested');
        $this->batch('batch-B', ['22나2222'], 'done');

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->call('setStatus', 'done')
            ->assertSee('22나2222')
            ->assertDontSee('11가1111');
    }

    public function test_non_clearance_user_forbidden(): void
    {
        $this->batch('batch-A', ['11가1111']);
        $this->actingAs($this->salesUser());

        // mount + transition 동일 게이트(canAccessClearance) — 영업은 진입 자체 403
        Volt::test('erp.shipping-requests.index')->assertStatus(403);
    }

    // ── v2 B/L 발급 bulk-apply · 변경요청 ──────────────────────

    public function test_bl_issue_bulk_applies_to_members_when_fully_paid(): void
    {
        // sale_price 0 = 미수 0 → fully_paid (발급 가드 통과). 멤버 2대.
        $this->batch('batch-A', ['11가1111', '22나2222']);
        ShippingRequest::where('batch_id', 'batch-A')->update(['bl_type' => 'surrender', 'bl_status' => 'requested']);

        $this->actingAs($this->managerUser());

        Volt::test('erp.shipping-requests.index')
            ->call('openIssue', 'batch-A')
            ->set('blForm.bl_number', 'BL123')
            ->call('applyBlIssue');

        // 공유 B/L 필드가 멤버 차량 전체에 일괄 기입
        $this->assertSame(2, Vehicle::where('bl_type', 'surrender')->where('bl_number', 'BL123')->count());
        $this->assertSame(2, ShippingRequest::where('batch_id', 'batch-A')->where('bl_status', 'issued')->count());
    }

    public function test_bl_issue_blocked_when_unpaid(): void
    {
        $v = Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export', 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200]);
        ShippingRequest::create(['batch_id' => 'batch-U', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'bl_type' => 'original', 'bl_status' => 'requested', 'requested_by_email' => 's@a.com', 'status' => 'in_progress', 'requested_at' => now()]);

        $this->actingAs($this->managerUser());

        Volt::test('erp.shipping-requests.index')
            ->call('openIssue', 'batch-U')
            ->call('applyBlIssue');

        // 미완납 → 발급 차단(bl_status 그대로, 차량 bl_type 미기입)
        $this->assertSame('requested', ShippingRequest::where('batch_id', 'batch-U')->value('bl_status'));
        $this->assertNull(Vehicle::find($v->id)->bl_type);
    }

    public function test_declaration_number_bulk_applies_to_members(): void
    {
        $this->batch('batch-D', ['11가1111', '22나2222']);

        // 통관 권한(canAccessClearance)이면 발급 권한 없이도 신고번호 일괄 기입 가능
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->call('openDeclNumber', 'batch-D')
            ->set('declNumber', '12345-67-890123X')
            ->call('applyDeclNumber');

        $this->assertSame(2, Vehicle::where('export_declaration_number', '12345-67-890123X')->count());
    }

    public function test_declaration_number_prefills_existing_value(): void
    {
        $this->batch('batch-P', ['33다3333']);
        Vehicle::where('vehicle_number', '33다3333')->update(['export_declaration_number' => 'EXIST-99']);

        $this->actingAs($this->clearanceUser());

        Volt::test('erp.shipping-requests.index')
            ->call('openDeclNumber', 'batch-P')
            ->assertSet('declNumber', 'EXIST-99');
    }

    public function test_declaration_number_sales_user_forbidden(): void
    {
        $this->batch('batch-S', ['44라4444']);

        // 영업 = 화면 진입부터 차단(canAccessClearance false) → mount 403
        $this->actingAs($this->salesUser());
        Volt::test('erp.shipping-requests.index')->assertStatus(403);
    }

    public function test_clearance_non_approver_cannot_issue_bl(): void
    {
        $this->batch('batch-A', ['11가1111']);
        ShippingRequest::where('batch_id', 'batch-A')->update(['bl_status' => 'requested']);

        // 수출통관 = 화면 진입 O(canAccessClearance) / 발급 X(canApprove false) → 403
        $this->actingAs($this->clearanceUser());
        Volt::test('erp.shipping-requests.index')->call('openIssue', 'batch-A')->assertStatus(403);
    }

    public function test_accept_change_releases_bundle_and_resolves_alarm(): void
    {
        $v = Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'export']);
        $sr = ShippingRequest::create(['batch_id' => 'batch-C', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'requested_by_email' => 's@a.com', 'status' => 'in_progress', 'requested_at' => now(), 'change_requested_at' => now(), 'change_request_meta' => ['note' => '바꿔주세요', 'requested_by' => 's@a.com']]);
        TaskAlarm::create(['type' => 'shipping_change_requested', 'vehicle_id' => $v->id, 'target_role' => '관리', 'due_date' => now()]);

        $this->actingAs($this->managerUser());
        Volt::test('erp.shipping-requests.index')->call('acceptChange', $sr->id);

        $fresh = ShippingRequest::find($sr->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->change_requested_at);
        $this->assertSame(0, TaskAlarm::where('type', 'shipping_change_requested')->whereNull('resolved_at')->count());
    }

    public function test_reject_change_clears_flag_only(): void
    {
        $v = Vehicle::create(['vehicle_number' => '55마5555', 'sales_channel' => 'export']);
        $sr = ShippingRequest::create(['batch_id' => 'batch-R', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'requested_by_email' => 's@a.com', 'status' => 'in_progress', 'requested_at' => now(), 'change_requested_at' => now(), 'change_request_meta' => ['note' => 'x']]);

        $this->actingAs($this->managerUser());
        Volt::test('erp.shipping-requests.index')->call('rejectChange', $sr->id);

        $fresh = ShippingRequest::find($sr->id);
        $this->assertSame('in_progress', $fresh->status);   // 묶음 유지
        $this->assertNull($fresh->change_requested_at);     // 플래그만 클리어
    }
}
