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
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 보증금 적용 (jin 2026-07-20) — [관리]/업무관리자 기안 → 최고관리자(admin) 승인 = 즉시 적용.
 *   기존 차량간 이체(요청→관리→재무)와 분리(kind=deposit_apply). 승인 즉시 FinalPayment 페어 생성(재무 단계 없음).
 */
class InterVehicleDepositApplyTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /** source(끌어올 차, 받은돈 있음) + target(신규 차) + 기안자([관리]) + 승인자(최고관리자). */
    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $source = Vehicle::create([
            'vehicle_number' => '99가0001', 'sales_channel' => 'export',
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01',
            'sale_price' => 100_000_000, 'currency' => 'KRW',
        ]);
        $source->finalPayments()->create(['amount' => 50_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => '99가0002', 'sales_channel' => 'export',
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01',
            'sale_price' => 80_000_000, 'currency' => 'KRW',
        ]);

        return compact('buyer', 'drafter', 'admin', 'source', 'target');
    }

    public function test_apply_deposit_creates_pending_without_payment(): void
    {
        ['drafter' => $drafter, 'source' => $source, 'target' => $target] = $this->ctx();

        $transfer = $this->service->applyDeposit($source, $target, 20_000_000, $drafter, 'deposit_down', '보증금 계약금 적용');

        $this->assertSame(InterVehicleTransfer::KIND_DEPOSIT_APPLY, $transfer->kind);
        $this->assertSame(InterVehicleTransfer::STATUS_PENDING, $transfer->status);
        $this->assertSame('deposit_down', $transfer->target_payment_type);
        $this->assertSame(ApprovalRequest::TYPE_INTER_VEHICLE_DEPOSIT_APPLY, $transfer->approvalRequest->action_type);
        // 아직 돈 안 움직임 (승인 전)
        $this->assertSame(0, FinalPayment::where('transfer_id', $transfer->id)->count());
    }

    public function test_admin_approval_applies_immediately_as_deposit(): void
    {
        ['drafter' => $drafter, 'admin' => $admin, 'source' => $source, 'target' => $target] = $this->ctx();
        $transfer = $this->service->applyDeposit($source, $target, 20_000_000, $drafter, 'deposit_down');

        $this->service->executeDepositApply($transfer, $admin);

        $transfer->refresh();
        $this->assertSame(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        // 페어 2건 — source −2천만(잔금) / target +2천만(계약금)
        $src = FinalPayment::where('transfer_id', $transfer->id)->where('vehicle_id', $source->id)->first();
        $tgt = FinalPayment::where('transfer_id', $transfer->id)->where('vehicle_id', $target->id)->first();
        $this->assertEquals(-20_000_000, (int) $src->amount);
        $this->assertSame('balance', $src->type);
        $this->assertEquals(20_000_000, (int) $tgt->amount);
        $this->assertSame('deposit_down', $tgt->type, '타겟 입금 = 선택한 계약금 유형');
        $this->assertNotNull($tgt->confirmed_at, '승인 즉시 확정');
    }

    public function test_balance_type_lands_as_balance(): void
    {
        ['drafter' => $drafter, 'admin' => $admin, 'source' => $source, 'target' => $target] = $this->ctx();
        $transfer = $this->service->applyDeposit($source, $target, 10_000_000, $drafter, 'balance');
        $this->service->executeDepositApply($transfer, $admin);

        $tgt = FinalPayment::where('transfer_id', $transfer->id)->where('vehicle_id', $target->id)->first();
        $this->assertSame('balance', $tgt->type);
    }

    public function test_only_admin_can_approve(): void
    {
        ['drafter' => $drafter, 'source' => $source, 'target' => $target] = $this->ctx();
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $transfer = $this->service->applyDeposit($source, $target, 20_000_000, $drafter, 'deposit_down');

        $this->expectException(DomainException::class);
        $this->service->executeDepositApply($transfer, $manager);   // 업무관리자는 승인 불가
    }

    public function test_sod_drafter_cannot_self_approve(): void
    {
        ['source' => $source, 'target' => $target] = $this->ctx();
        // 기안자가 admin 이라도 본인 승인 불가
        $adminDrafter = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $transfer = $this->service->applyDeposit($source, $target, 20_000_000, $adminDrafter, 'deposit_down');

        $this->expectException(DomainException::class);
        $this->service->executeDepositApply($transfer, $adminDrafter);
    }

    public function test_volt_submit_creates_request(): void
    {
        ['source' => $source, 'target' => $target] = $this->ctx();
        // 기안 권한 + 전체 스코프(담당자 없는 차량 openEdit 가능)인 super 로 UI 흐름 검증.
        $drafter = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($drafter);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->set('applyDepositSourceId', (string) $source->id)
            ->set('applyDepositAmountStr', '20,000,000')
            ->set('applyDepositType', 'deposit_down')
            ->set('applyDepositReason', '완납분 일부 이전')
            ->call('submitApplyDeposit')
            ->assertHasNoErrors();

        $transfer = InterVehicleTransfer::where('kind', InterVehicleTransfer::KIND_DEPOSIT_APPLY)->first();
        $this->assertNotNull($transfer);
        $this->assertSame($source->id, $transfer->source_vehicle_id);
        $this->assertSame($target->id, $transfer->target_vehicle_id);
        $this->assertSame(InterVehicleTransfer::STATUS_PENDING, $transfer->status);
    }

    public function test_approval_request_execute_routes_to_deposit_apply(): void
    {
        ['drafter' => $drafter, 'admin' => $admin, 'source' => $source, 'target' => $target] = $this->ctx();
        $transfer = $this->service->applyDeposit($source, $target, 15_000_000, $drafter, 'balance');

        // 최고관리자 컨텍스트에서 ApprovalRequest.execute() → executeDepositApply 즉시 적용
        $this->actingAs($admin);
        $transfer->approvalRequest->execute();

        $this->assertSame(InterVehicleTransfer::STATUS_EXECUTED, $transfer->fresh()->status);
        $this->assertSame(2, FinalPayment::where('transfer_id', $transfer->id)->count());
    }

    public function test_guard_source_over_50_percent_unpaid_blocks(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $source = Vehicle::create([
            'vehicle_number' => '77가7777', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000_000, 'currency' => 'KRW',
        ]);
        $source->finalPayments()->create(['amount' => 30_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);  // 30% 입금
        $source->refresh();
        $target = Vehicle::create([
            'vehicle_number' => '77가8888', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 50_000_000, 'currency' => 'KRW',
        ]);

        $this->expectException(DomainException::class);   // 소스 미수율 70% > 50% → 차단
        $this->service->applyDeposit($source, $target, 5_000_000, $drafter, 'balance');
    }
}
