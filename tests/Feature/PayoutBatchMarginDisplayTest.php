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

    // ── 정산 없는 월급 직원 (jin 2026-10-06 「정산이 0명인 사람은 월급만 나올 수 있게」) ──────

    /**
     * 💴 **그 달 정산이 0건인 재직 사내직원도 「기본급만」 줄로 두 화면에 오르고, 기본급 합계에 들어간다.**
     *    구(09-18)는 「배치에 이름이 있는 사람만」이라 그 직원의 월급이 송금 예상에서 조용히 빠졌다.
     *
     * ⚠️ 정산 0 이면 기본급 = 월수령액이라 숫자만으로는 못 가른다(§8 #107) — 이름·라벨 옆에서 본다.
     */
    public function test_a_salaried_employee_with_no_settlement_appears_on_both_screens(): void
    {
        [$batch] = $this->batch();
        $idle = Salesman::create([
            'name' => '이달엔 건이 없는 직원', 'type' => 'employee',
            'is_active' => true, 'base_salary_krw' => 5_000_000,
        ]);
        $payoutBefore = (int) $batch->total_payout;

        // 합계 = 배치 안 직원(2,740,000) + 정산 없는 직원(5,000,000)
        $this->assertSame(2_740_000 + 5_000_000, $batch->fresh()->baseSalaryTotal(),
            '정산 없는 직원의 기본급이 합계에 안 들어갔다');

        // ① 월배치 화면 — 그 사람 줄이 「기본급만」 라벨과 함께 뜬다
        $this->actingAs($this->manager());
        $html = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->html();
        $this->assertMatchesRegularExpression(
            '/data-salary-only="'.$idle->id.'"[\s\S]{0,600}?'.preg_quote($idle->name, '/').'[\s\S]{0,300}?'
            .preg_quote(__('payout_batch.margin.pay.salary_only'), '/').'/u',
            $html, '월배치 화면에 「기본급만」 줄이 없다'
        );
        $this->assertStringContainsString(number_format(5_000_000), $html);

        // ② 대표 승인 페이지 — 같은 사람이 「기본급만」으로 보인다
        $url = URL::temporarySignedRoute(
            'payout.approve.show', now()->addDay(), ['batch' => $batch->id, 'u' => $this->manager()->id]
        );
        $page = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($idle->name, '/').'[\s\S]{0,200}?기본급만/u',
            $page, '승인 페이지에 「기본급만」 사람이 없다'
        );
        $this->assertStringContainsString('정산 없음 · 기본급만', $page);
        $this->assertStringNotContainsString('정산 없음 (조정만)', $page, '기본급만 직원이 「조정만」으로 찍혔다');
        // 「이달 송금 예상」도 그 사람 몫만큼 커진다
        $this->assertStringContainsString(
            number_format($payoutBefore + 2_740_000 + 5_000_000).'원', $page,
            '이달 송금 예상에 정산 없는 직원의 기본급이 안 들어갔다'
        );

        // 🚫 지급 총액은 그대로 — 급여는 정산이 아니다
        $this->assertSame($payoutBefore, (int) $batch->fresh()->total_payout, '지급 총액이 움직였다');
    }

    /**
     * 🚪 **「전 직원 급여 합계」는 아니다** — 퇴사 · 지급 제외(신분, §8 #103) · 기본급 미입력 · 기본급 0(명시 「없음」) ·
     *    예치금만 있는 프리랜서는 줄도 합계도 안 생긴다. jin 요청 범위 = **월급**이라 예치금만 있는 사람은 대상이 아니다.
     */
    public function test_people_who_do_not_draw_a_salary_this_month_stay_out(): void
    {
        [$batch] = $this->batch();

        $outsiders = [
            '퇴사한 직원' => ['type' => 'employee', 'is_active' => false, 'base_salary_krw' => 5_000_000],
            '지급 제외 계정' => ['type' => 'employee', 'is_active' => true, 'payout_excluded' => true, 'base_salary_krw' => 5_000_000],
            '기본급 미입력' => ['type' => 'employee', 'is_active' => true, 'base_salary_krw' => null],
            '기본급 없음 명시' => ['type' => 'employee', 'is_active' => true, 'base_salary_krw' => 0],
            '예치금만 있는 프리랜서' => ['type' => 'freelance', 'is_active' => true, 'deposit_krw' => 7_000_000],
        ];
        foreach ($outsiders as $name => $attrs) {
            Salesman::create(array_merge(['name' => $name], $attrs));
        }

        $this->assertSame(2_740_000, $batch->fresh()->baseSalaryTotal(), '월급이 안 나가는 사람의 기본급이 섞였다');

        $this->actingAs($this->manager());
        $html = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->html();
        foreach (array_keys($outsiders) as $name) {
            $this->assertStringNotContainsString($name, $html, "「{$name}」이 월배치 화면에 올라왔다");
        }
        $this->assertStringNotContainsString('data-salary-only', $html);
    }

    /**
     * 🔁 **배치에 이미 있는 직원은 두 번 안 센다** — 정산이 있는 직원은 정산 줄로만 나오고
     *    「기본급만」 줄이 또 생기면 안 된다(id 로 뺀다 — 이름으로 빼면 동명이인이 사라진다).
     */
    public function test_an_employee_already_in_the_batch_is_not_listed_twice(): void
    {
        [$batch, $employee] = $this->batch();

        $this->assertSame(2_740_000, $batch->fresh()->baseSalaryTotal());
        $this->assertCount(0, $batch->fresh()->salaryOnlyPeople($batch->settlements, $batch->adjustments));

        $this->actingAs($this->manager());
        $html = Volt::test('erp.payout-batches.index')->call('toggle', $batch->id)->html();
        $this->assertSame(1, substr_count($html, '>'.$employee->name), '같은 직원이 두 줄로 나왔다');
        $this->assertStringNotContainsString('data-salary-only', $html);
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
