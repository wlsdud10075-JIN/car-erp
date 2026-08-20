<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkReceivableStatus;
use App\Console\Commands\AlimtalkWeeklySummary;
use App\Models\Buyer;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 채권 알림톡의 금액·% 표기 규격.
 *
 * 🔀 2026-08-20 (jin) — 대상이 **erp_receivable_status(채권 현황)** 으로 옮겨갔다.
 *   구: 일일요약이 매출과 미수를 함께 보냈다. 성격이 섞여 "오늘 뭘 밀어야 하나" 도 "돈은 어떤가" 도 안 보였다.
 *   신: 일일요약 = 진행 현황 / **채권 현황 = 돈**(신규, 매일) / 주간요약 = 매출·실적 + 미수 금액(% 없음).
 *
 * 설계 요지 — 본문(msg)과 카드(아이템리스트)가 **같은 값을 쓰면 안 된다**:
 *   · 본문 = 정확한 원 단위 + %   (길이 여유 있음)
 *   · 카드 = 억 단위 축약 + %      (description 20자 컷, SKILLS §8 #35 — 같이 쓰면 % 가 잘려나간다)
 *
 * 🚫 요약칸에는 % 를 넣지 않는다 — 금액 표기(숫자·쉼표·원)만 허용이라 K140 반려(SKILLS §8 #40).
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

        $vars = AlimtalkReceivableStatus::buildVars();

        // 분모 = 미수 차량의 판매금액 1,000만. 미수 750만 = 75% / 입금 250만 = 25%.
        $this->assertSame('7,500,000원 (75%)', $vars['선적후금액']);
        $this->assertSame('2,500,000원 (25%)', $vars['입금액']);
        // 🚫 합계엔 % 를 붙이지 않는다 — 요약칸은 금액 전용(K140).
        $this->assertSame('7,500,000원', $vars['미수합계']);
    }

    /** 카드는 축약형이라 20자 컷에 안 걸린다. 본문 값을 그대로 썼다면 잘렸을 것. */
    public function test_card_uses_abbreviated_amount_and_fits_the_limit(): void
    {
        $this->shippedUnpaid('22가2222', 300_000_000, 0);   // 3억 전액 미수

        $vars = AlimtalkReceivableStatus::buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_receivable_status', $vars);

        $after = collect($payload['items']['item']['list'])->firstWhere('title', '선적후');
        $this->assertNotNull($after);
        $this->assertStringContainsString('%', $after['description'], '카드에서 % 가 잘려나갔다');
        $this->assertLessThanOrEqual(20, mb_strlen($after['description']), '카드 description 20자 초과 — 발송 반려된다');
        $this->assertStringContainsString('억', $after['description']);
    }

    /** 🚫 요약칸은 금액 표기만 — % 가 새어 들어가면 K140 으로 발송 자체가 반려된다. */
    public function test_summary_line_has_no_percent(): void
    {
        $this->shippedUnpaid('33가3333', 10_000_000, 1_000_000);

        $vars = AlimtalkReceivableStatus::buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_receivable_status', $vars);

        $this->assertArrayHasKey('summary', $payload['items']['item']);
        $this->assertStringNotContainsString('%', $payload['items']['item']['summary']['description']);
        $this->assertStringNotContainsString('억', $payload['items']['item']['summary']['description']);
    }

    /** 대상이 0건이면 % 를 억지로 만들지 않는다(0% 로 오해 유발 금지). */
    public function test_no_percent_when_nothing_outstanding(): void
    {
        $vars = AlimtalkReceivableStatus::buildVars();

        $this->assertSame('0원', $vars['선적전금액']);
        $this->assertSame('0원', $vars['선적후금액']);
        $this->assertSame('0원', $vars['총판매금액']);
    }

    public function test_weekly_summary_has_the_same_treatment(): void
    {
        $this->shippedUnpaid('44가4444', 10_000_000, 2_500_000);

        // 주간요약도 % 를 붙이되 **채권 현황과 같은 분모**를 쓴다 — 금요일 숫자와 매일 숫자가 같아야 한다.
        $vars = app(AlimtalkWeeklySummary::class)->buildVars();
        $daily = AlimtalkReceivableStatus::buildVars();

        $this->assertSame('7,500,000원 (75%)', $vars['선적후금액']);
        $this->assertSame($daily['선적후금액'], $vars['선적후금액'], '주간과 채권 현황의 % 가 갈렸다');

        $payload = AlimtalkTemplates::itemListPayload('erp_weekly_summary', $vars);
        $after = collect($payload['items']['item']['list'])->firstWhere('title', '선적후 미수');
        $this->assertLessThanOrEqual(20, mb_strlen($after['description']));
        $this->assertStringContainsString('%', $after['description'], '카드에서 % 가 잘려나갔다');
        // 축약형이라 원 단위 본문값(7,500,000원)이 그대로 들어가지 않는다 — 그랬다면 20자를 넘겨 잘렸을 것.
        $this->assertStringNotContainsString('7,500,000', $after['description']);
    }

    /** 카드 전용 오버라이드 키가 본문 렌더에 새어 나가면 안 된다(배열이라 치환 대상 아님). */
    public function test_card_override_key_never_leaks_into_body(): void
    {
        $this->shippedUnpaid('55가5555', 10_000_000, 0);

        $vars = AlimtalkReceivableStatus::buildVars();
        $body = AlimtalkTemplates::render('erp_receivable_status', $vars);

        $this->assertArrayHasKey(AlimtalkTemplates::CARD_VARS_KEY, $vars);
        $this->assertStringNotContainsString('Array', $body);
        $this->assertStringNotContainsString(AlimtalkTemplates::CARD_VARS_KEY, $body);
    }

    /**
     * 🔑 핵심 — 네 항목이 **하나의 분모**(미수 차량의 판매금액)를 공유해 합이 100% 가 된다.
     * 카톡에 나란히 찍힌 % 를 사람은 반드시 더해서 읽는다(jin 2026-08-06: "9%가 비는데 이건 뭐지?").
     * 분모를 하나로 두면 그 읽기가 정답이 되고, 모수가 섞여 오독되는 일이 원천적으로 안 생긴다.
     */
    public function test_all_percentages_share_one_denominator(): void
    {
        // 선적전 미수 200만 / 선적후 미수 600만, 판매금액 합 2,000만 → 입금 1,200만
        $this->preShippingUnpaid('66가6666', 10_000_000, 8_000_000);
        $this->shippedUnpaid('77가7777', 10_000_000, 4_000_000);

        $vars = AlimtalkReceivableStatus::buildVars();

        $this->assertSame('20,000,000원', $vars['총판매금액']);
        $this->assertStringContainsString('(10%)', $vars['선적전금액']);
        $this->assertStringContainsString('(30%)', $vars['선적후금액']);
        $this->assertStringContainsString('(60%)', $vars['입금액']);
        $this->assertSame('8,000,000원', $vars['미수합계'], '합계에 % 가 붙으면 위 값들과 모수가 갈린다');
    }

    /**
     * 🚫 요약칸(미수 합계)에는 % 가 절대 들어가면 안 된다 — 금액 표기 전용이라 K140 반려(SKILLS §8 #40).
     * 08-20 개편으로 합계엔 애초에 % 를 안 붙이지만, 누가 다시 붙이면 **발송 자체가 죽으므로** 가드를 남긴다.
     */
    public function test_summary_total_never_carries_a_percent(): void
    {
        $this->shippedUnpaid('88가8888', 10_000_000, 0);

        $vars = AlimtalkReceivableStatus::buildVars();
        $payload = AlimtalkTemplates::itemListPayload('erp_receivable_status', $vars);

        $this->assertStringNotContainsString('%', $vars['미수합계']);
        $this->assertStringNotContainsString('%', $payload['items']['item']['summary']['description']);
    }
}
