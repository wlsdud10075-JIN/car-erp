<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkDailySummary;
use App\Console\Commands\AlimtalkWeeklySummary;
use App\Models\Buyer;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 일일·주간 요약 알림톡의 선적전/선적후 미수에 **비중 %** 를 붙인다.
 *
 * 🔀 2026-08-20 (jin) — % 의 뜻이 바뀌었다.
 *   구: 미수 차량의 **판매총액** 대비 미수율(공유 분모). 그 분모가 화면 어디에도 없어 대조가 안 됐고,
 *       jin 본인이 "이게 어떻게 나오는 수치야?" 로 물었다.
 *   신: **미수 총액을 100 으로 놓은 구성비** — "미수 9.61억 중 선적전 26%, 선적후 74%".
 *   ⚠️ 08-06 의 요구(나란히 놓인 두 % 는 더해서 읽히니 더해서 말이 되게 하라)는 **그대로 유지**된다.
 *       구 규칙은 합이 전체 미수율, 신 규칙은 합이 100%. 어느 쪽이든 더해 읽는 게 정답이 된다.
 *   구 값(합 49.5% = 미수 차량끼리의 미납률)은 버린 게 아니라 채권관리 KPI 「미납률」로 옮겼다.
 *
 * 설계 요지 — 본문(msg)과 카드(아이템리스트)가 **같은 값을 쓰면 안 된다**:
 *   · 본문 = 정확한 원 단위 + %   (길이 여유 있음)
 *   · 카드 = 억 단위 축약 + %      (description 20자 컷, SKILLS §8 #35 — 같이 쓰면 % 가 잘려나간다)
 * 등록본의 고정 문구(`#{선적전건수}건 · #{선적전금액}`)는 그대로고 **값만** 달라지므로 BizM 재등록 불필요.
 *
 * 🚫 요약칸('미수 합계')에는 % 를 넣지 않는다 — 금액 표기(숫자·쉼표·원)만 허용이라 K140 반려(SKILLS §8 #40).
 */
class AlimtalkSummaryUnpaidRatioTest extends TestCase
{
    use RefreshDatabase;

    /** 선적후(출고일 있음) 미수 차량. 판매 1000만 / 입금 250만 → 미수 750만 = 75% */
    private function shippedUnpaid(string $num, int $sale, int $paid): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.$num, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => $sale,
            'sale_date' => now()->subDays(30)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'warehouse_out_date' => now()->subDays(3)->toDateString(),
        ]);
        if ($paid > 0) {
            $v->finalPayments()->create(['amount' => $paid, 'type' => 'balance', 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    /** 선적전(출고일 없음) 미수 차량 — grace(판매일+10일) 를 지나야 채권으로 잡힌다. */
    private function preShippingUnpaid(string $num, int $sale, int $paid): Vehicle
    {
        $buyer = Buyer::create(['name' => 'P'.$num, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => $sale,
            'sale_date' => now()->subDays(60)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);
        if ($paid > 0) {
            $v->finalPayments()->create(['amount' => $paid, 'type' => 'balance', 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    public function test_body_shows_exact_amount_with_percent(): void
    {
        $this->shippedUnpaid('11가1111', 10_000_000, 2_500_000);

        $vars = app(AlimtalkDailySummary::class)->buildVars();

        // 선적전이 없으므로 미수 전부가 선적후 몫 → 100%
        $this->assertSame('7,500,000원 (100%)', $vars['선적후금액']);
        // 합계엔 % 를 붙이지 않는다 — 구성비의 합은 늘 100% 라 정보가 0 이다.
        $this->assertSame('7,500,000원', $vars['미수합계']);
    }

    /** 카드는 축약형이라 20자 컷에 안 걸린다. 본문 값을 그대로 썼다면 잘렸을 것. */
    public function test_card_uses_abbreviated_amount_and_fits_the_limit(): void
    {
        $this->shippedUnpaid('22가2222', 300_000_000, 0);   // 3억 전액 미수

        $vars = app(AlimtalkDailySummary::class)->buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_daily_summary', $vars);

        $after = collect($payload['items']['item']['list'])->firstWhere('title', '선적후 미수');
        $this->assertNotNull($after);
        $this->assertStringContainsString('%', $after['description'], '카드에서 % 가 잘려나갔다');
        $this->assertLessThanOrEqual(20, mb_strlen($after['description']), '카드 description 20자 초과 — 발송 반려된다');
        $this->assertStringContainsString('억', $after['description']);
    }

    /** 🚫 요약칸은 금액 표기만 — % 가 새어 들어가면 K140 으로 발송 자체가 반려된다. */
    public function test_summary_line_has_no_percent(): void
    {
        $this->shippedUnpaid('33가3333', 10_000_000, 1_000_000);

        $vars = app(AlimtalkDailySummary::class)->buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_daily_summary', $vars);

        $this->assertArrayHasKey('summary', $payload['items']['item']);
        $this->assertStringNotContainsString('%', $payload['items']['item']['summary']['description']);
        $this->assertStringNotContainsString('억', $payload['items']['item']['summary']['description']);
    }

    /** 대상이 0건이면 % 를 억지로 만들지 않는다(0% 로 오해 유발 금지). */
    public function test_no_percent_when_nothing_outstanding(): void
    {
        $vars = app(AlimtalkDailySummary::class)->buildVars();

        $this->assertSame('0원', $vars['선적전금액']);
        $this->assertSame('0원', $vars['선적후금액']);
    }

    public function test_weekly_summary_has_the_same_treatment(): void
    {
        $this->shippedUnpaid('44가4444', 10_000_000, 2_500_000);

        $vars = app(AlimtalkWeeklySummary::class)->buildVars();

        $this->assertSame('7,500,000원 (100%)', $vars['선적후금액']);

        $payload = AlimtalkTemplates::itemListPayload('erp_weekly_summary', $vars);
        $after = collect($payload['items']['item']['list'])->firstWhere('title', '선적후 미수');
        $this->assertLessThanOrEqual(20, mb_strlen($after['description']));
        $this->assertStringContainsString('%', $after['description']);
    }

    /** 카드 전용 오버라이드 키가 본문 렌더에 새어 나가면 안 된다(배열이라 치환 대상 아님). */
    public function test_card_override_key_never_leaks_into_body(): void
    {
        $this->shippedUnpaid('55가5555', 10_000_000, 0);

        $vars = app(AlimtalkDailySummary::class)->buildVars();
        $body = AlimtalkTemplates::render('erp_daily_summary', $vars);

        $this->assertArrayHasKey(AlimtalkTemplates::CARD_VARS_KEY, $vars);
        $this->assertStringNotContainsString('Array', $body);
        $this->assertStringNotContainsString(AlimtalkTemplates::CARD_VARS_KEY, $body);
    }

    /**
     * 🔑 핵심 — 두 % 는 **미수 총액을 쪼갠 구성비**라 합이 100% 여야 한다.
     * 각자 다른 분모를 쓰면 더할 수 없는 숫자인데, 카톡에 나란히 찍히면 사람은 반드시 더해서 읽는다
     * (jin 2026-08-06: "9%가 비는데 이건 뭐지?" — 31%+60%=91% 로 오독). 그 요구는 08-20 개편에도 유지된다.
     */
    public function test_before_and_after_percentages_split_the_unpaid_total(): void
    {
        // 선적전 미수 200만 / 선적후 미수 600만 → 총 800만 중 25% : 75%
        $this->preShippingUnpaid('66가6666', 10_000_000, 8_000_000);
        $this->shippedUnpaid('77가7777', 10_000_000, 4_000_000);

        $vars = app(AlimtalkDailySummary::class)->buildVars();

        $this->assertStringContainsString('(25%)', $vars['선적전금액']);
        $this->assertStringContainsString('(75%)', $vars['선적후금액']);
        $this->assertSame('8,000,000원', $vars['미수합계'], '합계에 % 가 붙으면 위 두 값과 모수가 갈린다');
    }

    /**
     * 🚫 요약칸(미수 합계)에는 % 가 절대 들어가면 안 된다 — 금액 표기 전용이라 K140 반려(SKILLS §8 #40).
     * 08-20 개편으로 합계엔 애초에 % 를 안 붙이지만, 누가 다시 붙이면 **발송 자체가 죽으므로** 가드를 남긴다.
     */
    public function test_summary_total_never_carries_a_percent(): void
    {
        $this->shippedUnpaid('88가8888', 10_000_000, 0);

        $vars = app(AlimtalkDailySummary::class)->buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_daily_summary', $vars);

        $this->assertStringNotContainsString('%', $vars['미수합계']);
        $this->assertStringNotContainsString('%', $payload['items']['item']['summary']['description']);
    }
}
