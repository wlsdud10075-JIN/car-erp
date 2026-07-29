<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 이체 링크 잔금 보호 (2026-07-28) — 차량 편집 패널 저장이 이체 원장 링크를 끊지 못하게.
 *
 * 배경: 패널 save() 는 잔금을 쿼리빌더 bulk(whereIn->delete / where->update)로 동기화해서
 * 모델 이벤트가 안 뜬다 → FinalPayment/PBP 의 transfer_id 절대차단 가드가 통째로 우회됐다.
 * openEdit(:2585~2590)은 이미 이체 링크를 잠금 목록에 넣고 있었는데 save 만 빠져 있던 비대칭.
 * 끊기면 이체 취소(void) 영구 불가 + 이체 목록↔차량 잔금 desync + 감사 로그 0.
 */
class TransferLinkedPaymentProtectionTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /** 소스 S1($100k 완납) + 대상 T3(매입 3천만) + 기안/관리/재무. PurchaseFundingTest 와 동일 골격. */
    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $finance = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $source = Vehicle::create([
            'vehicle_number' => 'S1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $source->finalPayments()->create([
            'amount' => 100_000, 'type' => 'balance', 'payment_date' => '2026-05-02',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => 'T3', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'currency' => 'USD',   // standard 이체는 통화 일치를 요구한다
            'purchase_date' => '2026-05-10', 'purchase_price' => 30_000_000,
        ]);

        return compact('buyer', 'drafter', 'manager', 'finance', 'source', 'target');
    }

    /**
     * 이체 링크 PBP 생성.
     *
     * ⚠️ 이 행을 만들던 「매입 선지급」은 2026-07-29 제거됐다. 그래도 **가드는 남긴다** —
     * transfer_id 를 가진 PBP 가 운영에 남아 있을 수 있고, 가드가 지키는 대상은 kind 와 무관하다.
     * 따라서 생성 경로 대신 행을 직접 만들어 가드 자체를 검증한다.
     */
    private function executePurchaseFunding(array $ctx): array
    {
        $t = $this->service->request($ctx['source'], $ctx['target'], 10_000, $ctx['drafter']);
        $pbp = PurchaseBalancePayment::withoutEvents(fn () => PurchaseBalancePayment::create([
            'vehicle_id' => $ctx['target']->id,
            'transfer_id' => $t->id,
            'amount' => 30_000_000,
            'type' => 'balance',
            'payment_date' => '2026-05-20',
            'confirmed_by_user_id' => $ctx['finance']->id,
            'confirmed_at' => now(),
        ]));
        $t->update(['purchase_balance_payment_id' => $pbp->id]);

        return [$t->fresh(), $pbp->fresh()];
    }

    // ── ② 매입 선지급 PBP ─────────────────────────────────────────────

    public function test_purchase_funding_pbp_carries_transfer_id(): void
    {
        [$t, $pbp] = $this->executePurchaseFunding($this->ctx());

        $this->assertSame((int) $t->id, (int) $pbp->transfer_id, '역방향 링크 — 모델 가드가 이걸로 행을 지킨다');
        $this->assertSame((int) $pbp->id, (int) $t->purchase_balance_payment_id, '정방향 링크와 쌍');
    }

    public function test_transfer_linked_pbp_blocked_from_direct_update_and_delete(): void
    {
        [, $pbp] = $this->executePurchaseFunding($this->ctx());

        try {
            $pbp->update(['amount' => 1]);
            $this->fail('이체 링크 매입 잔금이 수정됐다');
        } catch (DomainException $e) {
            $this->assertStringContainsString('수정할 수 없습니다', $e->getMessage());
        }

        try {
            $pbp->delete();
            $this->fail('이체 링크 매입 잔금이 삭제됐다');
        } catch (DomainException $e) {
            $this->assertStringContainsString('삭제할 수 없습니다', $e->getMessage());
        }

        $this->assertDatabaseHas('purchase_balance_payments', ['id' => $pbp->id, 'amount' => 30_000_000]);
    }

    /**
     * 핵심 회귀 — 매입 잔금은 패널에서 금액칸·삭제버튼이 전부 열려 있어서(재무 권한)
     * 배열에서 행을 빼거나 금액을 고치면 그대로 bulk 반영되던 경로. 이제 서버가 무시해야 한다.
     */
    public function test_panel_save_cannot_delete_or_modify_transfer_linked_pbp(): void
    {
        $ctx = $this->ctx();
        [, $pbp] = $this->executePurchaseFunding($ctx);
        $target = $ctx['target']->fresh();

        $this->actingAs($ctx['finance']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            // 변조 시나리오: 링크 행을 목록에서 통째로 제거(=삭제 요청) 후 저장
            ->set('purchaseBalancePayments', [])
            ->call('save');

        // 이체 링크 매입 잔금이 패널 저장으로 사라지면 안 된다
        $this->assertDatabaseHas('purchase_balance_payments', [
            'id' => $pbp->id,
            'transfer_id' => $pbp->transfer_id,
            'amount' => 30_000_000,
        ]);

        // 금액 변조 시나리오
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->set('purchaseBalancePayments.0.amount', '1')
            ->call('save');

        $this->assertSame(30_000_000, (int) $pbp->fresh()->amount, '이체 링크 매입 잔금 금액은 패널에서 못 바꾼다');
    }

    public function test_panel_marks_transfer_linked_pbp_readonly(): void
    {
        $ctx = $this->ctx();
        $this->executePurchaseFunding($ctx);
        $this->actingAs($ctx['finance']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $ctx['target']->fresh()->id)
            ->assertSet('purchaseBalancePayments.0.transfer_locked', true);
    }

    // ── ① 판매 잔금 (보증금 적용) ──────────────────────────────────────

    /** 보증금 적용 실행 → 대상 차에 type=deposit_down + transfer_id 인 확정 잔금 생성. */
    private function executeDepositApply(array $ctx, string $paymentType = 'deposit_down'): array
    {
        $ctx['target']->update([
            'sale_date' => '2026-05-20', 'sale_price' => 50_000, 'exchange_rate' => 1300,
        ]);

        // 보증금 적용은 제거됐다(2026-07-29). 이체 링크 FinalPayment 는 standard 이체가 그대로 만든다 —
        // 가드가 지키는 대상은 kind 가 아니라 transfer_id 라 검증력은 동일하다.
        $t = $this->service->request($ctx['source'], $ctx['target']->fresh(), 10_000, $ctx['drafter']);
        $this->service->approve($t, $ctx['manager']);
        $this->service->confirmByFinance($t, $ctx['finance'], '은행이체 완료');
        $fp = FinalPayment::where('transfer_id', $t->id)->where('vehicle_id', $ctx['target']->id)->firstOrFail();
        // 4항목 sync 사정권(deposit_down 등)에 들어가는지가 회귀 지점이라 유형을 명시한다.
        FinalPayment::withoutEvents(fn () => $fp->forceFill(['type' => $paymentType])->saveQuietly());

        return [$t->fresh(), $fp->fresh()];
    }

    /**
     * 핵심 회귀 — 보증금 적용의 기본 유형이 deposit_down 이라 4항목 sync 사정권에 들어온다.
     * 그 sync 는 "type별 confirmed 전부 삭제 후 1행 재생성" 이라 링크 행을 통째로 갈아치웠다.
     */
    public function test_breakdown_sync_never_wipes_transfer_linked_final_payment(): void
    {
        $ctx = $this->ctx();
        [$t, $fp] = $this->executeDepositApply($ctx);
        $target = $ctx['target']->fresh();

        $this->actingAs($ctx['finance']);

        // 변조 시나리오: 계약금 박스(입력칸 없는 public 프로퍼티)에 임의 금액 쓰기.
        //   12,000 은 수기합(0)·전체합(10,000) 둘 다와 달라서 수정 전/후 모두 sync 의 delete 분기를
        //   반드시 태운다 — 값이 우연히 일치해 continue 로 빠져 통과하는 무력한 테스트가 되지 않도록.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->set('deposit_down_payment_str', '12000')
            ->call('save');

        $this->assertNotNull(
            FinalPayment::find($fp->id),
            '4항목 sync 가 이체 링크 잔금을 지웠다 — 이체 원장 desync + void 영구 불가'
        );
        $fp->refresh();
        $this->assertSame((int) $t->id, (int) $fp->transfer_id, '이체 링크가 끊기면 void 가 영영 불가해진다');
        $this->assertEqualsWithDelta(10_000, (float) $fp->amount, 0.01, '링크 행 금액은 sync 대상 아님');
        // 수기분은 정상 반영 — 보호가 기능을 막지 않는다
        $this->assertEqualsWithDelta(
            12_000,
            (float) FinalPayment::where('vehicle_id', $target->id)
                ->where('type', 'deposit_down')->whereNull('transfer_id')->sum('amount'),
            0.01
        );
    }

    public function test_breakdown_box_shows_manual_entries_only_and_transfer_shown_separately(): void
    {
        $ctx = $this->ctx();
        $this->executeDepositApply($ctx);
        $target = $ctx['target']->fresh();

        // 같은 유형의 수기 확정 잔금 1건 추가
        $this->actingAs($ctx['finance']);
        $target->finalPayments()->create([
            'amount' => 2_000, 'type' => 'deposit_down', 'payment_date' => '2026-05-25',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->assertSet('deposit_down_payment_str', '2000')            // 박스 = 수기분만
            ->assertSet('transferAppliedByType.deposit_down', 10000.0); // 이체분은 별도 노출(보조표시)
    }

    public function test_panel_save_cannot_delete_transfer_linked_final_payment(): void
    {
        $ctx = $this->ctx();
        [, $fp] = $this->executeDepositApply($ctx, 'balance');
        $target = $ctx['target']->fresh();

        $this->actingAs($ctx['finance']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->set('finalPayments', [])   // 변조: 잔금 목록 통째 비우고 저장
            ->call('save');

        // 이체 링크 판매 잔금이 패널 저장으로 사라지면 안 된다
        $this->assertDatabaseHas('final_payments', [
            'id' => $fp->id,
            'transfer_id' => $fp->transfer_id,
        ]);
    }
}
