<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🛒 **바이어가 구매를 취소했을 때 「바이어 미정」으로 되돌리기** (jin 2026-09-17).
 *
 * 실사고 = ssancarerp `42너5507` — *「바이어가 차량 사는 걸 취소해서 바이어 미정으로 바꿔야 하는데
 * 바이어가 있는 상태에서 다시 바꾸는 게 안 되나 봐」*.
 *
 * 🔑 **버그가 아니라 규칙이다** — 「판매가 > 0 이면 바이어 필수」(2026-05-26). DB CHECK 가 FK 컬럼에
 *    못 걸려(`chk_sale_required` 는 바이어를 안 본다, §8 #25) **이 앱 검사가 유일한 강제 지점**이다.
 *    ⇒ 바이어만 비울 수는 없고, **판매 자체를 무르면**(판매가·판매일 비우기) 된다.
 *
 * ⚠️ 고친 것은 **안내 문구 하나**다(jin 결정). 기존에도 친절한 문구가 있었지만 `$this->validate()`
 *    **뒤**에 있어서 일반 규칙 메시지가 **먼저 떠서 가려졌다** — 그래서 무엇을 해야 하는지 안 보였다.
 */
class BuyerUndecidedRevertTest extends TestCase
{
    use RefreshDatabase;

    /** 실사고와 같은 상태 — 매입 완납 · 판매됨(EUR) · 운임비 있음 · 입금 0 · 출고 전. */
    private function soldVehicle(): array
    {
        $admin = User::factory()->create([
            'permission' => 'super', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'CANCELLED BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '42너5507', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1713,
            'salesman_id' => $sm->id,
            'buyer_id' => $buyer->id, 'export_buyer_id' => $buyer->id, 'bl_buyer_id' => $buyer->id,
            'purchase_price' => 12_000_000, 'selling_fee' => 440_000, 'purchase_date' => '2026-04-29',
            'sale_price' => 6_992, 'sale_date' => '2026-09-01', 'transport_fee' => 1_486,
        ]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 12_440_000,
            'payment_date' => '2026-04-29', 'confirmed_at' => now()->subMonths(4),
        ]);
        $v->refresh()->refreshCaches();

        return [$v->fresh(), $admin];
    }

    /** 전제 — 실사고 상태(판매됨 · 미수 전액 · 재고 안에 있음)가 재현됐나. */
    public function test_the_fixture_reproduces_the_reported_state(): void
    {
        [$v] = $this->soldVehicle();

        $this->assertSame(8478.0, (float) $v->sale_total_amount, '판매가 + 운임비');
        $this->assertSame(8478.0, (float) $v->sale_unpaid_amount, '입금이 없어 전액 미수');
        $this->assertTrue(Vehicle::query()->inStock()->where('id', $v->id)->exists(), '매입 완납·출고 전이라 재고다');
    }

    /** 🚫 바이어를 둔 채 「미정」만 켜면 저장 훅이 자동으로 끈다 — 모순 상태를 안 만든다. */
    public function test_ticking_undecided_while_a_buyer_is_set_is_ignored(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('buyer_undecided', true)
            ->call('save');

        $this->assertFalse((bool) $v->fresh()->buyer_undecided, '바이어가 있는데 미정이 켜졌다');
        $this->assertNotNull($v->fresh()->buyer_id);
    }

    /**
     * 🚫 판매가가 남아 있으면 바이어를 못 비운다 — 그리고 **무엇을 해야 하는지 화면이 말한다**.
     *
     * ⚠️ 이 단언이 이 테스트의 본체다. 고치기 전에는 일반 규칙 메시지(「필수 입력 항목입니다」)만 떠서
     *    사람이 「바꾸는 방법이 없다」고 읽었다(§8 #60 — 동작을 결정하는 것은 화면에 있어야 한다).
     */
    public function test_clearing_only_the_buyer_explains_what_to_do(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('buyer_id_str', '')
            ->set('buyer_undecided', true)
            ->call('save');

        $c->assertHasErrors('buyer_id_str');
        $shown = $c->errors()->first('buyer_id_str');
        $this->assertStringContainsString('바이어 미정', $shown, '되돌리는 방법을 안내하지 않는다');
        $this->assertStringContainsString('판매가', $shown);
        $this->assertStringNotContainsString('필수 입력 항목입니다', $shown, '일반 규칙 메시지가 그대로 뜬다');

        $this->assertNotNull($v->fresh()->buyer_id, '바이어가 지워졌다 — 판매가가 있는데 통과했다');
    }

    /** ✅ 판매를 무르면(판매가·판매일·운임비 비우기) 바이어 미정으로 돌아간다 — 그리고 일반재고가 된다. */
    public function test_clearing_the_sale_reverts_to_buyer_undecided(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '')
            ->set('sale_date', '')
            ->set('transport_fee_str', '')
            ->set('buyer_id_str', '')
            ->set('buyer_undecided', true)
            ->call('save')
            ->assertHasNoErrors();

        $f = $v->fresh();
        $this->assertNull($f->buyer_id);
        $this->assertTrue((bool) $f->buyer_undecided, '미정 플래그가 안 남았다');
        $this->assertSame(0.0, (float) $f->sale_unpaid_amount, '미수가 남았다');
        // 매입 완납(12,440,000 확정) 차량이라 판매를 무르면 「매입완료」로 내려온다.
        $this->assertSame('매입완료', $f->progress_status_cache);
        $this->assertTrue(Vehicle::query()->generalStock()->where('id', $v->id)->exists(),
            '판매를 물렀으면 일반재고로 돌아와야 한다');
    }

    /**
     * ⚠️ **운임비를 안 비우면 미수가 남는다** — 판매가만 지우면 「판매가 0 인데 받을 돈이 있는」 차가 된다.
     *    화면 안내가 운임비를 함께 말하는 이유이자, 이 테스트가 그 사실을 박제하는 이유다.
     */
    public function test_leaving_the_freight_keeps_a_receivable_behind(): void
    {
        [$v, $admin] = $this->soldVehicle();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('sale_price_str', '')
            ->set('sale_date', '')
            ->set('buyer_id_str', '')
            ->set('buyer_undecided', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1486.0, (float) $v->fresh()->sale_unpaid_amount,
            '운임비를 남기면 미수가 남는다는 사실이 바뀌었다 — 안내 문구도 같이 확인할 것');
    }
}
