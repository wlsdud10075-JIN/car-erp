<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 현금 원장 1·2단계 — 입금 기재·조회 + 토글. 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚫 적립금(`SavingsStatus`)과 다른 원장이다 — 적립금은 회사가 준 크레딧,
 *    이건 바이어가 실제로 보낸 현금. 섞이면 「그만큼만 쓴다」가 성립하지 않는다.
 *
 * ⚠️ 3단계(게이트 = 현금 없으면 판매잔금 차단)는 아직 없다. 여기서는
 *    **원장이 스스로 맞는지**(잔액·배분·회수)와 **토글·권한**만 본다.
 */
class BuyerCashLedgerTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
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
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $buyer->salesman_id,
            'buyer_id' => $buyer->id,
            'sale_price' => 10000,
            'sale_date' => now()->toDateString(),
        ]);
    }

    private function enable(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    // ── 토글 ─────────────────────────────────────────────────────

    /** 신규 기능이라 기본은 꺼져 있어야 한다 — 켜는 순간 판매잔금 입력이 제한되기 때문. */
    public function test_toggle_is_off_by_default(): void
    {
        $this->assertFalse(Setting::buyerCashEnabled());
    }

    public function test_only_super_can_flip_the_toggle(): void
    {
        // abort(403) 이 updated 훅에서 나면 Livewire 테스트 하네스가 스냅샷 오류로 감싼다 —
        //   예외 종류를 단언하지 말고 **불변식(설정이 안 바뀐다)** 을 본다.
        try {
            Volt::actingAs($this->user('admin'))->test('admin.settings')
                ->set('buyerCashEnabled', true);
        } catch (\Throwable) {
            // 기대한 거부.
        }

        $this->assertFalse(Setting::buyerCashEnabled(), 'admin 이 켜면 안 된다');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'buyer_cash_toggle_changed']);
    }

    /** 돈 흐름을 바꾸는 조작이라 누가 언제 켰는지가 사후에 반드시 필요하다. */
    public function test_flipping_the_toggle_is_audited(): void
    {
        Volt::actingAs($this->user('super'))->test('admin.settings')
            ->set('buyerCashEnabled', true);

        $this->assertTrue(Setting::buyerCashEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'buyer_cash_toggle_changed', 'new_value' => '1']);
    }

    /** 감사 화면이 영문 식별자를 그대로 찍으면 안 된다(SKILLS §8 #41). */
    public function test_audit_actions_have_korean_labels(): void
    {
        foreach ([
            'buyer_cash_toggle_changed',
            'buyer_cash_receipt_added',
            'buyer_cash_receipt_deleted',
        ] as $action) {
            $label = config("column_labels.actions.$action");
            $this->assertIsString($label, "{$action} 한글 라벨 없음");
            $this->assertMatchesRegularExpression('/[가-힣]/u', $label, "{$action} 라벨이 한글이 아니다");
        }
        $this->assertSame('바이어 현금 입금', config('column_labels.models.BuyerCashReceipt'));
        $this->assertSame('buyer_cash_receipts', ColumnLabel::resolveTable(BuyerCashReceipt::class));
    }

    // ── 탭 노출 ───────────────────────────────────────────────────

    /**
     * 토글이 꺼진 회사엔 탭이 아예 없어야 한다.
     * 🚨 **보이는데 저장이 안 되는** 상태를 만들지 말 것(SKILLS §8 #60) — 노출과 저장이 같은 출처를 본다.
     */
    public function test_cash_tab_follows_the_toggle(): void
    {
        $buyer = $this->buyer();
        $as = $this->user('admin');

        Volt::actingAs($as)->test('erp.buyers.index')->call('openEdit', $buyer->id)
            ->assertDontSee("tab==='cash'", false);

        $this->enable();

        Volt::actingAs($as)->test('erp.buyers.index')->call('openEdit', $buyer->id)
            ->assertSee("tab==='cash'", false);
    }

    /** 토글이 꺼져 있으면 저장 경로도 아무 일을 하지 않는다(화면만 막으면 우회된다). */
    public function test_recording_is_a_no_op_while_the_toggle_is_off(): void
    {
        $buyer = $this->buyer();

        Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('cash_date', '2026-09-04')
            ->set('cash_currency', 'EUR')
            ->set('cash_amount', '10000')
            ->call('addCashReceipt');

        $this->assertSame(0, BuyerCashReceipt::count());
    }

    // ── 기재 ─────────────────────────────────────────────────────

    public function test_finance_can_record_a_receipt(): void
    {
        $this->enable();
        $buyer = $this->buyer();

        Volt::actingAs($this->user('user', '재무'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('cash_date', '2026-09-04')
            ->set('cash_currency', 'EUR')
            ->set('cash_amount', '10000')
            ->set('cash_note', '전신환')
            ->call('addCashReceipt');

        $receipt = BuyerCashReceipt::sole();
        $this->assertSame($buyer->id, $receipt->buyer_id);
        $this->assertSame('EUR', $receipt->currency);
        $this->assertSame('10000.00', (string) $receipt->amount);
        $this->assertSame('2026-09-04', $receipt->received_date->format('Y-m-d'));
        $this->assertSame('전신환', $receipt->note, '메모가 저장되지 않았다');
        $this->assertNotNull($receipt->created_by, '기재자가 남아야 한다');
        $this->assertDatabaseHas('audit_logs', ['action' => 'buyer_cash_receipt_added']);
    }

    /**
     * 🚨 **입금에는 환율·원화 컬럼이 없다**(jin 2026-09-04). 원화 환산은 판매잔금 행이 담당한다 —
     *    두 곳에서 원화를 만들면 정산 환율이 갈린다. 컬럼이 생기면 이 테스트가 잡는다.
     */
    public function test_receipt_carries_no_exchange_rate(): void
    {
        $this->enable();
        $columns = Schema::getColumnListing('buyer_cash_receipts');

        $this->assertNotContains('exchange_rate', $columns);
        $this->assertNotContains('amount_krw', $columns);
    }

    /** 영업은 못 적는다 — 화면에 폼이 안 뜨는 것과 별개로 액션도 막혀야 한다(SKILLS §8 #26). */
    public function test_sales_cannot_record_a_receipt(): void
    {
        $this->enable();
        $buyer = $this->buyer();

        Volt::actingAs($this->user('user', '영업'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('cash_date', '2026-09-04')
            ->set('cash_amount', '10000')
            ->call('addCashReceipt')
            ->assertStatus(403);

        $this->assertSame(0, BuyerCashReceipt::count());
    }

    // ── 잔액 ─────────────────────────────────────────────────────

    /** 잔액 = 입금 − 배분. 캐시 컬럼이 없으므로 배분을 넣으면 즉시 따라와야 한다. */
    public function test_balance_is_receipt_minus_allocations(): void
    {
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-04', 'amount' => 10000,
        ]);

        $this->assertSame(10000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));

        $fp = $this->finalPayment($vehicle, 4000);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 4000,
        ]);

        $this->assertSame(6000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(6000.0, $receipt->fresh()->remaining_amount);
        $this->assertSame(4000.0, $receipt->fresh()->allocated_amount);
    }

    /** 통화가 다르면 서로 섞이지 않는다(확정 #3 — 같은 통화끼리만). */
    public function test_currencies_do_not_mix(): void
    {
        $buyer = $this->buyer();
        BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 1000]);
        BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'USD', 'received_date' => '2026-09-01', 'amount' => 500]);

        $this->assertSame(1000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(500.0, BuyerCashReceipt::balanceFor($buyer->id, 'USD'));
    }

    /** FIFO = 오래 받은 돈부터. 3단계 배분이 이 순서를 쓴다 — 화면 「다음에 여기서 나갑니다」와 같은 출처. */
    public function test_fifo_orders_oldest_first(): void
    {
        $buyer = $this->buyer();
        $late = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-10', 'amount' => 100]);
        $early = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 100]);

        $ids = BuyerCashReceipt::forBuyerCurrency($buyer->id, 'EUR')->fifo()->pluck('id')->all();
        $this->assertSame([$early->id, $late->id], $ids);
    }

    /** 화면 합계와 게이트가 쓰는 balanceFor 가 갈리면 안 된다 — 숫자가 두 벌이 되는 순간이다. */
    public function test_screen_total_matches_the_single_source(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 7000]);
        $fp = $this->finalPayment($vehicle, 2500);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 2500,
        ]);

        $screen = Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->get('cashBalances');

        $this->assertSame(
            BuyerCashReceipt::balanceFor($buyer->id, 'EUR'),
            round($screen['EUR']['remaining'], 2),
        );
        $this->assertSame(7000.0, round($screen['EUR']['received'], 2));
        $this->assertSame(2500.0, round($screen['EUR']['allocated'], 2));
    }

    // ── 삭제·회수 ────────────────────────────────────────────────

    public function test_unused_receipt_can_be_deleted(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $receipt = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 100]);

        Volt::actingAs($this->user('user', '재무'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->call('deleteCashReceipt', $receipt->id);

        $this->assertSame(0, BuyerCashReceipt::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'buyer_cash_receipt_deleted']);
    }

    /**
     * 🚨 배분된 입금은 못 지운다 — 지우면 cascade 로 **배분 행만** 사라지고 판매잔금은 남아
     *    미수와 현금이 어긋난다. 되돌리기는 「그 판매잔금 행을 지우는 것」이다(확정 #8).
     */
    public function test_allocated_receipt_cannot_be_deleted(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 100]);
        $fp = $this->finalPayment($vehicle, 40);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 40,
        ]);

        Volt::actingAs($this->user('user', '재무'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->call('deleteCashReceipt', $receipt->id);

        $this->assertSame(1, BuyerCashReceipt::count(), '배분된 입금이 지워졌다');
        $this->assertSame(1, BuyerCashAllocation::count());
    }

    /**
     * 회수의 구현 자체 — 판매잔금 행을 지우면 배분이 cascade 로 사라지고 현금이 복원된다.
     * 별도 되돌리기 로직을 만들지 말 것.
     */
    public function test_deleting_the_sale_balance_restores_the_cash(): void
    {
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 10000]);
        $fp = $this->finalPayment($vehicle, 4000);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 4000,
        ]);
        $this->assertSame(6000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));

        $fp->delete();

        $this->assertSame(0, BuyerCashAllocation::count(), '배분 행이 cascade 로 안 사라졌다');
        $this->assertSame(10000.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
    }

    /** 한 잔금이 두 입금에 걸칠 수 있다 — 그래서 칸 하나가 아니라 별도 테이블이다. */
    public function test_one_balance_can_span_two_receipts(): void
    {
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $a = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-01', 'amount' => 3000]);
        $b = BuyerCashReceipt::create(['buyer_id' => $buyer->id, 'currency' => 'EUR', 'received_date' => '2026-09-02', 'amount' => 7000]);
        $fp = $this->finalPayment($vehicle, 10000);

        foreach ([[$a, 3000], [$b, 7000]] as [$receipt, $amount]) {
            BuyerCashAllocation::create([
                'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
                'vehicle_id' => $vehicle->id, 'amount' => $amount,
            ]);
        }

        $this->assertSame(0.0, BuyerCashReceipt::balanceFor($buyer->id, 'EUR'));
        $this->assertSame(2, $fp->refresh()->id ? BuyerCashAllocation::where('final_payment_id', $fp->id)->count() : 0);
    }

    // ── 좁은 패널(580px)에서 보이는가 ────────────────────────────

    /**
     * 🚨 **열을 늘리면 오른쪽이 가로 스크롤 밖으로 밀려 안 보인다**(jin 2026-09-05 «메모는 안나오네?»).
     *    실제로 그랬다 — 8열 표라 메모가 화면 밖에 있었고, 사람은 그걸 「저장이 안 됐다」로 읽는다.
     *
     * ⚠️ 렌더 결과만 보는 테스트는 **가로 스크롤을 모른다** — HTML 에 있어도 화면엔 없을 수 있다.
     *    그래서 원인(열 개수)을 직접 검사한다. 사이드 패널은 `sm:w-[580px]` 고정이다.
     */
    public function test_cash_table_stays_narrow_enough_for_the_panel(): void
    {
        $this->enable();
        $buyer = $this->buyer();

        $html = Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->html();

        $marker = __('buyer.cash.col_used');
        $pos = strpos($html, $marker);
        $this->assertNotFalse($pos, '현금 표 헤더를 못 찾았다');

        $rowStart = strrpos(substr($html, 0, $pos), '<tr');
        $rowEnd = strpos($html, '</tr>', $pos);
        $headerRow = substr($html, $rowStart, $rowEnd - $rowStart);

        $this->assertLessThanOrEqual(
            5,
            substr_count($headerRow, '<th'),
            '현금 표 열이 늘었다 — 580px 패널에서 오른쪽 열이 가로 스크롤 밖으로 밀린다. '
            .'메모·기재자처럼 부수 정보는 열을 늘리지 말고 첫 칸 안에 붙이거나 호버로 넣을 것.'
        );
    }

    /** 메모는 열이 아니라 수령일 아래에 붙는다 — 그래도 목록에서 읽혀야 한다. */
    public function test_receipt_note_is_rendered(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-05', 'amount' => 10000,
            'note' => '바이어 현금 테스트 #1',
        ]);

        $html = Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->html();

        $this->assertStringContainsString('바이어 현금 테스트 #1', $html, '메모가 목록에 안 나온다');
    }

    /** 잘려도 호버로 전문이 보여야 한다(적립금 탭과 같은 방식 — jin 2026-09-05). */
    public function test_allocations_are_readable_on_hover(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000,
        ]);
        $fp = $this->finalPayment($vehicle, 4000);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 4000,
        ]);

        $html = Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->html();

        $this->assertStringContainsString(
            'title="'.$vehicle->vehicle_number.' 4,000.00"',
            $html,
            '쓴 내역에 호버(title)가 없다 — 좁은 패널에서 잘리면 읽을 방법이 사라진다'
        );
    }

    // ── 화면에 번역 안 된 키가 새지 않는다 (SKILLS §8 #73) ─────────

    public function test_no_untranslated_key_leaks_into_the_cash_tab(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        $receipt = BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 5000, 'note' => '메모',
        ]);
        $fp = $this->finalPayment($vehicle, 1000);
        BuyerCashAllocation::create([
            'receipt_id' => $receipt->id, 'final_payment_id' => $fp->id,
            'vehicle_id' => $vehicle->id, 'amount' => 1000,
        ]);

        $html = Volt::actingAs($this->user('user', '재무'))->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->html();

        // buyer.cash.foo 처럼 점 두 개짜리 토큰이 그대로 찍히면 lang 키가 빠진 것이다.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:buyer|common|feature_settings)\.[a-z_]+\.[a-z_]+\b/',
            $html,
            '번역 안 된 키가 화면에 그대로 찍힌다',
        );
    }

    private function finalPayment(Vehicle $vehicle, float $amount): FinalPayment
    {
        return FinalPayment::create([
            'vehicle_id' => $vehicle->id,
            'type' => 'balance',
            'amount' => $amount,
            'payment_date' => '2026-09-04',
            'confirmed_at' => now(),
        ]);
    }
}
