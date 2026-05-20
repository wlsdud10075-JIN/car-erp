<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 19-C — vehicles/index 자금 이체 요청 모달 통합 테스트.
 * Service 단위 가드는 InterVehicleTransferServiceTest에서 검증.
 * 본 테스트는 Volt 컴포넌트 ↔ Service 연결만 확인.
 */
class InterVehicleTransferModalTest extends TestCase
{
    use RefreshDatabase;

    private function setup50PctReceivedSource(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);

        $source = Vehicle::create([
            'vehicle_number' => '99가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 100_000_000,
            'currency' => 'KRW',
        ]);
        // 큐 22-A-3 — 4컬럼 DROP. 계약금은 confirmed FP 로 표현.
        $source->finalPayments()->create(['amount' => 50_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 80_000_000,
            'currency' => 'KRW',
        ]);

        return compact('buyer', 'sales', 'source', 'target');
    }

    public function test_transfer_context_eligible_when_source_has_50pct_received(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->assertSet('editingId', $c['source']->id);

        // computed transferContext 호출 — eligible + candidates 1대
        $ctx = (new \ReflectionClass(Vehicle::class)); // ensure autoload
        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $ctxData = $component->instance()->transferContext;

        $this->assertTrue($ctxData['eligible']);
        $this->assertEquals(25_000_000, $ctxData['limit']);
        $this->assertCount(1, $ctxData['candidates']);
    }

    public function test_submit_transfer_request_creates_transfer_and_approval(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->assertSet('showTransferRequestModal', true)
            ->set('transferTargetVehicleId', (string) $c['target']->id)
            ->set('transferAmountStr', '25000000')
            ->set('transferReason', '바이어 2번 차 계약금 이체 요청')
            ->call('submitTransferRequest')
            ->assertHasNoErrors()
            ->assertSet('showTransferRequestModal', false);

        $this->assertEquals(1, InterVehicleTransfer::count());
        $transfer = InterVehicleTransfer::first();
        $this->assertEquals($c['source']->id, $transfer->source_vehicle_id);
        $this->assertEquals($c['target']->id, $transfer->target_vehicle_id);
        $this->assertEquals(25_000_000, (float) $transfer->amount);
        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $transfer->status);

        $req = ApprovalRequest::find($transfer->approval_request_id);
        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER, $req->action_type);
        $this->assertEquals('pending', $req->status);
    }

    public function test_submit_blocks_amount_over_limit_with_inline_error(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->set('transferTargetVehicleId', (string) $c['target']->id)
            ->set('transferAmountStr', '30000000')  // 한도 2500만 초과
            ->set('transferReason', '한도 초과 케이스 — 한도 초과 사유 입력')
            ->call('submitTransferRequest')
            ->assertHasErrors('transferAmountStr');

        $this->assertEquals(0, InterVehicleTransfer::count());
    }

    public function test_modal_does_not_open_when_source_not_eligible(): void
    {
        // 입금 부족 시나리오 — 4천만(40%)만 받음
        $c = $this->setup50PctReceivedSource();
        // 큐 22-A-3 — vehicles 4컬럼 DROP. confirmed FP 를 5천만 → 4천만으로 교체.
        FinalPayment::$allowConfirmedMutation = true;
        try {
            $c['source']->finalPayments()->delete();
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }
        $c['source']->finalPayments()->create(['amount' => 40_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $c['source']->refresh();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->assertSet('showTransferRequestModal', false);  // 열리지 않음
    }

    /**
     * 큐 19-C 보강 (2026-05-15) — 같은 source에 pending 요청 있으면 모달 안 열림 + context.pending 노출.
     */
    public function test_pending_request_blocks_new_modal_and_shows_in_context(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        // 첫 요청 — 성공
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->set('transferTargetVehicleId', (string) $c['target']->id)
            ->set('transferAmountStr', '20000000')
            ->set('transferReason', '바이어 2번 차 계약금 이체 요청')
            ->call('submitTransferRequest')
            ->assertHasNoErrors();

        $this->assertEquals(1, InterVehicleTransfer::count());

        // 두 번째 모달 열기 시도 — pending 있어서 차단
        $component = Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->assertSet('showTransferRequestModal', false);

        // transferContext.pending에 정보 노출
        $ctx = $component->instance()->transferContext;
        $this->assertNotNull($ctx['pending']);
        $this->assertEquals(20_000_000, $ctx['pending']['amount']);
        $this->assertEquals('KRW', $ctx['pending']['currency']);
    }

    public function test_last_decided_shown_after_rejection(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->set('transferTargetVehicleId', (string) $c['target']->id)
            ->set('transferAmountStr', '15000000')
            ->set('transferReason', '거부 시나리오 테스트')
            ->call('submitTransferRequest');

        $req = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)->first();
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $req->update([
            'status' => 'rejected',
            'approver_id' => $manager->id,
            'decision_note' => '바이어 신용 미확인 — 거부',
            'decided_at' => now(),
        ]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $ctx = $component->instance()->transferContext;

        $this->assertNull($ctx['pending']);
        $this->assertNotNull($ctx['lastDecided']);
        $this->assertEquals('rejected', $ctx['lastDecided']['status']);
        $this->assertEquals('바이어 신용 미확인 — 거부', $ctx['lastDecided']['decision_note']);
        $this->assertEquals($manager->name, $ctx['lastDecided']['approver_name']);
        $this->assertTrue($ctx['eligible']);
    }

    /**
     * 큐 19-C 보강 (2026-05-15 #2) — 승인된 경우에도 lastDecided에 status=approved 정보 노출.
     * 사용자 피드백: 거부 박스가 승인 후에도 남으면 헷갈림 → 승인되면 emerald 박스로 교체.
     */
    public function test_last_decided_shown_after_approval(): void
    {
        $c = $this->setup50PctReceivedSource();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferRequestModal')
            ->set('transferTargetVehicleId', (string) $c['target']->id)
            ->set('transferAmountStr', '20000000')
            ->set('transferReason', '승인 시나리오 테스트')
            ->call('submitTransferRequest');

        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $this->actingAs($manager);

        // 관리자 승인 (ApprovalRequest::execute()까지 트리거)
        $req = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)->first();
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $req->id)
            ->set('decisionNote', '신뢰 거래 — 승인')
            ->call('decide')
            ->assertHasNoErrors();

        // 영업이 다시 열어보면 lastDecided.status === approved
        $this->actingAs($c['sales']);
        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $ctx = $component->instance()->transferContext;

        $this->assertNull($ctx['pending']);
        $this->assertNotNull($ctx['lastDecided']);
        $this->assertEquals('approved', $ctx['lastDecided']['status']);
        $this->assertEquals('신뢰 거래 — 승인', $ctx['lastDecided']['decision_note']);
        $this->assertEquals($manager->name, $ctx['lastDecided']['approver_name']);
    }
}
