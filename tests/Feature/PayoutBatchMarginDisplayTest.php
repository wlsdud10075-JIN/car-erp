<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📊 **월배치 화면 + 대표 승인 페이지** — 마진율 · 기본급 · 월수령액 (jin 2026-09-18).
 *
 * 🔑 **대표는 `/erp/payout-batches` 가 아니라 카톡 링크로 열리는 승인 페이지에서 승인한다.**
 *    두 화면이 사람별 내역을 **각자 따로 묶기** 때문에, 숫자를 만드는 식이 갈리면
 *    «월배치 3.6% ↔ 승인화면 3.7%» 가 된다. 묶는 루프는 달라도 **숫자는 한 출처**여야 한다(§8 #44·#45).
 *
 * 🚫 **금액은 하나도 안 바뀐다** — 지급 총액·회사이익·대표 알림톡 총액 전부 종전 그대로.
 *    기본급은 급여라 정산이 아니다(§8 #72 의 그 형태).
 */
class PayoutBatchMarginDisplayTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    /** 월배치 제출 권한 = approvalRank 1~2 ([관리] / 업무관리자). admin 은 승인자라 제출은 못 한다. */
    private function submitter(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** 총마진·판매금원화가 예측 가능한 외화 미완납 차량 + 확정 정산. */
    private function confirmed(Salesman $sm, string $month, int $salePrice = 10_000, array $attrs = []): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'MR'.++$this->c,
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1000,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000, 'purchase_date' => $month.'-01',
            'sale_price' => $salePrice, 'sale_date' => $month.'-02',
        ]);
        // 완납시킨다 — 미수가 남으면 지급보류 게이트가 배치에서 통째로 빼버린다(그게 정상 동작이다).
        //   입금 환율을 판매환율과 같게 둬서 정산환율이 안 흔들리게 한다(마진율을 눈으로 검산 가능).
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => $salePrice, 'exchange_rate' => 1000,
            'payment_date' => $month.'-10', 'confirmed_at' => now(),
        ]);
        $v->refresh();

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => $sm->type === 'freelance' ? 'ratio' : 'per_unit',
            'settlement_ratio' => $sm->type === 'freelance' ? 50 : null,
            'per_unit_amount' => $sm->type === 'freelance' ? null : 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => $month.'-15',
            'attributed_month' => $month.'-01',
        ], $attrs));
    }

    /** 사내직원(기본급 있음) 2건 + 프리랜서(예치금 있음) 1건짜리 배치. */
    private function batch(): array
    {
        $employee = Salesman::create([
            'name' => '조하', 'type' => 'employee', 'is_active' => true, 'base_salary_krw' => 2_740_000,
        ]);
        $freelancer = Salesman::create([
            'name' => '와심', 'type' => 'freelance', 'is_active' => true, 'deposit_krw' => 10_000_000,
        ]);

        $this->confirmed($employee, '2026-05');
        $this->confirmed($employee, '2026-05', 20_000);
        $this->confirmed($freelancer, '2026-05');

        $batch = SettlementPayoutBatch::submitForMonth($this->submitter(), '2026-05');

        return [$batch, $employee, $freelancer];
    }

    // ── 두 화면이 같은 숫자를 말한다 ────────────────────────────────────

    /**
     * 🚨 **이 테스트가 이 기능의 핵심이다** — 월배치 화면과 대표 승인 페이지가
     *    사람별 마진율을 **각자 계산**하므로, 같은 값이 나오는지 직접 비교한다.
     */
    public function test_both_screens_report_the_same_margin_rate(): void
    {
        [$batch, $employee] = $this->batch();
        $this->actingAs($this->manager());

        $rows = $batch->settlements()->with('vehicle')->get()
            ->filter(fn (Settlement $s) => $s->salesman_id === $employee->id);
        $expected = Settlement::formatMarginRate(Settlement::marginRateOf($rows));

        // ① 월배치 화면 (펼친 상태)
        Volt::test('erp.payout-batches.index')
            ->call('toggle', $batch->id)
            ->assertSee($employee->name)
            ->assertSee($expected);

        // ② 대표 승인 페이지 — 같은 문자열이 나와야 한다
        // 승인 페이지는 로그인이 아니라 **서명 링크**로 열린다 — 실제 카톡 버튼과 같은 경로로 연다.
        $approver = $this->manager();
        $url = URL::temporarySignedRoute(
            'payout.approve.show', now()->addDay(), ['batch' => $batch->id, 'u' => $approver->id]
        );
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString($expected, $html,
            '승인 페이지가 월배치 화면과 다른 마진율을 말한다');
    }

    /** 사내직원 소계는 「기본급 + 정산 = 월수령액」 3줄. */
    public function test_an_employee_row_shows_base_salary_and_take_home(): void
    {
        [$batch, $employee] = $this->batch();
        $this->actingAs($this->manager());

        $net = (int) $batch->settlements()->where('salesman_id', $employee->id)->get()
            ->sum(fn (Settlement $s) => (int) $s->actual_payout);

        Volt::test('erp.payout-batches.index')
            ->call('toggle', $batch->id)
            ->assertSee(number_format(2_740_000))
            ->assertSee(number_format(2_740_000 + $net));   // 월수령액
    }

    /** 프리랜서는 예치금 보유액만 — 지급액에 더하지 않는다(jin 「그냥 보유하면되고」). */
    public function test_a_freelancer_row_shows_the_deposit_but_never_adds_it(): void
    {
        [$batch, , $freelancer] = $this->batch();
        $this->actingAs($this->manager());

        $before = (int) $batch->total_payout;

        Volt::test('erp.payout-batches.index')
            ->call('toggle', $batch->id)
            ->assertSee(number_format(10_000_000));

        $this->assertSame($before, (int) $batch->fresh()->total_payout);
        $this->assertNotSame($before, $before + 10_000_000);   // 자명하지만 의도를 박제한다
        $this->assertStringNotContainsString(
            number_format($before + (int) $freelancer->deposit_krw),
            (string) $batch->fresh()->total_payout,
            '예치금이 지급 총액에 섞였다'
        );
    }

    // ── 금액 불변 ───────────────────────────────────────────────────────

    /**
     * 🚨 **기본급은 지급 총액·회사이익 어디에도 안 들어간다.**
     *    회사이익은 `총마진 − 지급 − 발송비`다. 급여를 넣으면 그 지표의 뜻이 바뀐다.
     */
    public function test_base_salary_never_enters_the_total_or_the_company_profit(): void
    {
        [$batch, $employee] = $this->batch();

        $before = $batch->profitStats();
        $payoutBefore = (int) $batch->total_payout;

        $employee->update(['base_salary_krw' => 9_999_999]);
        $after = $batch->fresh()->profitStats();

        $this->assertSame($payoutBefore, (int) $batch->fresh()->total_payout, '지급 총액이 움직였다');
        $this->assertSame($before['company_profit'], $after['company_profit'], '회사이익이 움직였다');
        $this->assertSame($before['total_margin'], $after['total_margin']);
        // 표시용 합계만 따라온다
        $this->assertSame(9_999_999, $after['base_salary']);
    }

    /**
     * 💰 「이달 송금 예상」 = 지급 총액 + **이 배치에 이름이 올라온** 직원의 기본급.
     *    ⚠️ 그 달에 정산 건이 없는 직원은 배치에 없으므로 안 들어간다 — 「전 직원 급여 합계」가 아니다.
     */
    public function test_the_base_salary_total_counts_only_people_in_the_batch(): void
    {
        [$batch] = $this->batch();

        Salesman::create([
            'name' => '이달엔 건이 없는 직원', 'type' => 'employee',
            'is_active' => true, 'base_salary_krw' => 5_000_000,
        ]);

        $this->assertSame(2_740_000, $batch->fresh()->baseSalaryTotal(),
            '배치에 없는 직원의 기본급이 섞였다');
    }

    // ── 비용 ────────────────────────────────────────────────────────────

    /**
     * 🐢 **관계를 안 얹으면 차량마다 잔금·회수이력을 읽는다**(정산액 → 총마진 → 정산환율 → 미수).
     *    실측 560건 배치에서 1,125 쿼리였다.
     */
    public function test_the_batch_screen_eager_loads_what_the_money_accessors_read(): void
    {
        [$batch] = $this->batch();
        $this->actingAs($this->manager());

        $loaded = Volt::test('erp.payout-batches.index')->instance()->batches()
            ->firstWhere('id', $batch->id);
        $vehicle = $loaded->settlements->first()->vehicle;

        // ⚠️ **쿼리 수로 세지 말 것** — 표본이 3건이면 지연 로딩과 차이가 2개뿐이라 임계값이
        //    아무것도 안 지킨다(실측 18 ↔ 20, 일부러 빼고 돌려서 확인했다). 관계가 실제로
        //    얹혔는지를 **직접** 본다.
        $this->assertTrue($loaded->relationLoaded('settlements'));
        $this->assertTrue($vehicle->relationLoaded('finalPayments'),
            '잔금이 지연 로딩이다 — 배치 1개당 차량 수만큼 쿼리가 더 나간다(실측 560건 = 1,125개)');
        $this->assertTrue($vehicle->relationLoaded('receivableHistories'),
            '회수이력이 지연 로딩이다 — 미수 계산이 차량마다 쿼리를 친다');
    }

    /**
     * 🚨 **승인 페이지는 대표가 실제로 보는 화면이다** — 마진율만 대조하면 절반이다.
     *    기본급 3줄 · 「+ 기본급 합계 / 이달 송금 예상」 · 차량 줄 마진율까지 실제 렌더로 확인한다.
     *    (그 블록들은 월배치 화면과 **다른 코드**라 한쪽만 고쳐져도 아무 테스트가 안 빨개졌다.)
     */
    public function test_the_approval_page_shows_the_pay_block_and_the_expected_transfer(): void
    {
        [$batch, $employee, $freelancer] = $this->batch();

        $approver = $this->manager();
        $url = URL::temporarySignedRoute(
            'payout.approve.show', now()->addDay(), ['batch' => $batch->id, 'u' => $approver->id]
        );
        $html = $this->get($url)->assertOk()->getContent();

        // ① 사내직원 3줄 — 기본급 / 정산 / 월수령액
        $net = (int) $batch->settlements()->where('salesman_id', $employee->id)->get()
            ->sum(fn (Settlement $s) => (int) $s->actual_payout);
        $this->assertStringContainsString('월수령액', $html, '승인 페이지에 월수령액 줄이 없다');
        $this->assertStringContainsString(number_format((int) $employee->base_salary_krw), $html);
        $this->assertStringContainsString(number_format((int) $employee->base_salary_krw + $net), $html,
            '월수령액 = 기본급 + 정산 이 안 찍혔다');

        // ② 프리랜서는 예치금 보유만
        $this->assertStringContainsString('예치금 보유', $html);
        $this->assertStringContainsString(number_format((int) $freelancer->deposit_krw), $html);

        // ③ 지급 총액 카드 — 승인 금액은 그대로이고 그 아래에 참고 두 줄
        $this->assertStringContainsString('이달 송금 예상', $html, '송금 예상 줄이 없다');
        $this->assertStringContainsString(
            number_format((int) $batch->total_payout + $batch->baseSalaryTotal()).'원', $html,
            '이달 송금 예상 = 지급 총액 + 기본급 합계 가 안 맞는다'
        );
        $this->assertStringContainsString(number_format((int) $batch->total_payout).'원', $html,
            '승인 금액(지급 총액)이 기본급까지 더한 값으로 바뀌었다');

        // ④ 차량 줄에도 마진율
        $s = $batch->settlements()->with('vehicle')->first();
        $this->assertStringContainsString(
            '마진율 '.Settlement::formatMarginRate($s->margin_rate), $html,
            '차량 줄에 마진율이 없다'
        );
    }
}
