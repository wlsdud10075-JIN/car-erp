<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 채권관리 「임포트 정리만」 드롭박스 + 「받았음」 청산 (jin 2026-09-10).
 *
 * 2026-08-28 ssancarerp 소급 적재는 미납을 회수이력 「기타」로 적어 **미수를 0 으로 눕혔다**
 * (jin 확정 규칙, 🚫손실처리 아님). 그래서 화면상 완납인데 실제로는 받아야 할 돈이 남은 차가
 * 317 대 생겼고, 그중 45 대는 금액이 **운임비와 정확히 일치**한다(운임만 못 받은 선적 묶음).
 *
 * jin 결정 — **미수는 되살리지 않는다.** 되살리면 v5 규칙(「출고일 + 완납」)이 깨져 실측 77 대가
 * 거래완료에서 판매중으로 떨어지고, 2차 마감된 228 건은 살려도 `FinalPayment::creating` 가드에
 * 막혀 받은 돈을 넣을 수조차 없다. ⇒ **목록으로만 본다.**
 *
 * 이 테스트가 지키는 것:
 *   ① 미수가 0 이어도 잡힌다 (그게 이 탭의 존재 이유다)
 *   ② 🚨 **사람이 넣은 「기타」는 안 잡힌다** — 오탐이 나는 목록은 곧 무시당한다
 *   ③ 대상이 없으면 선택지가 아예 안 뜬다 (다른 두 회사 · 다 정리한 뒤)
 *   ④ 왜 여기 있는지 + **얼마인지** 화면이 말한다 (미수 컬럼이 전부 0 이라 KPI 엔 한 푼도 안 잡힌다)
 *   ⑤ 「받았음」이 현금 기록과 줄 차감을 한 번에 하고 **진행상태를 안 흔든다**
 */
class ReceivableImportClearedFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '55가'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'salesman_id' => $s->id,
            'buyer_id' => $b->id,
            'currency' => 'EUR',
            'exchange_rate' => 1500,
            'purchase_price' => 1_000_000,
            'sale_price' => 5_000,
            'transport_fee' => 1_300,
            'sale_date' => now()->subMonths(3)->toDateString(),
        ], $attrs));
    }

    /**
     * 운영 모양 그대로 — **물건값은 받고 운임비만 못 받은 뒤** 그 차액을 기타로 적어 미수를 0 으로 눕혔다.
     * ⚠️ 미수가 0 이어야 한다 — 드롭박스를 고르면 탭이 「완납」으로 옮겨가기 때문(updatedCancelFilter).
     *    실제 317 건도 적재가 전부 0 으로 맞춰 놨다.
     */
    private function clearedVehicle(float $freight = 1_300): Vehicle
    {
        $v = $this->vehicle(['transport_fee' => $freight]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5_000,
            'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now()->subMonths(2),
        ]);
        $this->importCleared($v, $freight);
        $v->refresh();
        $this->assertLessThanOrEqual(0, (float) $v->sale_unpaid_amount, '전제: 미수가 0 이어야 한다');

        return $v;
    }

    /** 적재기가 남긴 「미수 정리」 행과 똑같은 모양. */
    private function importCleared(Vehicle $v, float $amount): ReceivableHistory
    {
        return ReceivableHistory::create([
            'vehicle_id' => $v->id,
            'method' => 'other',
            'amount' => $amount,
            'collected_at' => now()->subMonth()->toDateString(),
            'note' => ReceivableHistory::IMPORT_CLEARED_NOTE_PREFIX.' — 정산 종결분 미수 정리',
        ]);
    }

    /** 드롭박스를 고르면 탭도 「완납」으로 같이 옮겨진다(updatedCancelFilter) — 실제 경로 그대로 탄다. */
    private function screen(bool $filtered = false)
    {
        $c = Volt::actingAs($this->admin())->test('erp.receivables.index');

        return $filtered ? $c->set('cancelFilter', 'import_cleared') : $c;
    }

    /**
     * ① 미수가 0 이어도 잡힌다 — 완납으로 보이는 게 이 부류의 정의다.
     *    실제 운영 모양 그대로 만든다: **물건값은 받고 운임비만 못 받은** 뒤 그 차액을 기타로 적었다
     *    (실측 45 대가 이 형태 — 금액이 운임비와 정확히 일치한다).
     */
    public function test_a_vehicle_whose_unpaid_was_zeroed_by_the_import_is_listed(): void
    {
        $v = $this->vehicle();
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5_000,
            'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now()->subMonths(2),
        ]);
        $this->importCleared($v, 1_300);   // = 운임비. 받은 게 아니라 「못 받은 것」이다.
        $v->refresh();

        $this->assertLessThanOrEqual(0, (float) $v->sale_unpaid_amount,
            '전제: 미수가 0 이어야 한다 (그래서 다른 탭에서는 안 보인다)');

        $this->assertStringContainsString($v->vehicle_number, $this->screen(true)->html(),
            '임포트 정리분인데 탭에 안 뜬다');
    }

    /**
     * ② 🚨 사람이 실제 회수로 넣은 「기타」는 안 잡힌다.
     *    method 만으로 고르면 진짜 받은 돈까지 「안 받은 돈」으로 떠서 목록을 못 믿게 된다.
     */
    public function test_a_manual_other_collection_is_not_swept_in(): void
    {
        $v = $this->vehicle();
        ReceivableHistory::create([
            'vehicle_id' => $v->id,
            'method' => 'other',
            'amount' => 1_300,
            'collected_at' => now()->toDateString(),
            'note' => '현장에서 현금 수령',
        ]);

        $this->assertStringNotContainsString($v->vehicle_number, $this->screen(true)->html(),
            '사람이 넣은 기타 회수가 임포트 정리분으로 잡혔다');
    }

    /** ③ 대상이 없으면 탭 자체가 안 뜬다 — 회사별 분기를 코드에 박지 않기 위한 장치. */
    public function test_the_tab_is_hidden_when_there_is_nothing_to_show(): void
    {
        $this->vehicle();   // 표식 없는 평범한 차량만

        $this->assertStringNotContainsString(__('receivable.cancel.import_cleared'), $this->screen()->html(),
            '대상이 0 인데 선택지가 떴다 — 다 정리하면 저절로 사라져야 한다');
    }

    /** 대상이 생기면 선택지가 뜨고, 고르면 **통화별 합계**가 나온다 — 청구하려면 그 숫자가 필요하다. */
    public function test_the_option_appears_and_shows_totals_per_currency(): void
    {
        $this->clearedVehicle(1_300);
        $this->clearedVehicle(2_017);

        $this->assertStringContainsString(__('receivable.cancel.import_cleared'), $this->screen()->html(),
            '선택지가 안 뜬다');

        $totals = $this->screen(true)->instance()->importClearedTotals;
        $this->assertSame([['cur' => 'EUR', 'total' => 3317.0, 'cars' => 2]], $totals,
            '통화별 합계가 안 맞는다 — 🚫 통화를 섞어 더하지 말 것');
    }

    /** 🔑 바이어를 고르면 그 바이어에게 받을 금액만 남는다 — 이게 드롭박스에 둔 이유다. */
    public function test_totals_follow_the_buyer_filter(): void
    {
        $a = $this->clearedVehicle(1_300);
        $this->clearedVehicle(2_017);

        $totals = $this->screen(true)->set('buyerFilter', (string) $a->buyer_id)
            ->instance()->importClearedTotals;

        $this->assertSame([['cur' => 'EUR', 'total' => 1300.0, 'cars' => 1]], $totals,
            '바이어를 골랐는데 합계가 안 따라온다 — KPI·합계가 드롭박스를 타야 한다');
    }

    /** ④ 미수가 0 이라 「왜 여기 있지」가 된다 — 화면이 이유를 말해야 한다(SKILLS §8 #60·#85). */
    public function test_the_screen_explains_why_a_paid_up_vehicle_is_listed(): void
    {
        $v = $this->vehicle();
        $this->importCleared($v, 1_300);

        $this->assertStringContainsString(__('receivable.import_cleared_note'),
            $this->screen(true)->html(),
            '골라도 이유 안내가 없다 — 미수 0 만 보고 멀쩡한 기록을 손대게 된다');
    }

    /** 고르면 탭도 「완납」으로 같이 간다 — 기본 탭(미수>0)에 두면 **0 건**이 뜬다(과입금과 같은 함정). */
    public function test_choosing_the_filter_moves_the_tab_to_paid_up(): void
    {
        $this->importCleared($this->vehicle(), 1_300);

        $this->assertSame('paid_up', $this->screen(true)->instance()->classification,
            '드롭박스만 바뀌고 탭이 안 따라왔다 — 채권 전체 탭은 미수>0 이라 0 건이 된다');
    }

    /** 회수이력 줄에도 표시한다 — 진짜 회수한 「기타」와 섞이면 청구 대상을 못 고른다. */
    public function test_the_history_line_is_marked(): void
    {
        $v = $this->vehicle();
        $this->importCleared($v, 1_300);

        $html = $this->screen('import_cleared')->call('openPanel', $v->id)->html();

        $this->assertStringContainsString('>'.__('receivable.import_cleared_badge').'<', $html,
            '회수이력 줄에 임포트 표시가 없다');
    }

    // ── ⑤ 「받았음」 청산 ──────────────────────────────────────────

    /** 전액 받으면 현금 기록이 생기고 그 줄이 사라진다 → 목록에서도 빠진다. */
    public function test_settling_in_full_records_cash_and_removes_the_line(): void
    {
        $v = $this->clearedVehicle(1_300);
        $h = ReceivableHistory::query()->importCleared()->where('vehicle_id', $v->id)->firstOrFail();

        $this->screen(true)->call('openPanel', $v->id)
            ->call('openSettleImport', $h->id)
            ->call('settleImportCleared')
            ->assertHasNoErrors();

        $this->assertNull(ReceivableHistory::find($h->id), '「기타」 줄이 안 지워졌다');
        $this->assertSame(1, ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'cash')->count(),
            '실제 수령이 현금으로 기록되지 않았다');
        $this->assertSame(0, ReceivableHistory::query()->importCleared()->where('vehicle_id', $v->id)->count(),
            '청산했는데 목록에 남는다');
    }

    /** 부분 수령이면 남은 금액만 계속 뜬다. */
    public function test_a_partial_receipt_leaves_the_remainder(): void
    {
        $v = $this->clearedVehicle(1_300);
        $h = ReceivableHistory::query()->importCleared()->where('vehicle_id', $v->id)->firstOrFail();

        $this->screen(true)->call('openPanel', $v->id)
            ->call('openSettleImport', $h->id)
            ->set('settleAmount', '300')
            ->call('settleImportCleared')
            ->assertHasNoErrors();

        $this->assertSame('1000.00', (string) ReceivableHistory::find($h->id)->amount,
            '남은 금액이 안 맞는다');
    }

    /**
     * 청산이 끝난 뒤 **진행상태가 그대로여야 한다** — v5 는 「출고일 + 완납」이라 미수가 살아나면
     * 거래완료가 판매중으로 떨어진다.
     *
     * ⚠️ 이 테스트는 **메서드 안의 순서를 검증하지 않는다** — 한 트랜잭션이고 끝에 refreshCaches 로
     *    최종 상태를 확정하므로 순서를 뒤집어도 통과한다(실제로 뒤집어 확인했다).
     *    지키는 것은 「청산 후 결과」이고, 사람이 손으로 두 단계를 할 때의 중간 위험이야말로
     *    이 버튼을 만든 이유다.
     */
    public function test_settling_never_knocks_the_vehicle_out_of_done(): void
    {
        $v = $this->vehicle([
            'progress_status_rule_version' => 5,
            'warehouse_out_date' => now()->subMonth()->toDateString(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5_000,
            'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now()->subMonths(2),
        ]);
        $h = $this->importCleared($v, 1_300);
        $v->refresh();
        $this->assertSame('거래완료', $v->progress_status_cache, '전제: v5 로 거래완료여야 한다');

        $this->screen(true)->call('openPanel', $v->id)
            ->call('openSettleImport', $h->id)->call('settleImportCleared');

        $this->assertSame('거래완료', $v->fresh()->progress_status_cache,
            '청산했더니 거래완료가 풀렸다 — 현금을 먼저 넣고 「기타」를 지워야 한다');
        $this->assertSame(0.0, (float) $v->fresh()->sale_unpaid_amount, '미수가 0 이 아니다');
    }

    /** 🔒 사람이 넣은 「기타」는 이 버튼으로 못 지운다 — 표식 있는 행만 대상이다. */
    public function test_the_button_refuses_a_manual_other_row(): void
    {
        $v = $this->vehicle();
        $manual = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'other', 'amount' => 500,
            'collected_at' => now()->toDateString(), 'note' => '현장 현금 수령',
        ]);

        $this->screen(true)->call('openPanel', $v->id)
            ->call('openSettleImport', $manual->id)->call('settleImportCleared');

        $this->assertNotNull(ReceivableHistory::find($manual->id),
            '표식 없는 「기타」가 이 버튼으로 지워졌다');
        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'cash')->count());
    }

    /**
     * 🔒 표식은 한 곳에서만 정의된다 — 전환 명령(`ssancarerp:convert-import-receivables`)이
     *    같은 행을 고른다. 갈리면 「탭엔 보이는데 전환은 건너뛰는」 행이 생긴다(SKILLS §8 #45).
     */
    public function test_the_marker_has_a_single_source(): void
    {
        $src = file_get_contents(base_path('app/Console/Commands/ConvertImportReceivableToPayment.php'));

        $this->assertStringContainsString('->importCleared()', $src,
            '전환 명령이 단일 출처 스코프를 안 쓴다');
        $this->assertStringNotContainsString('과거데이터 임포트%', $src,
            '표식 문자열을 다시 적어뒀다 — ReceivableHistory::IMPORT_CLEARED_NOTE_PREFIX 를 쓸 것');

        $screen = file_get_contents(base_path('resources/views/livewire/erp/receivables/index.blade.php'));
        $this->assertStringContainsString('importCleared()', $screen,
            '채권관리 필터가 단일 출처 스코프를 안 쓴다');
    }
}
