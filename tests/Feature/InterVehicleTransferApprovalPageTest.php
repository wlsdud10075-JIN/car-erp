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

    /**
     * 큐 19-D / 19-F — 관리 승인 = 의사결정만 통과.
     * 19-F 후엔 final_payment 페어 생성은 재무 확정(confirmByFinance) 시점으로 이연.
     * 본 테스트는 관리 승인 페이지 동작만 검증 (final_payment 0건, 미수 캐시 이체 전 그대로).
     */
    public function test_manager_approves_transfer_awaits_finance_confirmation(): void
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

        $this->actingAs($sales);
        $transfer = app(InterVehicleTransferService::class)->request(
            $source, $target, 25_000_000, $sales, reason: '바이어 신뢰 — 2번 차 계약금 이체'
        );

        $this->actingAs($manager);
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $transfer->approval_request_id)
            ->set('decisionNote', '신뢰 거래 확인. 승인.')
            ->call('decide')
            ->assertHasNoErrors();

        $req = ApprovalRequest::findOrFail($transfer->approval_request_id);
        $this->assertEquals('approved', $req->status);
        $this->assertEquals($manager->id, $req->approver_id);

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $transfer->status);
        $this->assertEquals($manager->id, $transfer->approver_id);
        $this->assertNull($transfer->confirmed_by_user_id);
        $this->assertNull($transfer->executed_at);

        // final_payment 미생성
        $this->assertEquals(0, FinalPayment::where('transfer_id', $transfer->id)->count());

        // 미수 캐시 — 이체 전 상태 그대로 (1번 차 5천만 / 2번 차 8천만)
        $source->refresh();
        $target->refresh();
        $this->assertEquals(50_000_000, (int) $source->sale_unpaid_amount);
        $this->assertEquals(80_000_000, (int) $target->sale_unpaid_amount);
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
