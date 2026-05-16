<?php

namespace Tests\Feature;

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
 * 큐 19-F-C — /erp/transfers (재무 처리 대기 페이지) 통합 테스트.
 *
 * 권한 (SoD):
 *   - settlement 미들웨어 통과 (super/admin/정산/관리)
 *   - mount() 에서 canConfirmFinanceTransfer() 추가 검증 (관리 role 차단)
 *   - self-confirm 차단 (approver_id === auth->id 시 모달 차단)
 */
class TransfersIndexTest extends TestCase
{
    use RefreshDatabase;

    private function makeAwaitingTransfer(?User $approver = null, ?User $financeUser = null): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = $approver ?? User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $finance = $financeUser ?? User::factory()->create(['permission' => 'user', 'role' => '정산']);

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

        $service = app(InterVehicleTransferService::class);
        $this->actingAs($sales);
        $transfer = $service->request($source, $target, 25_000_000, $sales, reason: '이체 요청');
        $this->actingAs($manager);
        $service->approve($transfer, $manager);
        $transfer->refresh();

        return compact('buyer', 'sales', 'manager', 'finance', 'source', 'target', 'service', 'transfer');
    }

    public function test_finance_role_can_access_transfers_page(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['finance']);

        $response = $this->get(route('erp.transfers.index'));

        $response->assertStatus(200);
        $response->assertSee('재무 처리');
    }

    public function test_admin_can_access_transfers_page(): void
    {
        $this->makeAwaitingTransfer();
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($admin);

        $response = $this->get(route('erp.transfers.index'));

        $response->assertStatus(200);
    }

    public function test_manager_role_blocked_from_transfers_page(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['manager']);

        // settlement 미들웨어는 관리 role 통과시키지만 mount() 에서 abort(403)
        $response = $this->get(route('erp.transfers.index'));

        $response->assertStatus(403);
    }

    public function test_sales_role_blocked_from_transfers_page(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['sales']);

        // 영업은 settlement 미들웨어 자체에서 차단
        $response = $this->get(route('erp.transfers.index'));

        $response->assertStatus(403);
    }

    public function test_awaiting_filter_shows_only_awaiting_transfers(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['finance']);

        Volt::test('erp.transfers.index')
            ->assertSee('99가0001')
            ->assertSee('99가0002')
            ->assertSee('관리 승인 (재무 처리 대기)');
    }

    public function test_confirm_by_finance_creates_paired_final_payments(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['finance']);

        Volt::test('erp.transfers.index')
            ->call('openModal', $c['transfer']->id)
            ->assertSet('showModal', true)
            ->set('financeNote', '시중은행 KB 12345-6789')
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $c['transfer']->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $c['transfer']->status);
        $this->assertEquals($c['finance']->id, $c['transfer']->confirmed_by_user_id);
        $this->assertEquals('시중은행 KB 12345-6789', $c['transfer']->finance_note);
        $this->assertCount(2, FinalPayment::where('transfer_id', $c['transfer']->id)->get());
    }

    public function test_self_confirm_blocked_by_modal_guard(): void
    {
        // approver == finance 인 케이스 — admin 1명이 양쪽 권한 보유한 시뮬레이션
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $admin = User::factory()->create(['permission' => 'admin']);

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

        $service = app(InterVehicleTransferService::class);
        $this->actingAs($sales);
        $transfer = $service->request($source, $target, 25_000_000, $sales);
        $this->actingAs($admin);
        $service->approve($transfer, $admin);

        // admin 이 본인 승인 건을 직접 확정 시도 → openModal 에서 차단 (모달 안 열림)
        Volt::test('erp.transfers.index')
            ->call('openModal', $transfer->id)
            ->assertSet('showModal', false);

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $transfer->status);
        $this->assertCount(0, FinalPayment::where('transfer_id', $transfer->id)->get());
    }

    /**
     * 큐 19-F — void 흐름 재무 확정.
     * approveVoid 호출 후 page 에서 confirmVoidByFinance 트리거 → status=voided.
     */
    public function test_void_confirm_creates_reverse_final_payments(): void
    {
        $c = $this->makeAwaitingTransfer();
        // 일단 executed 까지 만든다
        $c['service']->confirmByFinance($c['transfer'], $c['finance']);
        // void 요청 → 관리 승인 (approveVoid)
        $this->actingAs($c['sales']);
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '거래 무산');
        $this->actingAs($c['manager']);
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], '거래 무산 — 관리 승인');

        // 재무 처리 페이지에서 voided_awaiting_finance 행 처리
        $this->actingAs($c['finance']);
        Volt::test('erp.transfers.index')
            ->call('openModal', $c['transfer']->id)
            ->set('financeNote', '환불 거래 RV456')
            ->call('confirm')
            ->assertHasNoErrors();

        $c['transfer']->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED, $c['transfer']->status);
        $this->assertEquals('환불 거래 RV456', $c['transfer']->finance_note);
        $this->assertCount(4, FinalPayment::where('transfer_id', $c['transfer']->id)->get());
    }

    /**
     * 큐 19-K — /erp/transfers 모달에서 'reject' 모드로 재무 거부.
     * approved_awaiting_finance → finance_rejected 전환 + 메타 기록 + final_payment 미생성.
     */
    public function test_finance_reject_marks_status_and_records_reason(): void
    {
        $c = $this->makeAwaitingTransfer();
        $this->actingAs($c['finance']);

        Volt::test('erp.transfers.index')
            ->call('openModal', $c['transfer']->id, 'reject')
            ->assertSet('showModal', true)
            ->assertSet('decisionMode', 'reject')
            ->set('rejectReason', '통장 잔액 부족 — 영업 재요청 필요')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $c['transfer']->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_FINANCE_REJECTED, $c['transfer']->status);
        $this->assertEquals($c['finance']->id, $c['transfer']->finance_rejected_by_user_id);
        $this->assertEquals('통장 잔액 부족 — 영업 재요청 필요', $c['transfer']->finance_reject_reason);
        $this->assertNotNull($c['transfer']->finance_rejected_at);

        // ledger 영향 0
        $this->assertCount(0, FinalPayment::where('transfer_id', $c['transfer']->id)->get());

        // executed 메타는 그대로 비어있어야
        $this->assertNull($c['transfer']->confirmed_by_user_id);
    }

    /**
     * 큐 19-L — /erp/transfers 모달에서 void 거부 시 transfer.status=executed 복귀 + 메타 기록.
     * final_payment 페어 그대로 유지.
     */
    public function test_finance_void_reject_reverts_to_executed_keeping_final_payments(): void
    {
        $c = $this->makeAwaitingTransfer();
        // 일단 executed 까지 만들고 void 요청 + 관리 승인까지 진행
        $c['service']->confirmByFinance($c['transfer'], $c['finance']);
        $this->actingAs($c['sales']);
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '거래 무산');
        $this->actingAs($c['manager']);
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], '관리 승인');

        $this->assertEquals(2, FinalPayment::where('transfer_id', $c['transfer']->id)->count());

        // 재무가 void 거부 (모달 reject 모드)
        $this->actingAs($c['finance']);
        Volt::test('erp.transfers.index')
            ->call('openModal', $c['transfer']->id, 'reject')
            ->assertSet('decisionMode', 'reject')
            ->set('rejectReason', '환불 거부 — 영업이 재확인 필요')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $c['transfer']->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $c['transfer']->status);
        $this->assertEquals($c['finance']->id, $c['transfer']->void_finance_rejected_by_user_id);
        $this->assertEquals('환불 거부 — 영업이 재확인 필요', $c['transfer']->void_finance_reject_reason);
        $this->assertNotNull($c['transfer']->void_finance_rejected_at);

        // final_payment 페어 그대로 유지 (역 페어 미생성)
        $this->assertEquals(2, FinalPayment::where('transfer_id', $c['transfer']->id)->count());
    }
}
