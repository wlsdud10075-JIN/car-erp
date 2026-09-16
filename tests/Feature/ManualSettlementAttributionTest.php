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
 * 🗓️ **손으로 만든 정산도 귀속월·내수가 채워져야 한다** (jin 2026-09-16).
 *
 * jin: *«손으로 만든 정산도 그달에 만들면 채워줘야지? 이거 중요한거 같은데..?»*
 *
 * 정산관리 「신규 정산」 폼이 `attributed_month` 와 `is_domestic` 을 **하나도 안 채우고** 있었다.
 * 둘 다 조용히 망가진다 — 예외도 로그도 없고 화면에는 정상으로 보인다:
 *
 *   attributed_month 비었음 → **월배치에 영영 안 잡힌다.** 지급 대상이 아닌 채로 목록에만 남는다.
 *                             실측 ssancarerp 5건이 그 상태였다(전부 손으로 만든 것).
 *   is_domestic 비었음      → 내수 건이 **수출 공식으로 굳는다.** 생성 시 박제라 나중에
 *                             바이어를 고쳐도 안 바뀐다(부가세마진이 붙어 회사이익이 부푼다).
 *
 * 🔑 자동 생성(`Vehicle::createSettlementNow`)이 쓰는 **같은 함수**를 쓴다 — 옮겨 적으면 갈린다.
 */
class ManualSettlementAttributionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    /** 완납된 차 — 완납일을 지정해 귀속월이 그 달로 잡히는지 본다. */
    private function paidVehicle(string $paidOn, bool $domesticBuyer = false, string $currency = 'KRW'): Vehicle
    {
        $sm = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'settlement_type' => 'ratio']);
        $b = Buyer::create([
            'name' => 'B'.$this->n, 'is_active' => true,
            'salesman_id' => $sm->id, 'is_domestic' => $domesticBuyer,
        ]);

        $v = Vehicle::create([
            'vehicle_number' => '31사'.str_pad((string) (4000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => $currency,
            'exchange_rate' => $currency === 'KRW' ? 1 : 1400,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'purchase_price' => 8_000_000,
            'sale_price' => $currency === 'KRW' ? 12_000_000 : 9_000,
            'sale_date' => $paidOn,
        ]);

        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance',
            'amount' => $v->fresh()->sale_total_amount,
            'payment_date' => $paidOn, 'confirmed_at' => $paidOn,
        ]);

        // 자동 생성이 먼저 만들어 버리면 「수동 생성」을 시험할 수 없다 — 지우고 시작한다.
        Settlement::where('vehicle_id', $v->id)->forceDelete();

        return $v->fresh();
    }

    private function createManually(Vehicle $v): Settlement
    {
        Volt::actingAs($this->finance())->test('erp.settlements.index')
            ->set('vehicle_id', $v->id)
            ->set('salesman_id', $v->salesman_id)
            ->set('settlement_type', 'ratio')
            ->set('settlement_ratio', 50)
            ->set('settlement_status', 'pending')
            ->call('save');

        return Settlement::where('vehicle_id', $v->id)->firstOrFail();
    }

    /** 🚨 이번 결함 그 자체 — 귀속월이 비어 월배치에 안 잡히던 것. */
    public function test_a_manually_created_settlement_gets_an_attribution_month(): void
    {
        $v = $this->paidVehicle(now()->subDays(3)->toDateString());

        $s = $this->createManually($v);

        $this->assertNotNull($s->attributed_month,
            '손으로 만든 정산의 귀속월이 비었다 — 월배치에 영영 안 잡힌다');
        $this->assertSame(
            substr($v->settlementAttributionMonth(), 0, 7),
            substr((string) $s->attributed_month, 0, 7),
            '자동 생성과 다른 달로 잡혔다 — 두 경로가 같은 규칙을 써야 한다'
        );
    }

    /** 🏠 내수 바이어면 내수로 박제된다 — 안 찍히면 수출 공식으로 굳는다. */
    public function test_a_manually_created_domestic_settlement_is_stamped_domestic(): void
    {
        $v = $this->paidVehicle(now()->subDays(3)->toDateString(), domesticBuyer: true);

        $s = $this->createManually($v);

        $this->assertTrue((bool) $s->is_domestic,
            '내수 바이어 차인데 수출로 박제됐다 — 부가세마진이 붙어 회사이익이 부푼다');
    }

    /**
     * 🚫 **통화가 원화가 아니면 내수로 안 찍는다** — 외화 금액을 원화로 오인해 계산하는 걸 막는 규칙.
     * (2026-09-16 에 저장 차단은 걷어냈지만 이 판정은 그대로다.)
     */
    public function test_a_foreign_currency_domestic_buyer_is_not_stamped_domestic(): void
    {
        // ⚠️ 외화는 운임 게이트가 인코텀즈를 요구한다 — FOB 로 통과시켜야 정산이 만들어진다.
        //    (안 하면 게이트에 막혀 「정산 없음」이 되고, 이 테스트가 무엇도 검사하지 않는다.)
        $v = $this->paidVehicle(now()->subDays(3)->toDateString(), domesticBuyer: true, currency: 'EUR');
        $v->update(['incoterms' => 'FOB']);
        Settlement::where('vehicle_id', $v->id)->forceDelete();

        $s = $this->createManually($v->fresh());

        $this->assertFalse((bool) $s->is_domestic,
            '외화 차가 내수로 박제됐다 — 9,000 EUR 를 9,000원으로 읽는다');
    }

    /**
     * ⚠️ **편집은 귀속월을 안 건드린다.**
     * 저장할 때마다 조용히 움직이면 이미 지급된 배치의 구성이 바뀐다(§8 #65 ①).
     */
    public function test_editing_an_existing_settlement_does_not_move_its_month(): void
    {
        $v = $this->paidVehicle(now()->subDays(3)->toDateString());
        $s = $this->createManually($v);
        $month = (string) $s->attributed_month;

        Settlement::where('id', $s->id)->update(['attributed_month' => '2026-01-01']);

        Volt::actingAs($this->finance())->test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('note', '메모만 고친다')
            ->call('save');

        $this->assertSame('2026-01', substr((string) $s->fresh()->attributed_month, 0, 7),
            '메모만 고쳤는데 귀속월이 움직였다');
        $this->assertNotSame('', $month);
    }

    /**
     * 🔒 **규칙을 옮겨 적지 못하게** — 완납월이 마감된 달이면 현재 열린 달로 넘기는 규칙이
     * 그 함수 안에 있다. 「이번 달」을 직접 넣으면 그 규칙이 통째로 빠진다.
     */
    public function test_the_form_uses_the_shared_attribution_rule(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/erp/settlements/index.blade.php'));
        $start = strpos($src, 'public function save(): void');
        $this->assertNotFalse($start);
        // ⚠️ 창을 넉넉히 — save() 안의 게이트 블록이 길어서 4,500자로는 새 코드에 안 닿았다.
        //    🚫 이 문자열을 assert 에 통째로 넘기지 말 것 — 실패 메시지가 거대해져 테스트가 멎는다(§8 #93).
        $body = substr($src, $start, 9000);

        $this->assertTrue(str_contains($body, 'settlementAttributionMonth()'),
            '신규 정산 폼이 귀속월 단일 출처를 안 쓴다');
        $this->assertTrue(str_contains($body, 'isDomesticSettlement()'),
            '신규 정산 폼이 내수 판정 단일 출처를 안 쓴다');
        $this->assertFalse(str_contains($body, "'attributed_month' => now("),
            '귀속월을 「지금」으로 직접 넣고 있다 — 마감월 이월 규칙이 빠진다');
    }
}
