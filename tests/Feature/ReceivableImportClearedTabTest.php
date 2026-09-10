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
 * 🧾 채권관리 「임포트 정리분」 탭 (jin 2026-09-10).
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
 *   ③ 대상이 없으면 탭이 아예 안 뜬다 (다른 두 회사 · 다 정리한 뒤)
 *   ④ 왜 여기 있는지 화면이 말한다 (미수 컬럼이 전부 0 이라 설명이 없으면 오해한다)
 */
class ReceivableImportClearedTabTest extends TestCase
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

    private function screen(string $classification = '')
    {
        return Volt::actingAs($this->admin())->test('erp.receivables.index')
            ->set('classification', $classification);
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

        $this->assertStringContainsString($v->vehicle_number, $this->screen('import_cleared')->html(),
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

        $this->assertStringNotContainsString($v->vehicle_number, $this->screen('import_cleared')->html(),
            '사람이 넣은 기타 회수가 임포트 정리분으로 잡혔다');
    }

    /** ③ 대상이 없으면 탭 자체가 안 뜬다 — 회사별 분기를 코드에 박지 않기 위한 장치. */
    public function test_the_tab_is_hidden_when_there_is_nothing_to_show(): void
    {
        $this->vehicle();   // 표식 없는 평범한 차량만

        $this->assertStringNotContainsString(__('receivable.tab.import_cleared'), $this->screen()->html(),
            '대상이 0 인데 탭이 떴다 — 다 정리하면 저절로 사라져야 한다');
    }

    /** 대상이 생기면 탭이 카운트와 함께 뜬다. */
    public function test_the_tab_appears_with_its_count_once_there_is_something(): void
    {
        $this->importCleared($this->vehicle(), 1_300);
        $this->importCleared($this->vehicle(), 2_017);

        $c = $this->screen();
        $this->assertStringContainsString(__('receivable.tab.import_cleared'), $c->html(), '탭이 안 뜬다');
        $this->assertSame(2, $c->instance()->classificationCounts['import_cleared'],
            '탭 카운트가 대상 수와 다르다');
    }

    /** ④ 미수가 0 이라 「왜 여기 있지」가 된다 — 화면이 이유를 말해야 한다(SKILLS §8 #60·#85). */
    public function test_the_screen_explains_why_a_paid_up_vehicle_is_listed(): void
    {
        $v = $this->vehicle();
        $this->importCleared($v, 1_300);

        $this->assertStringContainsString(__('receivable.import_cleared_note'),
            $this->screen('import_cleared')->html(),
            '탭을 켜도 이유 안내가 없다 — 미수 0 만 보고 멀쩡한 기록을 손대게 된다');
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
            '채권관리 탭이 단일 출처 스코프를 안 쓴다');
    }
}
