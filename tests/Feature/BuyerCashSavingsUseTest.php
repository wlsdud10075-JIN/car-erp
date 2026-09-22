<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashReceipt;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💳 바이어 드로어 현금 탭 「적립금 사용」 (jin 2026-09-22).
 *
 * jin: «채권관리나 차량수정 판매탭에서 적립금을 사용하면 (여기에) 미러가 되지 않는 것 같아 … 적립금사용을 하는걸 추가하는게 여기다. 같이 미러도 되야 하고.»
 *
 * 적립금 탭의 옛 「사용」 입력은 차량과 연결되지 않아 어디에도 미러되지 않았다. 현금 탭 구역은 채권관리 「적립금」 회수와
 * **같은 행**(ReceivableHistory method=savings)을 만들어 vehicles.savings_used → SavingsStatus USED(vehicle_id) 로 한 번에 미러된다.
 * 🚫 현금 원장(buyer_cash_*)엔 행을 넣지 않는다 — 적립금은 회사가 준 크레딧이라 원장 밖(설계 확정 #1, §8 #83).
 */
class BuyerCashSavingsUseTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function vehicle(Buyer $buyer, string $currency = 'EUR'): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => $currency, 'exchange_rate' => 1400, 'dhl_request' => false,
            'salesman_id' => $buyer->salesman_id, 'buyer_id' => $buyer->id,
            'sale_price' => 50000, 'sale_date' => now()->toDateString(),
        ]);
    }

    private function enableCash(): void
    {
        Setting::updateOrCreate(['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
    }

    private function earn(Buyer $buyer, float $amount, string $currency = 'EUR'): void
    {
        $latest = SavingsStatus::where('buyer_id', $buyer->id)->where('currency', $currency)->orderByDesc('id')->first();
        SavingsStatus::create([
            'buyer_id' => $buyer->id, 'currency' => $currency, 'exchange_rate' => 1400,
            'transaction_type' => 'EARNED', 'savings' => $amount, 'balance' => (float) ($latest?->balance ?? 0) + $amount,
            'note' => '테스트 적립',
        ]);
    }

    /** 드로어에서 쓰면 판매탭(savings_used)·채권관리(회수이력)·적립금 잔액(USED, vehicle_id)에 한 번에 미러된다. 원장은 불변. */
    public function test_using_savings_from_the_cash_tab_mirrors_everywhere_and_leaves_the_cash_ledger_alone(): void
    {
        $this->enableCash();
        $b = $this->buyer();
        $v = $this->vehicle($b);
        $this->earn($b, 300);
        $receipt = BuyerCashReceipt::create(['buyer_id' => $b->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 1000]);
        $allocBefore = BuyerCashAllocation::count();

        $this->actingAs($this->finance());
        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->set('sv_vehicle_id', (string) $v->id)
            ->set('sv_amount', '300')
            ->set('sv_date', '2026-09-22')
            ->call('useSavingsForVehicle')
            ->assertHasNoErrors()
            ->assertSee($v->vehicle_number);

        $v->refresh();
        $this->assertSame(300.0, (float) $v->savings_used, '판매탭 savings_used 에 미러되지 않았다');
        $this->assertSame(49700.0, (float) $v->sale_unpaid_amount, '미수가 적립금만큼 줄지 않았다');

        $used = SavingsStatus::where('buyer_id', $b->id)->where('transaction_type', 'USED')->first();
        $this->assertNotNull($used, 'SavingsStatus USED 행이 없다');
        $this->assertSame($v->id, (int) $used->vehicle_id, 'USED 행에 차량이 안 붙었다 — 옛 적립금 탭과 같은 미러 안 되는 형태');
        $this->assertSame(0.0, (float) $used->balance);

        $rh = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'savings')->first();
        $this->assertNotNull($rh, '채권관리 회수이력(savings) 행이 없다');
        $this->assertSame(300.0, (float) $rh->amount);
        $this->assertSame('2026-09-22', $rh->collected_at->format('Y-m-d'));

        $this->assertSame($allocBefore, BuyerCashAllocation::count(), '현금 원장 배분이 생겼다 — 적립금은 원장 밖이어야 한다');
        $this->assertSame(1000.0, (float) $receipt->fresh()->remaining_amount, '남은 현금이 움직였다');
    }

    /** 잔액보다 크면 거부 — 아무 행도 안 생긴다. */
    public function test_more_than_the_balance_is_refused(): void
    {
        $this->enableCash();
        $b = $this->buyer();
        $v = $this->vehicle($b);
        $this->earn($b, 100);

        $this->actingAs($this->finance());
        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->set('sv_vehicle_id', (string) $v->id)->set('sv_amount', '100.01')->set('sv_date', '2026-09-22')
            ->call('useSavingsForVehicle')
            ->assertHasErrors(['sv_amount']);

        $this->assertSame(0.0, (float) $v->fresh()->savings_used);
        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count());
    }

    /** 다른 바이어 차량 id 를 주입해도 거부(§8 #26). */
    public function test_another_buyers_vehicle_is_refused(): void
    {
        $this->enableCash();
        $b = $this->buyer();
        $other = $this->vehicle($this->buyer());
        $this->earn($b, 100);

        $this->actingAs($this->finance());
        Volt::test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->set('sv_vehicle_id', (string) $other->id)->set('sv_amount', '50')->set('sv_date', '2026-09-22')
            ->call('useSavingsForVehicle')
            ->assertHasErrors(['sv_vehicle_id']);

        $this->assertSame(0.0, (float) $other->fresh()->savings_used);
    }

    /** 반대 방향 미러 — 채권관리(또는 판매탭)에서 쓴 적립금이 드로어 현금 탭 이력에 차량번호와 함께 보인다. */
    public function test_usage_recorded_elsewhere_shows_up_in_the_cash_tab(): void
    {
        $this->enableCash();
        $b = $this->buyer();
        $v = $this->vehicle($b);
        $this->earn($b, 200);
        $this->actingAs($this->finance());
        ReceivableHistory::create([   // 채권관리 「적립금」 회수와 같은 행
            'vehicle_id' => $v->id, 'collected_at' => '2026-09-20', 'collector_id' => auth()->id(),
            'method' => 'savings', 'amount' => 120, 'note' => '채권관리에서',
        ]);
        $this->assertSame(120.0, (float) $v->fresh()->savings_used);

        $c = Volt::test('erp.buyers.index')->call('openEdit', $b->id);
        $list = $c->get('savingsUseList');
        $this->assertCount(1, $list);
        $this->assertSame($v->vehicle_number, $list[0]['vehicle_number']);
        $this->assertSame(-120.0, $list[0]['amount']);
        $this->assertSame(['EUR' => 80.0], $c->get('savingsUseBalances'));
    }
}
