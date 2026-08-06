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

        // 미수율 % (jin 2026-08-06) — SKILLS §13 집계식. ⚠️ **분모를 선적전·선적후 합계로 통일**한다.
        //   각자 분모로 내면 모수가 달라 더할 수 없는 숫자인데, 카톡에 나란히 찍히면 사람은 더해서 읽는다
        //   (실제로 31%+60%=91% 로 오독됐다). 통일하면 두 % 의 합이 곧 전체 미수율이라 그 읽기가 맞아떨어진다.
        //   소수 1자리 유지 — 정수로 반올림하면 합이 전체율과 어긋난다(7.8+44.8=52.6 vs 8+45=53).
        $denomKrw = Vehicle::aggregateSaleTotalKrw(Vehicle::query()->action('receivable_before_shipping'))
            + Vehicle::aggregateSaleTotalKrw(Vehicle::query()->action('receivable_after_shipping'));
        $beforePct = Vehicle::aggregateUnpaidRatioPct(Vehicle::query()->action('receivable_before_shipping'), $denomKrw);
        $afterPct = Vehicle::aggregateUnpaidRatioPct(Vehicle::query()->action('receivable_after_shipping'), $denomKrw);
        $totalPct = $denomKrw > 0 ? round(($beforeSum + $afterSum) / $denomKrw * 100, 1) : null;
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
            '미수합계' => number_format($beforeSum + $afterSum).'원'.$pct($totalPct),
            AlimtalkTemplates::CARD_VARS_KEY => [
                '선적전금액' => AlimtalkTemplates::cardMoney($beforeSum).$pct($beforePct),
                '선적후금액' => AlimtalkTemplates::cardMoney($afterSum).$pct($afterPct),
                // 🚫 카드 요약칸은 **금액 표기만** 허용 — % 가 들어가면 K140 으로 발송 반려된다(SKILLS §8 #40).
                '미수합계' => number_format($beforeSum + $afterSum).'원',
            ],
        ];
    }
}
