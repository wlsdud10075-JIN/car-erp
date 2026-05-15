<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 19-E — 이체 취소(void) 흐름 (회의록 v5 §13 안전 가드 4: append-only).
 * Service 단위 + ApprovalRequest 통합 + Volt 모달 E2E.
 */
class InterVehicleTransferVoidTest extends TestCase
{
    use RefreshDatabase;

    private function executeTransferScenario(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $source = Vehicle::create([
            'vehicle_number' => '99가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 100_000_000,
            'currency' => 'KRW',
            'deposit_down_payment' => 50_000_000,
        ]);
        $target = Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 80_000_000,
            'currency' => 'KRW',
        ]);

        $service = new InterVehicleTransferService;
        $this->actingAs($sales);
        $transfer = $service->request($source, $target, 25_000_000, $sales, reason: '이체 요청');
        $this->actingAs($manager);
        $service->execute($transfer, $manager);

        return compact('buyer', 'sales', 'manager', 'source', 'target', 'service', 'transfer');
    }

    public function test_void_request_creates_approval_request_pending(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $req = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '바이어 2번 차 무산 — 자금 원상복구');

        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID, $req->action_type);
        $this->assertEquals('pending', $req->status);
        $this->assertEquals($c['transfer']->id, $req->payload['transfer_id']);
    }

    public function test_void_request_blocked_when_transfer_not_executed(): void
    {
        $c = $this->executeTransferScenario();
        $c['transfer']->update(['status' => InterVehicleTransfer::STATUS_VOIDED]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('실행 완료된 이체만');
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '이미 voided');
    }

    public function test_void_request_blocked_when_duplicate_pending(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '첫 요청');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 대기중');
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '중복 요청 시도');
    }

    /**
     * 회의록 §13 — void 실행은 양 차량에 반대 부호 final_payment 추가 (append-only).
     */
    public function test_void_execution_creates_reverse_final_payments_and_marks_voided(): void
    {
        $c = $this->executeTransferScenario();
        $c['service']->void($c['transfer']->fresh(), $c['manager'], '바이어 2번 차 거래 무산');

        $transfer = $c['transfer']->fresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED, $transfer->status);
        $this->assertNotNull($transfer->voided_at);
        $this->assertNotEmpty($transfer->void_reason);

        // FinalPayment 4건 (실행 페어 2 + void 페어 2). 양 차량 잔금 합은 0
        $payments = FinalPayment::where('transfer_id', $transfer->id)->get();
        $this->assertCount(4, $payments);

        $sourceSum = (float) $payments->where('vehicle_id', $c['source']->id)->sum('amount');
        $targetSum = (float) $payments->where('vehicle_id', $c['target']->id)->sum('amount');
        $this->assertEquals(0.0, $sourceSum);
        $this->assertEquals(0.0, $targetSum);

        // 미수 캐시 원상복구 — 1번 차 5000만(50%) / 2번 차 8000만(0)
        $source = $c['source']->fresh();
        $target = $c['target']->fresh();
        $this->assertEquals(50_000_000, (int) $source->sale_unpaid_amount);
        $this->assertEquals(80_000_000, (int) $target->sale_unpaid_amount);
    }

    public function test_void_blocked_when_source_has_paid_settlement(): void
    {
        $c = $this->executeTransferScenario();
        Settlement::create([
            'vehicle_id' => $c['source']->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('paid 정산');
        $c['service']->void($c['transfer']->fresh(), $c['manager'], 'paid 정산 가드 테스트');
    }

    /**
     * 큐 19-E E2E — /erp/approvals 페이지에서 관리 승인 → service.void() 자동 호출.
     */
    public function test_approval_request_execute_triggers_void(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);
        $req = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '거래 무산 — 원상복구');

        $this->actingAs($c['manager']);
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $req->id)
            ->set('decisionNote', '바이어 무산 확인. 원상복구 승인.')
            ->call('decide')
            ->assertHasNoErrors();

        $transfer = $c['transfer']->fresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED, $transfer->status);
        $this->assertCount(4, FinalPayment::where('transfer_id', $transfer->id)->get());
    }

    public function test_void_modal_submit_creates_approval_request(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferVoidModal', $c['transfer']->id)
            ->assertSet('showTransferVoidModal', true)
            ->set('voidReason', '바이어 2번 차 무산 — 원상복구 요청')
            ->call('submitTransferVoidRequest')
            ->assertHasNoErrors()
            ->assertSet('showTransferVoidModal', false);

        $this->assertEquals(1, ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)->count());
    }

    /**
     * 큐 19-E 보강 (2026-05-15) — submit 직후 finalPayments 메타가 즉시 갱신되어
     * 페이지 새로고침 없이 amber 박스 "취소 요청 중" 시각화.
     */
    public function test_void_submit_immediately_updates_finalpayments_meta(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $component = Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferVoidModal', $c['transfer']->id)
            ->set('voidReason', '잔금 row 메타 즉시 갱신 테스트')
            ->call('submitTransferVoidRequest');

        // openEdit 재호출 없이 같은 인스턴스에서 finalPayments 확인
        $payments = $component->instance()->finalPayments;
        $transferRow = collect($payments)->first(fn ($r) => ! empty($r['transfer']));

        $this->assertNotNull($transferRow);
        $this->assertTrue($transferRow['transfer']['pending_void']);
        $this->assertFalse($transferRow['transfer']['can_void']);
    }

    /**
     * 큐 19-E 보강 (사용자 피드백 2026-05-15) — pending void가 있으면 잔금 row 메타에
     * pending_void=true + can_void=false 반영. UI에서 amber 박스 + "취소 요청 중" 표시.
     */
    public function test_finalpayments_meta_shows_pending_void_after_request(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        // void 요청 보냄
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '취소 요청 — 메타 반영 테스트');

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $payments = $component->instance()->finalPayments;

        $transferRow = collect($payments)->first(fn ($r) => ! empty($r['transfer']));
        $this->assertNotNull($transferRow);
        $this->assertTrue($transferRow['transfer']['pending_void']);
        $this->assertFalse($transferRow['transfer']['can_void']);
    }
}
