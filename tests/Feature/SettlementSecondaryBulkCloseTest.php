<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2차 정산 완료 일괄 (jin 2026-08-26).
 *
 * 핵심은 «건너뛴 건이 카운터에 묻히지 않는다»다 — 미리보기가 차량번호 + 사유를 그대로 보여줘야
 * 정작 손봐야 할 차(미완납·환율누락)를 사람이 본다. 실제로 운영 54건 중 2건이 그 상태였다
 * (248가4049·29마0712, 운임비 1,528 EUR 미수).
 *
 * 그리고 미리보기와 실행은 같은 판정(secondaryCloseBlocker)을 써야 한다. 갈리면
 * 「목록엔 마감된다고 떴는데 안 닫히는」 행이 남는다.
 */
class SettlementSecondaryBulkCloseTest extends TestCase
{
    use RefreshDatabase;

    private Salesman $salesman;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesman = Salesman::create(['name' => 'BULK SM', 'type' => 'freelance', 'is_active' => true]);
        $this->buyer = Buyer::create(['name' => 'BULK BUYER', 'is_active' => true]);
    }

    /** @param  float  $paid  확정 잔금으로 실제 입금된 외화 금액 */
    private function makeSettlement(string $plate, float $salePrice, float $freight, float $paid): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1729, 'dhl_request' => false,
            'buyer_id' => $this->buyer->id, 'salesman_id' => $this->salesman->id,
            'sale_date' => '2026-07-02', 'sale_price' => $salePrice, 'transport_fee' => $freight,
        ]);

        if ($paid > 0) {
            FinalPayment::create([
                'vehicle_id' => $v->id, 'amount' => $paid, 'type' => 'balance',
                'payment_date' => '2026-07-02', 'exchange_rate' => 1729,
                'confirmed_at' => now(),
            ]);
        }

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $this->salesman->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
            'secondary_status' => 'pending', 'attributed_month' => '2026-07-01',
        ]);
    }

    private function actAsFinance(): User
    {
        $u = User::factory()->create(['role' => '재무']);
        $this->actingAs($u);

        return $u;
    }

    public function test_preview_separates_closable_from_skipped_with_a_reason(): void
    {
        $ok = $this->makeSettlement('BULK-OK', 10000, 0, 10000);       // 완납
        $stuck = $this->makeSettlement('BULK-STUCK', 10000, 1528, 10000); // 운임비 미수
        $this->actAsFinance();

        $component = Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->call('openCloseSecondaryModal')
            ->assertSet('showCloseSecondaryModal', true);

        // 모달이 실제로 그려지고 건너뛴 차량번호가 화면에 나와야 한다 (카운터에 묻히면 안 됨).
        $component->assertSee('BULK-STUCK')->assertSee(__('settlement.batch.close_warning'));

        $preview = $component->get('closeSecondaryPreview');

        $this->assertSame([$ok->id], array_column($preview['ready'], 'id'));
        $this->assertSame([$stuck->id], array_column($preview['skipped'], 'id'));
        $this->assertSame('BULK-STUCK', $preview['skipped'][0]['plate'], '건너뛴 건의 차량번호가 안 보임');
        $this->assertNotEmpty($preview['skipped'][0]['reason'], '건너뛴 사유가 비어 있음');
    }

    public function test_bulk_close_closes_only_the_previewed_rows(): void
    {
        $ok = $this->makeSettlement('BULK-OK', 10000, 0, 10000);
        $stuck = $this->makeSettlement('BULK-STUCK', 10000, 1528, 10000);
        $this->actAsFinance();

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->call('openCloseSecondaryModal')
            ->call('closeSecondaryMonth')
            ->assertSet('showCloseSecondaryModal', false);

        $this->assertSame('closed', $ok->fresh()->secondary_status);
        $this->assertSame('pending', $stuck->fresh()->secondary_status, '미완납 차량이 일괄에 쓸려 들어감');
    }

    public function test_bulk_close_honours_the_month_filter(): void
    {
        $july = $this->makeSettlement('BULK-JUL', 10000, 0, 10000);
        $june = $this->makeSettlement('BULK-JUN', 10000, 0, 10000);
        $june->update(['attributed_month' => '2026-06-01']);
        $this->actAsFinance();

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->call('openCloseSecondaryModal')
            ->call('closeSecondaryMonth');

        $this->assertSame('closed', $july->fresh()->secondary_status);
        $this->assertSame('pending', $june->fresh()->secondary_status, '다른 귀속월까지 마감됨');
    }

    public function test_bulk_close_requires_a_month(): void
    {
        $s = $this->makeSettlement('BULK-OK', 10000, 0, 10000);
        $this->actAsFinance();

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '')
            ->call('openCloseSecondaryModal')
            ->assertSet('showCloseSecondaryModal', false);

        $this->assertSame('pending', $s->fresh()->secondary_status);
    }

    public function test_bulk_close_is_forbidden_for_sales_role(): void
    {
        $s = $this->makeSettlement('BULK-OK', 10000, 0, 10000);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업']));

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->call('closeSecondaryMonth')
            ->assertStatus(403);

        $this->assertSame('pending', $s->fresh()->secondary_status);
    }

    // -- 버튼 노출 규칙 (jin 2026-08-26) ------------------------------------
    //   "이번 달에도 눌러야 하나" 하는 헷갈림을 없애려고 대상 0건이면 버튼을 안 띄운다.
    //   jin 제안(일괄 확정을 누른 이전 달에만)은 «누른 기록»이 없어서 판정 불가 —
    //   secondary_status='pending' 이 이미 «지급까지 끝난 달»을 뜻하므로 건수만 보면 된다.

    public function test_button_hidden_when_the_month_has_nothing_to_close(): void
    {
        $s = $this->makeSettlement('BULK-OK', 10000, 0, 10000);
        $s->update(['secondary_status' => 'closed']);   // 이미 다 마감된 달
        $this->actAsFinance();

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->assertSet('secondaryPendingCount', 0)
            ->assertDontSee(__('settlement.batch.close_secondary', ['count' => 0]));
    }

    public function test_button_shows_the_pending_count_for_a_month_that_has_work(): void
    {
        $this->makeSettlement('BULK-A', 10000, 0, 10000);
        $this->makeSettlement('BULK-B', 10000, 1528, 10000);   // 건너뛸 것도 대상 건수엔 든다
        $this->actAsFinance();

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->assertSet('secondaryPendingCount', 2)
            ->assertSee(__('settlement.batch.close_secondary', ['count' => 2]));
    }

    public function test_button_hidden_without_a_month_and_for_sales_role(): void
    {
        $this->makeSettlement('BULK-OK', 10000, 0, 10000);

        $this->actAsFinance();
        Volt::test('erp.settlements.index')
            ->set('monthFilter', '')
            ->assertSet('secondaryPendingCount', 0);   // 월 미선택 = 전체 일괄 마감 금지

        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업']));
        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->assertSet('secondaryPendingCount', 0);   // 권한 없음
    }

    public function test_inline_single_close_still_works_after_refactor(): void
    {
        $s = $this->makeSettlement('BULK-OK', 10000, 0, 10000);
        $this->actAsFinance();

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $this->assertSame('closed', $s->fresh()->secondary_status);
    }
}
