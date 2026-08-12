<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\ExportLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🏷️ 차량관리 board 요청 뱃지 pill 필터 (jin 2026-08-12).
 *
 * 운항 pill 과 같은 형태의 **직교 축**이다 — 진행상태를 안 건드리고 겹쳐 걸 수 있다.
 * 운항과 두 가지가 다르다: ①**다중 선택**(입금요청+매입잔금을 같이 훑는 게 실제 흐름)
 * ②**요청이 있는 종류만** pill 이 뜬다(카운트 0 이면 아예 안 보인다).
 *
 * 🛑 함께 검증 — 구 `purchase_payment` **수신 중단**(2026-08-12). 이미 열린 행은 계속 그려져야 한다.
 */
class BoardRequestBadgeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicle(string $number): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $number,
            'salesman_id' => Salesman::create(['name' => 'S'.Str::random(4), 'is_active' => true])->id,
            'purchase_date' => '2026-08-01',
            'purchase_price' => 10_000_000,
        ]);
    }

    private function signal(Vehicle $v, string $type): BoardRequest
    {
        return BoardRequest::create([
            'batch_id' => (string) Str::uuid(),
            'type' => $type,
            'vehicle_id' => $v->id,
            'status' => BoardRequest::STATUS_OPEN,
            'requested_by_email' => 'sales@example.com',
            'requested_at' => now(),
        ]);
    }

    /** 🚨 고른 뱃지가 달린 차만 남는다 — 이 기능의 존재 이유. */
    public function test_filter_keeps_only_vehicles_with_that_badge(): void
    {
        $this->actingAs($this->admin());

        $withBalance = $this->vehicle('11가1111');
        $withDeposit = $this->vehicle('22나2222');
        $plain = $this->vehicle('33다3333');
        $this->signal($withBalance, BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->signal($withDeposit, BoardRequest::TYPE_PURCHASE_DEPOSIT);

        Volt::test('erp.vehicles.index')
            ->set('dateType', 'all')
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)
            ->assertSee('11가1111')
            ->assertDontSee('22나2222')
            ->assertDontSee('33다3333');
    }

    /** 다중 = OR. 고른 것 중 **하나라도** 열려 있으면 남는다. */
    public function test_multiple_selection_is_or(): void
    {
        $this->actingAs($this->admin());

        $balance = $this->vehicle('11가1111');
        $deposit = $this->vehicle('22나2222');
        $plain = $this->vehicle('33다3333');
        $this->signal($balance, BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->signal($deposit, BoardRequest::TYPE_PURCHASE_DEPOSIT);

        Volt::test('erp.vehicles.index')
            ->set('dateType', 'all')
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_DEPOSIT)
            ->assertSet('boardFilters', [BoardRequest::TYPE_PURCHASE_BALANCE, BoardRequest::TYPE_PURCHASE_DEPOSIT])
            ->assertSee('11가1111')
            ->assertSee('22나2222')
            ->assertDontSee('33다3333');
    }

    /** 같은 걸 다시 누르면 해제된다(운항 pill 과 같은 규칙). */
    public function test_pressing_again_clears_it(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle('11가1111');
        $this->signal($v, BoardRequest::TYPE_PURCHASE_BALANCE);

        Volt::test('erp.vehicles.index')
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)
            ->assertSet('boardFilters', [BoardRequest::TYPE_PURCHASE_BALANCE])
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)
            ->assertSet('boardFilters', []);
    }

    /**
     * 🚨 카운트는 **board 필터를 뺀** 나머지 조건 기준이다.
     * 자기 자신으로 자기를 세면 누를수록 숫자가 줄어 pill 을 못 쓴다(운항 pill 과 같은 함정).
     */
    public function test_counts_ignore_the_board_filter_itself(): void
    {
        $this->actingAs($this->admin());

        $this->signal($this->vehicle('11가1111'), BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->signal($this->vehicle('22나2222'), BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->signal($this->vehicle('33다3333'), BoardRequest::TYPE_PURCHASE_DEPOSIT);

        $c = Volt::test('erp.vehicles.index')->set('dateType', 'all');

        $before = $c->get('boardRequestCounts');
        $this->assertSame(2, $before[BoardRequest::TYPE_PURCHASE_BALANCE]);
        $this->assertSame(1, $before[BoardRequest::TYPE_PURCHASE_DEPOSIT]);

        // 매입잔금만 보게 걸어도 계약금 카운트가 살아 있어야 다음 pill 을 누를 수 있다.
        $after = $c->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)->get('boardRequestCounts');
        $this->assertSame($before, $after, 'board 필터가 자기 카운트를 깎았다');
    }

    /** 한 차에 신호가 둘이어도 type 마다 **1대**로 센다(뱃지 2개 = 차 2대가 아니다). */
    public function test_a_vehicle_with_two_signals_counts_once_per_type(): void
    {
        $this->actingAs($this->admin());

        $v = $this->vehicle('11가1111');
        $this->signal($v, BoardRequest::TYPE_PURCHASE_PAYMENT);   // 구 신호
        $this->signal($v, BoardRequest::TYPE_PURCHASE_BALANCE);   // 신 신호 — 같은 차

        $counts = Volt::test('erp.vehicles.index')->set('dateType', 'all')->get('boardRequestCounts');

        $this->assertSame(1, $counts[BoardRequest::TYPE_PURCHASE_PAYMENT]);
        $this->assertSame(1, $counts[BoardRequest::TYPE_PURCHASE_BALANCE]);
    }

    /** 요청이 0 건인 종류는 카운트 키가 없다 → pill 이 안 뜬다(빈 버튼을 늘리지 않는다). */
    public function test_types_without_open_requests_produce_no_pill(): void
    {
        $this->actingAs($this->admin());
        $this->signal($this->vehicle('11가1111'), BoardRequest::TYPE_PURCHASE_BALANCE);

        $counts = Volt::test('erp.vehicles.index')->set('dateType', 'all')->get('boardRequestCounts');

        $this->assertArrayHasKey(BoardRequest::TYPE_PURCHASE_BALANCE, $counts);
        $this->assertArrayNotHasKey(BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, $counts);
        $this->assertArrayNotHasKey(BoardRequest::TYPE_PURCHASE_DEPOSIT, $counts);
    }

    /** 닫힌(done) 신호는 세지도, 거르지도 않는다 — 처리한 건 목록에서 빠져야 한다. */
    public function test_done_signals_are_ignored(): void
    {
        $this->actingAs($this->admin());

        $v = $this->vehicle('11가1111');
        $this->signal($v, BoardRequest::TYPE_PURCHASE_BALANCE)->update(['status' => BoardRequest::STATUS_DONE]);

        $c = Volt::test('erp.vehicles.index')->set('dateType', 'all');
        $this->assertSame([], $c->get('boardRequestCounts'));
        $c->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_BALANCE)->assertDontSee('11가1111');
    }

    /** ⚠️ `#[Url]` 배열이라 주소창으로 아무 값이나 들어온다 — 화이트리스트 밖은 무시한다. */
    public function test_unknown_type_is_ignored(): void
    {
        $this->actingAs($this->admin());
        $this->signal($this->vehicle('11가1111'), BoardRequest::TYPE_PURCHASE_BALANCE);

        Volt::test('erp.vehicles.index')
            ->call('toggleBoardRequest', 'nonsense')
            ->assertSet('boardFilters', []);

        // 주소창으로 직접 주입돼도 목록이 안 깎인다(정제 후 빈 배열 = 필터 없음).
        Volt::test('erp.vehicles.index', ['boardFilters' => ['nonsense']])
            ->set('dateType', 'all')
            ->assertSee('11가1111');
    }

    /**
     * 🔗 **엑셀 내보내기 정합** — 안 넘기면 화면은 1대인데 엑셀은 전체가 나온다.
     * 화면 scope 와 export 결과의 차량 집합을 직접 대조한다(§9 export 정합 패턴).
     */
    public function test_export_mirrors_the_screen_filter(): void
    {
        $this->actingAs($this->admin());

        $hit = $this->vehicle('11가1111');
        $miss = $this->vehicle('22나2222');
        $this->signal($hit, BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->signal($miss, BoardRequest::TYPE_PURCHASE_DEPOSIT);

        $ids = Vehicle::whereHas('boardRequests', fn ($q) => $q->open()
            ->where('type', BoardRequest::TYPE_PURCHASE_BALANCE))->pluck('id')->all();

        $res = $this->get(route('erp.vehicles.export', [
            'scope' => 'current', 'dateType' => 'all', 'cols' => 'vehicle_number',
            'brq' => BoardRequest::TYPE_PURCHASE_BALANCE,
        ]));
        $res->assertOk();

        $log = ExportLog::latest('id')->first();
        $this->assertSame(count($ids), $log->row_count, '엑셀 행 수가 화면 필터 결과와 다르다');
        $this->assertSame(BoardRequest::TYPE_PURCHASE_BALANCE, $log->filters['brq'] ?? null);
    }

    /** 범위 「전체」면 board 필터도 무시된다 — 다른 필터와 같은 규칙. */
    public function test_export_scope_all_ignores_the_board_filter(): void
    {
        $this->actingAs($this->admin());
        $this->signal($this->vehicle('11가1111'), BoardRequest::TYPE_PURCHASE_BALANCE);
        $this->vehicle('22나2222');

        $this->get(route('erp.vehicles.export', [
            'scope' => 'all', 'cols' => 'vehicle_number', 'brq' => BoardRequest::TYPE_PURCHASE_BALANCE,
        ]))->assertOk();

        $this->assertSame(2, ExportLog::latest('id')->first()->row_count);
    }

    // ── 구 purchase_payment 수신 중단 (2026-08-12) ────────────────────────

    /** 🛑 `raise()` 가 구 type 을 더는 안 만든다. */
    public function test_retired_type_is_no_longer_accepted(): void
    {
        $v = $this->vehicle('11가1111');

        $this->assertNotContains(BoardRequest::TYPE_PURCHASE_PAYMENT, BoardRequest::TYPES);
        $this->assertNull(BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@example.com'));
        $this->assertSame(0, BoardRequest::count());
    }

    /**
     * 🗂️ 그래도 **이미 열려 있는 행은 그대로 그려진다** — 상수와 `TYPE_META` 를 남긴 이유.
     * 지웠으면 운영에 남은 2건이 라벨 없는 유령 뱃지가 됐을 것이다.
     */
    public function test_existing_retired_rows_still_render_and_can_be_filtered(): void
    {
        $this->actingAs($this->admin());

        $v = $this->vehicle('11가1111');
        $this->vehicle('22나2222');
        $this->signal($v, BoardRequest::TYPE_PURCHASE_PAYMENT);

        $this->assertArrayHasKey(BoardRequest::TYPE_PURCHASE_PAYMENT, BoardRequest::TYPE_META);
        $this->assertNotSame('', trim(__(BoardRequest::TYPE_META[BoardRequest::TYPE_PURCHASE_PAYMENT]['badge'])));

        Volt::test('erp.vehicles.index')
            ->set('dateType', 'all')
            ->call('toggleBoardRequest', BoardRequest::TYPE_PURCHASE_PAYMENT)
            ->assertSee('11가1111')
            ->assertDontSee('22나2222');
    }
}
