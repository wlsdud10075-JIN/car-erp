<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PurchaseRegistrationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 무담보 체크 **강제** (jin 2026-08-21) — 잠긴 문 옆의 뚫린 창문을 닫는다.
 *
 * 구조적 구멍:
 *   무담보 한도를 설정하면 그 바이어는 미수율 판정에서 빠져 금액 판정으로 넘어간다.
 *   그런데 그 금액은 「무담보로 지급」이 켜진 차의 계약금만큼만 줄어들기 때문에,
 *   **아무도 안 켜면 잔액이 영영 안 줄어 매입 락이 통째로 사라진다.**
 *   기존 해제 가드는 「켰다가 끄는 것」만 막았지 「처음부터 안 켜는 것」은 무방비였다.
 *
 * 실측(heymanerp 2026-08-21): 무담보 설정 2명 / 체크된 차량 전체 1대.
 *   R.S.H 는 거래완료 60건짜리 최대 거래처인데 사실상 매입 락이 없었다.
 */
class UnsecuredCheckRequiredTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function buyer(?int $limit = null): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create([
            'name' => 'B'.$this->n, 'is_active' => true,
            'salesman_id' => $s->id, 'unsecured_limit_krw' => $limit,
        ]);
    }

    /** @param int $paidKrw 판매 입금 — 미수율을 좌우한다(임계 초과여야 강제가 발동). */
    private function vehicle(Buyer $b, int $salePrice, int $paidKrw): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'UC'.++$this->n.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-08-02',
            'is_unsecured_down' => false,
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    private function saveDownPayment(Vehicle $v, string $amount, bool $check = false)
    {
        return Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_unsecured_down', $check)
            ->set('down_payment_str', $amount)
            ->call('save');
    }

    // ── 강제가 발동한다 ────────────────────────────────────────

    public function test_down_payment_is_blocked_without_the_unsecured_check(): void
    {
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 0);   // 미수 100% — 무담보가 없었으면 락

        $this->saveDownPayment($v, '1,000,000')
            ->assertHasErrors('down_payment_str');

        $this->assertSame(0, (int) $v->fresh()->confirmed_down_payment,
            '거부됐는데 계약금이 저장되면 한도가 안 줄어든 채 돈만 나간 기록이 남는다');
    }

    public function test_checking_the_box_lets_it_through_and_draws_down_the_limit(): void
    {
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 0);

        $this->saveDownPayment($v, '1,000,000', check: true)
            ->assertHasNoErrors();

        $this->assertTrue((bool) $v->fresh()->is_unsecured_down);
        $this->assertSame(4_000_000, $b->fresh()->receivableGauge()['unsecured_available_krw'],
            '체크했으면 계약금만큼 무담보 잔액이 줄어야 한다');
    }

    // ── 강제가 발동하지 않는 경우 ──────────────────────────────

    /** 미수가 기준 안이면 평소대로 자유롭게 저장된다 — 강제는 "락이었을 상황"에만. */
    public function test_no_force_when_the_buyer_is_within_the_threshold(): void
    {
        $b = $this->buyer(5_000_000);
        $need = Setting::lockRequiredPaidPct('purchase_registration');
        $v = $this->vehicle($b, 10_000_000, (int) (10_000_000 * ($need + 10) / 100));

        $this->saveDownPayment($v, '1,000,000')->assertHasNoErrors();

        $this->assertSame(1_000_000, (int) $v->fresh()->confirmed_down_payment);
        $this->assertFalse((bool) $v->fresh()->is_unsecured_down, '강제되지 않았으므로 꺼진 채여야 한다');
    }

    /** 무담보를 안 쓰는 바이어는 종전 그대로 — 그쪽은 미수율 락이 이미 제 역할을 한다. */
    public function test_no_force_for_buyers_without_an_unsecured_limit(): void
    {
        $b = $this->buyer();   // 미설정
        $v = $this->vehicle($b, 10_000_000, 0);

        $this->saveDownPayment($v, '1,000,000')->assertHasNoErrors();

        $this->assertSame(1_000_000, (int) $v->fresh()->confirmed_down_payment);
    }

    /** 계약금이 없으면 무담보가 줄어들 일도 없으니 강제하지 않는다(잔금·매도비만 만지는 저장). */
    public function test_no_force_without_a_down_payment(): void
    {
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 0);

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('purchase_price_str', '12,000,000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12_000_000, (int) $v->fresh()->purchase_price);
    }

    // ── 구멍이 실제로 닫히는가 (end-to-end) ────────────────────

    /**
     * 강제가 없으면 무담보 바이어는 계약금을 아무리 걸어도 잔액이 안 줄어 **영원히 통과**한다.
     * 강제가 들어가면 계약금이 쌓여 잔액이 0 이 되고 그제서야 진짜 락이 걸린다.
     */
    public function test_the_limit_actually_runs_out_now(): void
    {
        $b = $this->buyer(2_000_000);

        foreach ([1_000_000, 1_000_000] as $i => $amount) {
            $v = $this->vehicle($b, 10_000_000, 0);
            $this->saveDownPayment($v, number_format($amount), check: true)->assertHasNoErrors();
        }

        $gauge = $b->fresh()->receivableGauge();
        $this->assertSame(0, $gauge['unsecured_available_krw'], '두 건이면 한도가 소진돼야 한다');

        $verdict = PurchaseRegistrationGate::decide($gauge, true);
        $this->assertTrue($verdict['locked'], '한도가 0 이면 신규 매입 등록이 막혀야 한다');
    }

    /** 바이어별 락 %를 낮추면 강제 조건(미수율 > 임계)에서 빠져나온다 — 두 기능이 이어져 있다. */
    public function test_buyer_lock_override_relaxes_the_force(): void
    {
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 4_000_000);   // 미수 60%

        $this->saveDownPayment($v, '1,000,000')->assertHasErrors('down_payment_str');

        // 그 바이어만 필요입금 30% 로 풀어주면 미수 60% 도 기준 안이 된다.
        $b->update(['lock_purchase_registration_pct' => 30]);

        $this->saveDownPayment($v->fresh(), '1,000,000')->assertHasNoErrors();
    }

    // ── 강제가 발동하면 안 되는 경우 (jin 2026-08-26 제보) ───────────

    /**
     * 🚨 **이미 나간 계약금 때문에 무관한 저장이 막히면 안 된다.**
     *
     * 실사고 248가4049 — 계약금 2,000만이 2026-06-19 에 확정 지급됐고 매입 완납·선적완료인데,
     * **판매 잔금**을 고치려고 저장할 때마다 이 가드가 떴다. 계약금 칸에 옛 값이 실려 있어
     * `> 0` 이 항상 참이었기 때문이다(save() 는 매 저장마다 전 게이트를 다시 본다).
     * 그때 체크를 켜라고 강요하면 **두 달 전 지급을 「회사가 대신 냈다」로 만드는 거짓 기록**이 된다.
     */
    public function test_an_already_paid_down_payment_does_not_block_unrelated_saves(): void
    {
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 0);   // 미수 100% — 강제 조건 자체는 성립

        // ① 처음 계약금을 넣을 때는 체크가 필요하다(기존 동작 보존)
        $this->saveDownPayment($v, '1,000,000', check: true)->assertHasNoErrors();
        $v = $v->fresh();
        $this->assertSame(1_000_000, (int) $v->confirmed_down_payment);

        // ② 같은 금액이 실린 채로 **다른 것**을 고치는 저장 — 막히면 안 된다.
        //    (체크를 끄는 건 해제 가드가 따로 막는다. 여기선 켠 채로 둔다.)
        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('vessel_name', 'MV TEST')
            ->call('save')
            ->assertHasNoErrors('down_payment_str');
    }

    /**
     * 🚨 **선적 진입 조건을 넘긴 차는 강제하지 않는다 — 켜도 한도가 안 줄어든다.**
     *
     * 무담보 사용액은 `Buyer::computeReceivableGauge` 가 `! isShippingEntryMet()` 인 차의
     * 계약금만 더한다. 넘긴 차를 켜 봐야 차감액이 0 이라 **효과 없이 저장만 막는 셈**이다.
     * 바로 위 해제 가드는 같은 조건을 이미 보고 있었다 — 같은 불변식인데 한쪽만 안 봤다.
     */
    public function test_no_force_once_the_sale_has_cleared_the_shipping_entry_bar(): void
    {
        $b = $this->buyer(5_000_000);
        // 판매대금 전액 입금 → 미수 0 → isShippingEntryMet() 통과
        $v = $this->vehicle($b, 10_000_000, 10_000_000);

        $this->assertTrue($v->isShippingEntryMet(), '전제가 깨졌다 — 이 차는 선적 진입 조건을 넘어야 한다');

        $this->saveDownPayment($v, '1,000,000')->assertHasNoErrors('down_payment_str');
        $this->assertSame(1_000_000, (int) $v->fresh()->confirmed_down_payment);
    }
}
