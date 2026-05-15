<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 19-D — /erp/approvals 페이지 통합 (회의록 v5 §13 T=2).
 * 영업이 19-C 모달로 요청 → 관리가 본 페이지에서 승인 → 트랜잭션 자동 실행 E2E.
 */
class InterVehicleTransferApprovalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_approves_transfer_and_final_payments_pair_created(): void
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

        // T=1 — 영업 요청
        $this->actingAs($sales);
        $transfer = app(InterVehicleTransferService::class)->request(
            $source, $target, 25_000_000, $sales, reason: '바이어 신뢰 — 2번 차 계약금 이체'
        );

        // T=2 — 관리가 /erp/approvals 페이지에서 승인
        $this->actingAs($manager);
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $transfer->approval_request_id)
            ->set('decisionNote', '신뢰 거래 확인. 승인.')
            ->call('decide')
            ->assertHasNoErrors();

        // ApprovalRequest 승인 상태
        $req = ApprovalRequest::findOrFail($transfer->approval_request_id);
        $this->assertEquals('approved', $req->status);
        $this->assertEquals($manager->id, $req->approver_id);

        // Transfer executed 상태
        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        $this->assertEquals($manager->id, $transfer->approver_id);

        // FinalPayment 페어 생성
        $payments = FinalPayment::where('transfer_id', $transfer->id)->orderBy('amount')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(-25_000_000, $payments[0]->amount);
        $this->assertEquals(25_000_000, $payments[1]->amount);

        // 양 차량 미수 캐시 갱신 (회의록 §13 T=2 결과)
        $source->refresh();
        $target->refresh();
        $this->assertEquals(75_000_000, (int) $source->sale_unpaid_amount);  // 1억 - 2500만 = 7500만
        $this->assertEquals(55_000_000, (int) $target->sale_unpaid_amount);  // 8000만 - 2500만 = 5500만
    }

    public function test_manager_rejects_transfer_no_execution(): void
    {
        $buyer = Buyer::create(['name' => 'OSAKA MOTORS', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $source = Vehicle::create([
            'vehicle_number' => '99가0010',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 50_000_000,
            'currency' => 'KRW',
            'deposit_down_payment' => 25_000_000,
        ]);
        $target = Vehicle::create([
            'vehicle_number' => '99가0011',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 50_000_000,
            'currency' => 'KRW',
        ]);

        $this->actingAs($sales);
        $transfer = app(InterVehicleTransferService::class)->request(
            $source, $target, 10_000_000, $sales, reason: '이체 요청'
        );

        $this->actingAs($manager);
        Volt::test('erp.approvals.index')
            ->call('openRejectModal', $transfer->approval_request_id)
            ->set('decisionNote', '바이어 신용도 미확인 — 거부')
            ->call('decide')
            ->assertHasNoErrors();

        $req = ApprovalRequest::findOrFail($transfer->approval_request_id);
        $this->assertEquals('rejected', $req->status);

        // Transfer는 그대로 pending (executed 안 됨, voided도 아님)
        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $transfer->status);
        $this->assertEquals(0, FinalPayment::where('transfer_id', $transfer->id)->count());
    }
}
