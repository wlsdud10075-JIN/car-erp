<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 대표 일일 현황 알림톡 (erp_daily_summary) — 매일 09:00.
 * 이번 달 누적 매출 + 현재 시점 미수(선적 전/후). 정의 = 관리자 대시보드 매출 · 채권 스코프 단일출처.
 *   선적전 미수는 scopeAction('receivable_before_shipping') = grace 제외 반영(판매일+10일 미경과 제외).
 * fire-and-forget: BizmAlimtalkService 가 게이트/미설정 시 자동 skip(로그만).
 */
class AlimtalkDailySummary extends Command
{
    protected $signature = 'alimtalk:daily-summary';

    protected $description = '대표 일일 현황 알림톡 — 이번 달 매출 + 현재 미수(선적 전/후).';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::forBroadcast('erp_daily_summary');
            if (empty($recipients)) {
                $this->info('daily-summary: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            $vars = self::buildVars();

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_daily_summary', $phone, $vars);
            }
            $this->info('daily-summary: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:daily-summary 실패', ['error' => $e->getMessage()]);
            $this->error('daily-summary 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** 템플릿 변수 산정(테스트 재사용). 매출=이번달 / 미수=현재 시점. */
    public static function buildVars(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // 이번 달 매출 — 관리자 대시보드 방식(KRW→sale_price / 외화→×rate, 환율0 외화 제외).
        $saleRows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereBetween('sale_date', [$monthStart, $monthEnd])
            ->get(['sale_price', 'currency', 'exchange_rate']);
        $saleCount = $saleRows->count();
        $revenue = (int) $saleRows->sum(fn ($v) => $v->currency === 'KRW'
            ? (int) $v->sale_price
            : (int) ((float) $v->sale_price * (float) ($v->exchange_rate ?? 0)));

        // 현재 미수 — 채권 스코프 단일출처. 선적전=receivable_before_shipping(grace 제외), 선적후=즉시.
        $beforeQ = Vehicle::query()->action('receivable_before_shipping');
        $afterQ = Vehicle::query()->action('receivable_after_shipping');
        $beforeCount = (clone $beforeQ)->count();
        $beforeSum = (int) (clone $beforeQ)->sum('sale_unpaid_amount_krw_cache');
        $afterCount = (clone $afterQ)->count();
        $afterSum = (int) (clone $afterQ)->sum('sale_unpaid_amount_krw_cache');

        // 비중 % (jin 2026-08-20) — **미수 총액을 100 으로 놓고 쪼갠 구성비**다.
        //   "미수 9.61억 중 선적전이 26%, 선적후가 74%" — jin 이 처음부터 자연스럽게 읽은 방식이고,
        //   두 % 의 합이 100 이라 나란히 놓고 더해 읽어도 맞다.
        //
        // 🔀 구: 분모가 **미수 차량의 판매총액**(19.4억)이라 12.9% / 36.6% 이 나왔다. 그 분모는
        //   화면 어디에도 없어서 대조가 안 됐고, jin 본인도 "이게 어떻게 나오는 수치야?" 로 물었다.
        //   ⚠️ 그 값(합 49.5% = 미수 차들의 평균 미납률)은 버린 게 아니라 **채권관리 KPI 「미납률」**
        //   카드로 옮겼다 — 알림톡에서 사라진 숫자를 화면에서 찾을 수 있어야 한다.
        //   소수 1자리 유지 — 정수로 반올림하면 합이 100 에서 어긋난다(26+74 는 맞지만 33.3+66.7 은 깨진다).
        $unpaidTotal = $beforeSum + $afterSum;
        $share = fn (int $part): ?float => $unpaidTotal > 0 ? round($part / $unpaidTotal * 100, 1) : null;
        $beforePct = $share($beforeSum);
        $afterPct = $share($afterSum);
        $pct = fn (?float $p): string => $p === null ? '' : ' ('.$p.'%)';

        // 카드(아이템리스트)는 description 20자 컷이라 본문과 같은 문자열을 쓰면 % 가 잘린다.
        //   → 본문은 정확한 원 단위, 카드는 억 단위 축약. 등록본 고정 문구는 그대로라 재등록 불필요.
        //   (요약칸 '미수합계' 는 **금액 표기만** 허용이라 % 를 넣지 않는다 — K140 반려. SKILLS §8 #40)

        return [
            '날짜' => now()->format('Y-m-d'),
            '판매건수' => number_format($saleCount),
            '매출액' => number_format($revenue).'원',
            '선적전건수' => number_format($beforeCount),
            '선적전금액' => number_format($beforeSum).'원'.$pct($beforePct),
            '선적후건수' => number_format($afterCount),
            '선적후금액' => number_format($afterSum).'원'.$pct($afterPct),
            // 합계에 전체 미수율을 붙여 "두 % 를 더하면 이 값" 임을 눈으로 확인시킨다.
            // 합계엔 % 를 붙이지 않는다 — 구성비의 합은 늘 100% 라 정보가 0 이고,
            //   여기에만 다른 분모(미납률)를 쓰면 위 두 줄과 모수가 달라져 또 섞여 읽힌다.
            '미수합계' => number_format($beforeSum + $afterSum).'원',
            AlimtalkTemplates::CARD_VARS_KEY => [
                '선적전금액' => AlimtalkTemplates::cardMoney($beforeSum).$pct($beforePct),
                '선적후금액' => AlimtalkTemplates::cardMoney($afterSum).$pct($afterPct),
                // 🚫 카드 요약칸은 **금액 표기만** 허용 — % 가 들어가면 K140 으로 발송 반려된다(SKILLS §8 #40).
                '미수합계' => number_format($beforeSum + $afterSum).'원',
            ],
        ];
    }
}
