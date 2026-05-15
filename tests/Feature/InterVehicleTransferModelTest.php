<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 큐 19-A — 차량 간 자금 이체 모델·관계 단위 테스트.
 * 실제 한도 검증·트랜잭션 실행 로직은 큐 19-B (Service) 책임이라 별도.
 */
class InterVehicleTransferModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeContext(): array
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
        ]);
        $target = Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 80_000_000,
            'currency' => 'KRW',
        ]);

        return compact('buyer', 'sales', 'manager', 'source', 'target');
    }

    public function test_transfer_can_be_created_with_pending_status(): void
    {
        $c = $this->makeContext();

        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 25_000_000,
            'currency' => 'KRW',
            'requester_id' => $c['sales']->id,
            'notes' => '1번 차 50% 받음, 2번 차 계약금으로 이체 요청',
        ])->fresh();

        $this->assertEquals('pending', $transfer->status);
        $this->assertNull($transfer->executed_at);
        $this->assertNull($transfer->voided_at);
        $this->assertEquals('25000000.00', $transfer->amount);
    }

    public function test_relations_resolve_correctly(): void
    {
        $c = $this->makeContext();

        $req = ApprovalRequest::create([
            'requester_id' => $c['sales']->id,
            'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
            'target_type' => Vehicle::class,
            'target_id' => $c['source']->id,
            'status' => 'pending',
        ]);

        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 25_000_000,
            'currency' => 'KRW',
            'approval_request_id' => $req->id,
            'requester_id' => $c['sales']->id,
            'approver_id' => $c['manager']->id,
        ])->fresh();

        $this->assertEquals($c['source']->id, $transfer->sourceVehicle->id);
        $this->assertEquals($c['target']->id, $transfer->targetVehicle->id);
        $this->assertEquals($c['buyer']->id, $transfer->buyer->id);
        $this->assertEquals($req->id, $transfer->approvalRequest->id);
        $this->assertEquals($c['sales']->id, $transfer->requester->id);
        $this->assertEquals($c['manager']->id, $transfer->approver->id);
    }

    public function test_final_payment_can_link_to_transfer_both_directions(): void
    {
        $c = $this->makeContext();

        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 25_000_000,
            'currency' => 'KRW',
            'requester_id' => $c['sales']->id,
        ]);

        // 음수 (source) + 양수 (target) 페어
        $negative = FinalPayment::create([
            'vehicle_id' => $c['source']->id,
            'transfer_id' => $transfer->id,
            'amount' => -25_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '→ 2번 차 이체',
        ]);
        $positive = FinalPayment::create([
            'vehicle_id' => $c['target']->id,
            'transfer_id' => $transfer->id,
            'amount' => 25_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '1번 차에서 이체',
        ]);

        $transfer->refresh();
        $this->assertCount(2, $transfer->finalPayments);
        $this->assertEquals($transfer->id, $negative->transfer->id);
        $this->assertEquals($transfer->id, $positive->transfer->id);
    }

    public function test_status_label_and_badge_mapping(): void
    {
        $c = $this->makeContext();

        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 1_000_000,
            'currency' => 'KRW',
            'status' => InterVehicleTransfer::STATUS_PENDING,
            'requester_id' => $c['sales']->id,
        ]);

        // pending
        $this->assertEquals('대기', $transfer->status_label);
        $this->assertEquals('badge-amber', $transfer->status_badge);

        // executed
        $transfer->status = InterVehicleTransfer::STATUS_EXECUTED;
        $this->assertEquals('실행 완료', $transfer->status_label);
        $this->assertEquals('badge-green', $transfer->status_badge);

        // voided
        $transfer->status = InterVehicleTransfer::STATUS_VOIDED;
        $this->assertEquals('취소', $transfer->status_label);
        $this->assertEquals('badge-gray', $transfer->status_badge);
    }

    public function test_vehicle_transfer_relations_both_directions(): void
    {
        $c = $this->makeContext();

        InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 5_000_000,
            'currency' => 'KRW',
            'requester_id' => $c['sales']->id,
        ]);

        $this->assertCount(1, $c['source']->transfersAsSource);
        $this->assertCount(0, $c['source']->transfersAsTarget);
        $this->assertCount(0, $c['target']->transfersAsSource);
        $this->assertCount(1, $c['target']->transfersAsTarget);
    }

    public function test_approval_request_type_constant_registered(): void
    {
        $this->assertArrayHasKey(
            ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
            ApprovalRequest::TYPES
        );
        $this->assertEquals('차량 간 자금 이체', ApprovalRequest::TYPES[ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER]);
    }

    /**
     * 큐 19-C 보강 — transfer로 생성된 final_payment는 append-only.
     * 직접 수정·삭제 차단 (advisor 지적 #3 — append-only 보호).
     */
    public function test_transfer_linked_final_payment_cannot_be_deleted_directly(): void
    {
        $c = $this->makeContext();
        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 1_000_000,
            'currency' => 'KRW',
            'requester_id' => $c['sales']->id,
        ]);
        $fp = FinalPayment::create([
            'vehicle_id' => $c['source']->id,
            'transfer_id' => $transfer->id,
            'amount' => -1_000_000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('자금 이체로 생성된 잔금은 삭제할 수 없습니다');
        $fp->delete();
    }

    public function test_transfer_linked_final_payment_cannot_be_updated_directly(): void
    {
        $c = $this->makeContext();
        $transfer = InterVehicleTransfer::create([
            'source_vehicle_id' => $c['source']->id,
            'target_vehicle_id' => $c['target']->id,
            'buyer_id' => $c['buyer']->id,
            'amount' => 1_000_000,
            'currency' => 'KRW',
            'requester_id' => $c['sales']->id,
        ]);
        $fp = FinalPayment::create([
            'vehicle_id' => $c['source']->id,
            'transfer_id' => $transfer->id,
            'amount' => -1_000_000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('자금 이체로 생성된 잔금은 수정할 수 없습니다');
        $fp->update(['amount' => -500_000]);
    }
}
