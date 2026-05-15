<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 큐 19-B — InterVehicleTransferService (회의록 v5 §13).
 * 한도 계산·요청·실행·안전 가드 4종 검증.
 */
class InterVehicleTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /**
     * 회의록 §13 T=0 시나리오 — 1번 차 1억 / 5천만 입금 (ratio 50%).
     */
    private function makeContext(int $sourceReceived = 50_000_000): array
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
            'deposit_down_payment' => $sourceReceived,
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

    public function test_available_returns_received_times_half(): void
    {
        $c = $this->makeContext(50_000_000);
        $this->assertEquals(25_000_000.0, $this->service->available($c['source']));
    }

    public function test_available_zero_when_no_payment_received(): void
    {
        $c = $this->makeContext(0);
        $this->assertEquals(0.0, $this->service->available($c['source']));
    }

    public function test_request_creates_pending_transfer_and_approval(): void
    {
        $c = $this->makeContext();

        $transfer = $this->service->request(
            $c['source'], $c['target'],
            25_000_000,
            $c['sales'],
            reason: '바이어 2번 차 계약금 이체 요청',
        );

        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $transfer->status);
        $this->assertEquals(25_000_000, $transfer->amount);
        $this->assertEquals('KRW', $transfer->currency);
        $this->assertEquals($c['buyer']->id, $transfer->buyer_id);
        $this->assertEquals($c['sales']->id, $transfer->requester_id);
        $this->assertNotNull($transfer->approval_request_id);

        $req = ApprovalRequest::find($transfer->approval_request_id);
        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER, $req->action_type);
        $this->assertEquals('pending', $req->status);
        $this->assertEquals($c['source']->id, $req->payload['source_vehicle_id']);
        $this->assertEquals($c['target']->id, $req->payload['target_vehicle_id']);
    }

    public function test_request_blocks_same_vehicle(): void
    {
        $c = $this->makeContext();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('동일할 수 없습니다');
        $this->service->request($c['source'], $c['source'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_different_buyers(): void
    {
        $c = $this->makeContext();
        $other = Buyer::create(['name' => 'OSAKA MOTORS', 'is_active' => true]);
        $c['target']->update(['buyer_id' => $other->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('바이어가 동일');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_currency_mismatch(): void
    {
        $c = $this->makeContext();
        $c['target']->update(['currency' => 'USD']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('통화가 일치');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_when_source_received_below_50pct(): void
    {
        // 1억 중 4천만 받음 → ratio 60% → 50% 미달
        $c = $this->makeContext(40_000_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('50% 이상 입금');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_amount_over_limit(): void
    {
        $c = $this->makeContext(50_000_000);  // 한도 = 2500만

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('한도를 초과');
        $this->service->request($c['source'], $c['target'], 30_000_000, $c['sales']);
    }

    public function test_request_blocks_non_positive_amount(): void
    {
        $c = $this->makeContext();

        $this->expectException(DomainException::class);
        $this->service->request($c['source'], $c['target'], 0, $c['sales']);
    }

    /**
     * 회의록 §13 T=2 — 관리 승인 → 1번 차 -2500만 + 2번 차 +2500만 트랜잭션.
     */
    public function test_execute_creates_paired_final_payments_and_updates_caches(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);

        $this->service->execute($transfer, $c['manager']);

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        $this->assertNotNull($transfer->executed_at);
        $this->assertEquals($c['manager']->id, $transfer->approver_id);

        // FinalPayment 페어 검증
        $payments = FinalPayment::where('transfer_id', $transfer->id)->orderBy('amount')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(-25_000_000, $payments[0]->amount);
        $this->assertEquals($c['source']->id, $payments[0]->vehicle_id);
        $this->assertEquals(25_000_000, $payments[1]->amount);
        $this->assertEquals($c['target']->id, $payments[1]->vehicle_id);

        // 양 차량 미수 캐시 갱신
        $source = $c['source']->fresh();
        $target = $c['target']->fresh();
        // 1번 차: 1억 - (5000만 - 2500만) = 7500만 미수
        $this->assertEquals(75_000_000, (int) $source->sale_unpaid_amount);
        // 2번 차: 8000만 - 2500만 = 5500만 미수
        $this->assertEquals(55_000_000, (int) $target->sale_unpaid_amount);
    }

    public function test_execute_blocks_already_executed_transfer(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->execute($transfer, $c['manager']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 처리된');
        $this->service->execute($transfer, $c['manager']);
    }

    public function test_execute_re_validates_guards_at_execution_time(): void
    {
        // 요청 시점에는 통과했지만 승인 사이에 source가 환불받아 미수율이 50%↑로 올라간 경우
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);

        // 환불 시뮬레이션 — final_payment 음수로 입금 차감 (받은 금액 5000만 → 2000만, ratio 80%)
        FinalPayment::create([
            'vehicle_id' => $c['source']->id,
            'amount' => -30_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '환불 (테스트)',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('50% 이상 입금');
        $this->service->execute($transfer, $c['manager']);
    }
}
