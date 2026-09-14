<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔢 **카드의 숫자를 누르면 그 숫자만큼 나와야 한다** (jin 2026-09-14 「A~F 전부」).
 *
 * jin: *「업무대시보드/관리자대시보드 숫자 전부 재조사가 필요할 것으로 보임.」*
 *
 * 🚨 **기존 테스트가 이걸 못 잡았다.** `DashboardActionCountsTest`·`AdminDashboardTest` 는
 *    주석에 「vehicles 목록과 카운트 100% 일치」라고 적어 놓고 **둘 다 `Vehicle::action()` 만 부른다** —
 *    목록 화면을 띄우지 않는다. 정작 갈리는 자리는 **카드의 인라인 계산 ↔ 목록 화면**인데
 *    그 조합을 세는 테스트가 **한 건도 없었다**.
 *    ⇒ 이 파일은 **대시보드를 렌더해 카드 숫자를 읽고, 차량목록을 렌더해 총건수를 읽어** 비교한다.
 *
 * 📌 실사고(2026-09-14 운영 실측) — 채권 위험도 「심각」
 *      heymanerp 16 → 눌러보면 10 · ssancarerp 207 → 145 · 위험 23 → 8
 *    카드는 「미수는 기간으로 안 자른다」(2026-08-20)라 전 기간인데 링크가 상단 조회기간을 실어 보냈다.
 */
class DashboardCardMatchesListTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::factory()->create([
            'permission' => 'super', 'role' => '관리', 'email_verified_at' => now(),
        ]));
    }

    /** 매입일을 **한참 전**으로 둔다 — 기본 조회기간(2개월) 밖이라 기간이 걸리면 사라진다. */
    private function oldUnpaidVehicle(string $risk): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        $v = Vehicle::create([
            'vehicle_number' => '55마'.str_pad((string) (5000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $b->id, 'salesman_id' => $s->id,
            'purchase_date' => now()->subMonths(10)->toDateString(),
            'sale_price' => 10_000_000, 'sale_date' => now()->subMonths(9)->toDateString(),
        ]);

        // 캐시 컬럼을 직접 세팅 — 위험도는 야간 재계산으로 굳는 값이라 테스트에서 그대로 쓴다.
        DB::table('vehicles')->where('id', $v->id)->update([
            'receivable_risk' => $risk,
            'sale_unpaid_amount_krw_cache' => $risk === 'safe' ? 0 : 10_000_000,
        ]);

        return $v->fresh();
    }

    private function listTotal(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return Volt::test('erp.vehicles.index', $params)->get('vehicles')->total();
    }

    /**
     * 🚨 **이번 결함 그 자체** — 카드가 전 기간을 세는데 링크가 기간을 실어 보내던 것.
     */
    public function test_receivable_risk_cards_match_their_lists(): void
    {
        foreach (['critical', 'danger', 'caution'] as $risk) {
            $this->oldUnpaidVehicle($risk);
        }

        $dash = Volt::test('admin.dashboard');
        $counts = $dash->get('receivableKpis')['risk_counts'];

        foreach (['critical', 'danger', 'caution'] as $risk) {
            $this->assertSame(1, (int) ($counts[$risk] ?? 0), "{$risk} 카드 전제가 안 선다");

            $url = $dash->instance()->vehiclesUrlAllTime(['action' => 'receivable_'.$risk]);
            $this->assertSame((int) $counts[$risk], $this->listTotal($url), implode("\n", [
                "채권 위험도 「{$risk}」 카드와 목록이 다르다.",
                '카드는 기간을 안 세는데 링크가 조회기간을 실어 보내면 목록만 잘린다(2026-09-14 실사고).',
                '고치는 법: vehiclesUrlAllTime() 을 쓸 것 — vehiclesUrl() 은 기간을 붙인다.',
            ]));
        }
    }

    /**
     * 🗑️ **「안전」 카드는 내렸다** (jin 2026-09-14 — *「안전이란 건 없지 뭐.. 채권인데..」*).
     *
     * 그 자리는 원래 **구조적으로 영원히 0** 이었다(미수 목록에서 「미수 0」을 찾으니까).
     * 살려서 완납 차를 세면 실측 싼카 **4,252대** — 화면만 채우고 아무 행동도 안 만든다.
     * ⇒ 카드를 없앴다. 🚫 다시 넣지 말 것.
     * ✅ 다만 `scopeAction('receivable_safe')` 는 **뜻이 맞게 고쳤다**(`> 0` 요구 제거) —
     *    되살리거나 다른 데서 쓸 때 0 만 나오는 일이 없게.
     */
    public function test_the_safe_card_is_gone_but_the_scope_is_correct(): void
    {
        $this->oldUnpaidVehicle('safe');
        $this->oldUnpaidVehicle('critical');

        // 화면에 「안전」 카드가 없다.
        $src = file_get_contents(base_path('resources/views/livewire/admin/dashboard.blade.php'));
        $this->assertStringNotContainsString("'safe' => [__('receivable.risk.safe')", $src,
            '채권 위험도에 「안전」 카드가 다시 들어왔다 — 완납은 채권이 아니다(jin 2026-09-14)');

        // 스코프는 뜻대로 동작한다(영원히 0 이 아니다).
        $this->assertSame(1, Vehicle::query()->action('receivable_safe')->count(),
            'receivable_safe 가 0 이다 — 미수 > 0 을 함께 요구하면 구조적으로 도달 불가다');
    }

    /**
     * 🚫 매입취소 차가 **카드와 목록 중 한쪽에만** 있으면 안 된다.
     * 2026-09-09 에 큐에서 뺐는데 카드의 인라인 계산이 안 따라왔던 자리다.
     */
    public function test_clearance_cards_exclude_cancelled_like_their_lists(): void
    {
        /*
         * ⚠️ **완납까지 만들어야 조건을 만족한다.** `sale_unpaid_amount_krw_cache` 를 create 에 넣어도
         *    `Vehicle::saving` 훅이 **다시 계산해서 덮는다** — 처음에 그렇게 썼다가 양쪽 다 0 이 되어
         *    브레이크를 넣어도 안 빨개졌다(§8 #73 — 「초록은 그 줄을 안 밟았다」일 수 있다).
         * ⚠️ 매입일도 대시보드 기본 조회기간(2개월) **안**이어야 카드가 센다.
         */
        $mk = function (string $cancel) {
            $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
            $v = Vehicle::create([
                'vehicle_number' => '66바'.str_pad((string) (6000 + $this->n), 4, '0', STR_PAD_LEFT),
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $s->id, 'cancel_status' => $cancel,
                'sale_price' => 5_000_000, 'sale_date' => now()->subDays(50)->toDateString(),
                'purchase_date' => now()->subDays(55)->toDateString(),
            ]);
            FinalPayment::create([
                'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5_000_000,
                'payment_date' => now()->subDays(45)->toDateString(), 'confirmed_at' => now()->subDays(45),
            ]);
            $v->refresh()->save();   // 캐시 재계산

            return $v->fresh();
        };
        $normal = $mk(Vehicle::CANCEL_NONE);
        $mk(Vehicle::CANCEL_ACTIVE);

        // 전제 — 취소 아닌 차가 실제로 「통관 정체」 조건을 만족해야 이 테스트가 뭔가를 검사한다.
        $this->assertSame(0, (int) $normal->sale_unpaid_amount, '전제가 안 선다 — 완납이어야 한다');
        $this->assertSame(1, Vehicle::query()->action('clearance_stuck')->count(),
            '전제가 안 선다 — 목록 기준으로 정확히 1대여야 한다(취소분 제외)');

        $kpis = Volt::test('admin.dashboard')->get('clearanceKpis');

        $this->assertSame(
            Vehicle::query()->action('clearance_stuck')->count(),
            (int) $kpis['stuck_count'],
            '「통관 정체」 카드가 목록과 다르다 — 카드에만 매입취소 차가 남아 있다'
        );
    }

    /** 「정산 대기」를 누르면 그 건수가 나와야 한다 — 화면이 이번 달로 좁혀 0건이 되던 자리. */
    public function test_settlement_pending_card_opens_the_same_set(): void
    {
        $s = Salesman::create(['name' => 'SS', 'is_active' => true, 'settlement_type' => 'ratio']);
        $v = Vehicle::create([
            'vehicle_number' => '77사1234', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'dhl_request' => false, 'salesman_id' => $s->id,
        ]);
        // 지난달 귀속 — 화면 기본값(이번 귀속월)이면 안 보인다.
        $st = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $s->id,
            'settlement_status' => 'pending', 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
        ]);
        DB::table('settlements')->where('id', $st->id)->update([
            'created_at' => now()->subMonths(3), 'attributed_month' => null,
        ]);

        $card = Settlement::query()->where('settlement_status', 'pending')->count();
        $this->assertSame(1, $card, '전제가 안 선다');

        // ⚠️ 파라미터 이름은 **프로퍼티명**이다 — `#[Url(as: 'status')]` 의 별칭이 아니다.
        //    별칭으로 넘기면 조용히 무시돼 화면이 기본값(이번 귀속월)으로 열린다.
        $screen = Volt::test('erp.settlements.index', ['statusFilter' => 'pending']);

        $this->assertSame($card, $screen->get('settlements')->total(), implode("\n", [
            '「정산 대기」 카드와 정산 화면이 다르다.',
            '상태 딥링크가 들어오면 월 기본값을 걸지 않아야 한다(지급보류 딥링크와 같은 이유).',
        ]));
    }

    /** 「승인 대기」는 폐기된 승인 유형을 빼고 세야 한다 — 승인 화면과 같은 출처. */
    public function test_pending_approvals_use_the_same_source_as_the_screen(): void
    {
        $dash = Volt::test('erp.dashboard');
        $dash->set('roleView', '관리');

        $this->assertSame(
            ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)->count(),
            ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)->count(),
            '전제'
        );

        $src = file_get_contents(base_path('resources/views/livewire/erp/dashboard.blade.php'));
        $this->assertStringNotContainsString(
            "ApprovalRequest::where('status', ApprovalRequest::STATUS_PENDING)->count()",
            $src,
            '승인 대기 카드가 actionable() 을 안 쓴다 — 폐기 유형까지 세어 승인 화면보다 크게 나온다'
        );
    }

    /**
     * 🔒 **정적 — 「기간을 안 세는 카드」가 기간 링크로 되돌아가면 실패한다.**
     * 되돌아가도 화면은 정상 렌더되고 숫자만 갈리므로 기능 테스트로는 원리상 못 잡는다.
     */
    public function test_risk_cards_never_go_back_to_the_dated_link(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/admin/dashboard.blade.php'));

        $ok = str_contains($src, "vehiclesUrlAllTime(['action' => 'receivable_'.\$key])");
        $bad = str_contains($src, "vehiclesUrl(['action' => 'receivable_'.\$key])");

        $this->assertTrue($ok && ! $bad, implode("\n", [
            '채권 위험도 카드가 기간을 실어 보내는 링크로 되돌아갔다.',
            '카드는 전 기간을 세므로(jin 2026-08-20) 목록만 잘려 숫자가 갈린다.',
        ]));
    }
}
