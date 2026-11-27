<?php

namespace Tests\Feature;

use App\Exceptions\ClaimUsageException;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClaim;
use App\Models\VehicleClaimUsage;
use App\Services\ClaimUsage;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 클레임 사용 (jin 2026-11-20) — 인정된 클레임만큼 바이어가 대금을 덜 보냈을 때 그 차 미수를 턴다.
 *
 * 지키는 것: ①판매탭 「클레임 사용」(이 차에 쓴 합계) → 채권관리 상계 줄 1개(source=claim) + 미수 감소 + 누적금 감소
 *            ②오래된 클레임부터 · 일부 사용 · 0 으로 되돌리면 전부 복구 ③누적금 초과·미수 초과·통화 다름·바이어 없음 거절
 *            ④같은 값 다시 저장 = 아무 일도 없음 ⑤채권관리에서는 그 줄을 못 고친다 ⑥클레임을 쓴 차는 바이어·통화 변경 불가
 *            ⑦회사 부담 — 마진·지급·회사이익은 그대로 ⑧마감된 차도 미수가 있으면 패널에서 저장된다
 *            ⑨board 가 뒤늦게 클레임을 줄여도 실패하지 않고 누적금이 0 아래로 안 간다.
 */
class ClaimUsageTest extends TestCase
{
    use RefreshDatabase;

    private Buyer $buyer;

    private Salesman $sm;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($this->user);
        $this->sm = Salesman::create(['name' => '김영업', 'type' => 'freelance', 'is_active' => true]);
        $this->buyer = Buyer::create(['name' => 'CLAIM MOTORS', 'is_active' => true, 'salesman_id' => $this->sm->id]);
    }

    private function car(string $no, float $sale = 10000, string $currency = 'EUR', ?int $buyerId = -1): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => $currency, 'exchange_rate' => $currency === 'KRW' ? 1 : 1500,
            'dhl_request' => false, 'salesman_id' => $this->sm->id, 'buyer_id' => $buyerId === -1 ? $this->buyer->id : $buyerId,
            'purchase_price' => 8_000_000, 'selling_fee' => 0, 'purchase_date' => now()->subMonth()->toDateString(),
            'sale_price' => $sale, 'sale_date' => now()->subDays(5)->toDateString(), 'incoterms' => 'FOB',
        ])->fresh();
    }

    private function claim(float $amount, string $result = 'accepted', string $currency = 'EUR', int $daysAgo = 10, ?int $buyerId = null): VehicleClaim
    {
        static $n = 100;

        return VehicleClaim::create([
            'source' => 'board', 'board_claim_id' => ++$n, 'revision' => 1, 'buyer_id' => $buyerId ?? $this->buyer->id,
            'buyer_name' => 'CLAIM MOTORS', 'vehicle_number' => '00클0001', 'confirmed_amount' => $result === 'accepted' ? $amount : 0,
            'currency' => $currency, 'compensation_type' => 'credit', 'result' => $result, 'approved_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_use_creates_one_offset_row_lowers_unpaid_and_spends_oldest_claim_first(): void
    {
        $old = $this->claim(300, daysAgo: 20);
        $new = $this->claim(200, daysAgo: 5);
        $this->claim(999, 'declined');                 // 기각은 누적금이 아니다
        $this->claim(500, currency: 'USD');            // 다른 통화는 따로
        $v = $this->car('11가5001');
        $this->assertSame(500.0, ClaimUsage::balance($this->buyer->id, 'EUR'));

        ClaimUsage::setForVehicle($v, 400, $this->user->id);

        $row = ReceivableHistory::where('vehicle_id', $v->id)->sole();
        $this->assertSame('offset', $row->method);
        $this->assertTrue($row->isClaimUse());
        $this->assertSame($this->user->id, $row->collector_id);
        $this->assertEquals(400, (float) $row->amount);
        $this->assertEquals(9600, $v->fresh()->sale_unpaid_amount);
        $this->assertSame(100.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
        $this->assertSame(400.0, ClaimUsage::usedOn($v->id));
        // 오래된 300 을 다 쓰고 새 200 에서 100
        $this->assertEquals(300, (float) VehicleClaimUsage::where('vehicle_claim_id', $old->id)->sum('amount'));
        $this->assertEquals(100, (float) VehicleClaimUsage::where('vehicle_claim_id', $new->id)->sum('amount'));
        $this->assertSame([$new->id], VehicleClaim::open()->where('currency', 'EUR')->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim_used', 'auditable_id' => $v->id, 'user_id' => $this->user->id]);
        $this->assertNotSame('claim_used', ColumnLabel::action('claim_used'));   // 한글 라벨이 있다

        // 금액을 줄이면 같은 줄이 바뀐다(줄이 늘지 않는다)
        ClaimUsage::setForVehicle($v, 250, $this->user->id);
        $this->assertSame(1, ReceivableHistory::where('vehicle_id', $v->id)->count());
        $this->assertSame(250.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
        $this->assertCount(2, VehicleClaim::open()->where('currency', 'EUR')->get());

        // 0 으로 되돌리면 전부 복구
        ClaimUsage::setForVehicle($v, 0, $this->user->id);
        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count());
        $this->assertSame(0, VehicleClaimUsage::count());
        $this->assertEquals(10000, $v->fresh()->sale_unpaid_amount);
        $this->assertSame(500.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
    }

    // 🧾 바이어 창 클레임 탭의 발생·사용 원장 (jin 2026-11-27 «적립금탭처럼 월별로») — 남은 금액 · 다음에 쓰일 클레임 · 달마다 묶은 내역
    public function test_buyer_claim_tab_lists_accruals_and_uses_by_month_with_running_balance(): void
    {
        $old = $this->claim(300, daysAgo: 40);                 // 지난달 발생
        $new = $this->claim(500, daysAgo: 3);                  // 이번 달 발생
        $this->claim(999, 'declined', daysAgo: 2);             // 기각 — 원장에는 안 들어간다
        $usd = $this->claim(200, currency: 'USD', daysAgo: 5); // 다른 통화
        $car = $this->car('00클7001');

        ClaimUsage::setForVehicle($car, 450, $this->user->id);   // 오래된 300 을 다 쓰고 새 클레임에서 150

        $lg = ClaimUsage::ledger($this->buyer->id);
        $this->assertSame(['EUR' => 350.0, 'USD' => 200.0], $lg['balances'], '남은 금액 = ClaimUsage::balance 와 같다');
        $this->assertSame(ClaimUsage::balance($this->buyer->id, 'EUR'), $lg['balances']['EUR']);
        $this->assertSame(350.0, $lg['next']['EUR']['remaining'], '다음에 쓰일 클레임 = 돈이 남은 가장 오래된 것');
        $this->assertTrue($lg['multi']);

        $rows = collect($lg['months'])->flatten(1);
        $this->assertCount(4, $rows, '발생 3(EUR 2 + USD 1) + 사용 1 — 기각은 없다');
        $use = $rows->firstWhere('kind', 'out');
        $this->assertSame([-450.0, 350.0, '00클7001', $car->id], [$use['amount'], $use['balance'], $use['vehicle_number'], $use['vehicle_id']], '사용은 한 줄 · 그 시점 남은 금액');
        $this->assertSame([300.0, 150.0], array_column($use['parts'], 'amount'), '어느 클레임에서 얼마씩 — 오래된 것부터');
        $this->assertSame([0.0, 350.0], array_column($use['parts'], 'left'));
        $this->assertSame($this->user->name, $use['by']);
        $oldRow = $rows->firstWhere('key', 'c'.$old->id);
        $this->assertSame([300.0, 300.0, 0.0], [$oldRow['amount'], $oldRow['used'], $oldRow['left']]);
        $this->assertSame([now()->format('Y-m'), now()->subDays(40)->format('Y-m')], array_values(array_unique(array_keys($lg['months']))), '달마다 묶고 최근 달이 먼저');

        // 화면 — 남은 금액 · 다음 클레임 · 원장 · 차량 열기 링크. 클레임 목록은 그대로 남는다.
        $html = Volt::test('erp.buyers.index')->call('openEdit', $this->buyer->id)->html();
        $this->assertStringContainsString('data-claim-balances', $html);
        $this->assertMatchesRegularExpression('/data-claim-balance="EUR"[\s\S]{0,900}350\.00/u', $html);
        $this->assertStringContainsString('data-claim-next', $html);
        $this->assertSame(1, substr_count($html, 'data-claim-ledger-row="out"'));
        $this->assertSame(3, substr_count($html, 'data-claim-ledger-row="in"'));
        $this->assertStringContainsString('openVehicle='.$car->id, $html);
        $this->assertStringContainsString('00클7001', $html);

        // 사용을 0 으로 되돌리면 사용 줄이 사라지고 남은 금액이 돌아온다
        ClaimUsage::setForVehicle($car->fresh(), 0, $this->user->id);
        $back = ClaimUsage::ledger($this->buyer->id);
        $this->assertSame(800.0, $back['balances']['EUR']);
        $this->assertNull(collect($back['months'])->flatten(1)->firstWhere('kind', 'out'));

        // 클레임이 없는 바이어 — 원장 블록이 없다
        $none = Buyer::create(['name' => 'NO CLAIM', 'is_active' => true, 'salesman_id' => $this->sm->id]);
        $this->assertSame(['balances' => [], 'next' => [], 'months' => [], 'multi' => false], ClaimUsage::ledger($none->id));
        $this->assertStringNotContainsString('data-claim-ledger', Volt::test('erp.buyers.index')->call('openEdit', $none->id)->html());
    }

    public function test_same_value_is_a_strict_noop(): void
    {
        $this->claim(300);
        $v = $this->car('11가5002');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);
        $row = ReceivableHistory::where('vehicle_id', $v->id)->sole();
        $unused = $this->car('11가5003');
        $audits = AuditLog::count();
        $usageIds = VehicleClaimUsage::pluck('id')->all();

        ClaimUsage::setForVehicle($v, 300.00, $this->user->id);
        ClaimUsage::setForVehicle($unused, 0, $this->user->id);   // 안 쓴 차에 0

        $this->assertSame($audits, AuditLog::count());
        $this->assertSame($usageIds, VehicleClaimUsage::pluck('id')->all());
        $this->assertEquals($row->updated_at, $row->fresh()->updated_at);
    }

    public function test_rejects_over_balance_over_unpaid_wrong_currency_and_no_buyer(): void
    {
        $this->claim(300);
        $cases = [
            'balance' => [$this->car('11가5010'), 301],                                  // 누적금 300 초과
            'unpaid' => [$this->car('11가5011', 200), 250],                              // 그 차 미수 200 초과
            'currency' => [$this->car('11가5012', 5000, 'USD'), 100],                    // USD 클레임 없음
            'no_buyer' => [$this->car('11가5013', 5000, 'EUR', null), 100],
        ];
        foreach ($cases as $name => [$v, $amount]) {
            try {
                ClaimUsage::setForVehicle($v, $amount, $this->user->id);
                $this->fail("{$name}: 거절돼야 한다");
            } catch (ClaimUsageException) {
                $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count(), $name);
            }
        }
        $this->assertSame(0, VehicleClaimUsage::count());
        $this->assertSame(300.0, ClaimUsage::balance($this->buyer->id, 'EUR'));

        // 두 차가 같은 누적금을 나눠 쓰면 합이 누적금을 못 넘는다
        ClaimUsage::setForVehicle($cases['balance'][0], 200, $this->user->id);
        $this->expectException(ClaimUsageException::class);
        ClaimUsage::setForVehicle($this->car('11가5014'), 101, $this->user->id);
    }

    public function test_claim_row_cannot_be_edited_or_deleted_outside_the_service(): void
    {
        $this->claim(300);
        $v = $this->car('11가5020');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);
        $row = ReceivableHistory::where('vehicle_id', $v->id)->sole();

        foreach ([fn () => $row->update(['amount' => 1]), fn () => $row->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('클레임 줄은 서비스 밖에서 못 고친다');
            } catch (ClaimUsageException) {
            }
        }
        $this->assertEquals(300, (float) $row->fresh()->amount);

        // 채권관리 화면의 삭제도 막힌다
        Volt::test('erp.receivables.index')->call('deleteHistory', $row->id);
        $this->assertNotNull($row->fresh());
        $this->assertSame(0.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
    }

    public function test_vehicle_with_claim_use_cannot_change_buyer_or_currency(): void
    {
        $this->claim(300);
        $v = $this->car('11가5030');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);
        $other = Buyer::create(['name' => 'OTHER', 'is_active' => true]);

        try {
            $v->update(['buyer_id' => $other->id]);
            $this->fail('바이어 변경이 막혀야 한다');
        } catch (ClaimUsageException) {
        }
        $this->assertSame($this->buyer->id, $v->fresh()->buyer_id);

        // 0 으로 되돌린 뒤에는 바꿀 수 있다
        ClaimUsage::setForVehicle($v->fresh(), 0, $this->user->id);
        $v->fresh()->update(['buyer_id' => $other->id]);
        $this->assertSame($other->id, $v->fresh()->buyer_id);
    }

    public function test_company_bears_it_margin_payout_and_company_net_do_not_move(): void
    {
        $this->claim(400);
        $v = $this->car('11가5040', 10000);
        // 바이어가 400 덜 보냈다 — 9,600 만 입금·확정
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 9600, 'payment_date' => now()->subDay()->toDateString(),
            'exchange_rate' => 1500, 'confirmed_at' => now()]);
        $this->assertEquals(400, $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0, Settlement::where('vehicle_id', $v->id)->count());   // 미수가 남아 정산이 아직 없다

        ClaimUsage::setForVehicle($v->fresh(), 400, $this->user->id);

        $v = $v->fresh();
        $this->assertEquals(0, $v->sale_unpaid_amount);
        $s = Settlement::where('vehicle_id', $v->id)->sole();                      // 완납이 되어 정산이 한 번 만들어진다
        // 상계 줄은 판매환율로 「받은 것」 — 실입금 원화 = 10,000 × 1,500, 정산환율 = 판매환율
        $this->assertSame(15_000_000, $v->sale_received_krw_accumulated);
        $this->assertEquals(1500.0, $s->settlementRate());
        $before = [$s->total_margin, $s->actual_payout, $s->company_net];

        // 다시 저장해도 정산은 하나, 숫자도 그대로
        ClaimUsage::setForVehicle($v, 400, $this->user->id);
        $s = Settlement::where('vehicle_id', $v->id)->sole();
        $this->assertSame($before, [$s->total_margin, $s->actual_payout, $s->company_net]);
    }

    public function test_board_lowering_a_used_claim_never_fails_and_balance_floors_at_zero(): void
    {
        $c = $this->claim(300);
        $this->claim(100, daysAgo: 2);
        $v = $this->car('11가5050');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);

        $c->update(['confirmed_amount' => 200, 'revision' => 2]);   // board 재결재로 300 → 200 (이미 300 썼다)
        $this->assertSame(100.0, ClaimUsage::balance($this->buyer->id, 'EUR'));   // 줄어든 건은 0 으로 치고 다른 건 100 만
        $c->update(['confirmed_amount' => 0, 'result' => 'declined', 'revision' => 3]);
        $this->assertSame(100.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
        $this->assertSame(1, VehicleClaim::open()->count());
        // 이미 쓴 것은 그대로 남는다(사람이 판매탭에서 줄인다)
        $this->assertSame(300.0, ClaimUsage::usedOn($v->id));
    }

    public function test_deleting_the_vehicle_releases_the_claim(): void
    {
        $this->claim(300);
        $v = $this->car('11가5060');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);

        DB::statement('PRAGMA foreign_keys = ON');
        DB::table('vehicles')->where('id', $v->id)->delete();   // DB cascade — 모델 이벤트 없음

        $this->assertSame(0, VehicleClaimUsage::count());
        $this->assertSame(300.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
    }

    /** 판매탭에서 실제로 저장되나 — 마지막 한 걸음(DB)까지 본다. 2차 마감된 차도 미수가 있으면 쓴다(jin 2026-11-20). */
    public function test_sale_tab_saves_claim_use_even_on_a_closed_settlement_vehicle(): void
    {
        $this->claim(400);
        $v = $this->car('11가5070', 10000);
        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'amount' => 8_000_000, 'payment_date' => now()->subDays(20)->toDateString(), 'confirmed_at' => now()->subDays(20)]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 9600, 'payment_date' => now()->subDays(3)->toDateString(), 'exchange_rate' => 1500, 'confirmed_at' => now()->subDays(3)]);
        Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $this->sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now()->subDays(2), 'secondary_status' => 'closed', 'secondary_closed_at' => now()->subDay(),
            'attributed_month' => now()->startOfMonth()->toDateString()]);
        $v->refresh()->refreshCaches();
        $this->assertTrue($v->fresh()->hasClosedSecondarySettlement());
        $this->assertEquals(400, $v->fresh()->sale_unpaid_amount);

        $panel = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $panel->assertSeeHtml('data-claim-balance')->assertSeeHtml('data-claim-use')->assertSet('claim_used_str', '');
        $panel->set('claim_used_str', '400')->call('save')->assertHasNoErrors();

        $row = ReceivableHistory::where('vehicle_id', $v->id)->where('source', ClaimUsage::SOURCE)->sole();
        $this->assertEquals(400, (float) $row->amount);
        $this->assertSame($this->user->id, $row->collector_id);
        $this->assertEquals(0, $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count());

        // 다시 열면 칸에 쓴 합계가 들어 있고, 그대로 저장해도 줄이 늘지 않는다
        $again = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->assertSet('claim_used_str', '400');
        $again->call('save');
        $this->assertSame(1, ReceivableHistory::where('vehicle_id', $v->id)->where('source', ClaimUsage::SOURCE)->count());
        $this->assertSame(1, AuditLog::where('action', 'claim_used')->count());
    }

    public function test_sale_tab_rejects_more_than_the_balance_and_saves_nothing(): void
    {
        $this->claim(300);
        $v = $this->car('11가5071', 10000);

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('claim_used_str', '500')->set('port_of_loading', 'INCHEON')->call('save');

        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count());
        $this->assertSame(300.0, ClaimUsage::balance($this->buyer->id, 'EUR'));
        $this->assertNotSame('INCHEON', $v->fresh()->port_of_loading);   // 같은 저장의 다른 칸도 되돌아간다(한 트랜잭션)
    }

    /** 누적금도 쓴 것도 없는 차의 판매탭에는 칸이 안 보인다. */
    public function test_sale_tab_hides_the_fields_when_there_is_nothing_to_use(): void
    {
        $v = $this->car('11가5072');
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->assertDontSeeHtml('data-claim-use');
    }

    public function test_buyer_list_badge_and_chip_count_open_claims_and_vanish_when_used_up(): void
    {
        $this->claim(300);
        $this->claim(100, daysAgo: 3);
        $this->claim(999, 'declined');
        $quiet = Buyer::create(['name' => 'QUIET TRADING', 'is_active' => true]);
        $this->claim(50, 'declined', buyerId: $quiet->id);
        $v = $this->car('11가5080');

        $list = Volt::test('erp.buyers.index');
        $list->assertSeeHtml('data-open-claim-filter')->assertSee(__('buyer.claims.badge', ['n' => 2]));
        $this->assertSame(1, $list->instance()->openClaimBuyerCount);
        $list->call('toggleOpenClaimOnly')->assertSet('openClaimOnly', true)->assertSee('CLAIM MOTORS')->assertDontSee('QUIET TRADING');

        // 하나를 다 쓰면 1건, 전부 쓰면 뱃지·칩이 사라진다
        ClaimUsage::setForVehicle($v, 300, $this->user->id);
        Volt::test('erp.buyers.index')->assertSee(__('buyer.claims.badge', ['n' => 1]));
        ClaimUsage::setForVehicle($v->fresh(), 400, $this->user->id);
        Volt::test('erp.buyers.index')->assertDontSeeHtml('data-buyer-open-claim')->assertDontSeeHtml('data-open-claim-filter')->assertSee('CLAIM MOTORS');
    }

    public function test_receivables_panel_links_to_the_vehicle_sale_tab_and_marks_the_claim_row(): void
    {
        $this->claim(300);
        $v = $this->car('11가5090');
        ClaimUsage::setForVehicle($v, 300, $this->user->id);

        $html = Volt::test('erp.receivables.index')->call('openPanel', $v->id)->html();
        $this->assertStringContainsString('data-open-vehicle-sale', $html);
        $this->assertStringContainsString('openVehicle='.$v->id, $html);
        $this->assertStringContainsString('openTab=sale', $html);
        $this->assertStringContainsString('data-claim-row', $html);
        $this->assertStringContainsString(__('receivable.claim_row_edit'), $html);

        // 그 주소로 들어가면 그 차 패널이 판매탭으로 열린다
        Livewire::withQueryParams(['openVehicle' => $v->id, 'openTab' => 'sale'])->test('erp.vehicles.index')
            ->assertSet('editingId', $v->id)->assertDispatched('switch-tab', tab: 'sale');
        Livewire::withQueryParams(['openVehicle' => $v->id, 'openTab' => 'nope'])->test('erp.vehicles.index')
            ->assertDispatched('switch-tab', tab: 'purchase');
    }
}
