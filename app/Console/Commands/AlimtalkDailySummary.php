<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Services\LockThresholdResolver;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 대표 일일 현황 알림톡 (erp_daily_summary) — 매일 09:00.
 *
 * 🔀 2026-08-20 (jin) — **돈 이야기를 erp_receivable_status 로 분리**하고 여기는 진행 현황만 남겼다.
 *   구: 이번 달 매출 + 선적전/선적후 미수. 채권과 성격이 섞여 "오늘 뭘 밀어야 하나" 가 안 보였다.
 *   신: 이번 달 매출 + **차가 어디서 멈춰 있나** 4항목.
 *
 * 항목 정의 — 대시보드 액션(scopeAction)과 같은 출처를 쓴다. 조건을 옮겨 적으면 갈린다(SKILLS §8 #44).
 *   선적대기 = 완납 + 아직 안 떠남. ⚠️ "입금 60% 이상" 이 아니다 — 실측에서 60%↑ 15대 중 11대가
 *              이미 B/L 나온 거래완료였다(출고일만 미입력).
 *   판매중   = 선적 진입 입금률(기본 60%)에 못 미쳐 못 나가는 차. 판매대금을 더 받아야 움직인다.
 *   통관대기 = clearance_needed(완납 + 수출신고서 없음) / B/L대기 = 통관완료 + B/L 없음.
 * fire-and-forget: BizmAlimtalkService 가 게이트/미설정 시 자동 skip(로그만).
 */
class AlimtalkDailySummary extends Command
{
    protected $signature = 'alimtalk:daily-summary';

    protected $description = '대표 일일 현황 알림톡 — 이번 달 매출 + 차량 진행 현황(선적·통관·B/L 대기).';

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

    /** 템플릿 변수 산정(테스트 재사용). 매출=이번달 / 진행 현황=현재 시점(기간 무관). */
    public static function buildVars(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // 이번 달 매출 — 관리자 대시보드 방식(KRW→sale_price / 외화→×rate, 환율0 외화 제외).
        $saleRows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereBetween('sale_date', [$monthStart, $monthEnd])
            ->get(['sale_price', 'currency', 'exchange_rate']);
        $revenue = (int) $saleRows->sum(fn ($v) => $v->currency === 'KRW'
            ? (int) $v->sale_price
            : (int) ((float) $v->sale_price * (float) ($v->exchange_rate ?? 0)));

        // 선적대기 = 완납 + 아직 안 떠남(출고일도 B/L 도 없음). 돈은 다 받았고 나가기만 하면 된다.
        $waiting = Vehicle::query()->where('sale_price', '>', 0)->notDeparted()
            ->where(fn ($q) => $q->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->orWhereNull('sale_unpaid_amount_krw_cache'))
            ->count();

        // 판매중 = 아직 안 떠났고 선적 진입 입금률에 못 미치는 차. 임계는 회사별 설정(기본 60%).
        //   미수율 accessor 는 행별 computed 라 SQL 로 못 거른다 → 후보를 좁힌 뒤 PHP 로 판정한다.
        //   ⚠️ 임계는 **차량마다 그 바이어 기준**이다(jin 2026-08-21 바이어별 락). 루프 밖에서 1회
        //      계산해 넘기면 재정의가 조용히 무시된다 — buyer 를 eager load 해 추가 쿼리를 막는다.
        $selling = Vehicle::query()->where('sale_price', '>', 0)->notDeparted()
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->with('buyer')
            ->get()
            ->filter(fn (Vehicle $v) => $v->unpaid_ratio !== null
                && $v->unpaid_ratio > LockThresholdResolver::threshold($v->buyer, 'shipping_entry'));
        $sellingKrw = (int) $selling->sum('sale_unpaid_amount_krw_cache');

        // 통관·B/L 대기 — 거래완료 제외(이미 끝난 차).
        $active = fn ($q) => $q->where(fn ($q2) => $q2
            ->where('progress_status_cache', '!=', '거래완료')->orWhereNull('progress_status_cache'));
        $clearance = $active(Vehicle::query()->action('clearance_needed'))->count();
        $blWaiting = $active(Vehicle::query()->where('is_export_cleared', true)->whereNull('bl_document'))->count();

        $n = fn (int $v): string => number_format($v);

        return [
            '날짜' => now()->format('Y-m-d'),
            '판매건수' => $n($saleRows->count()),
            '매출액' => $n($revenue).'원',
            '선적대기' => $n($waiting),
            '판매중건수' => $n($selling->count()),
            '판매중금액' => $n($sellingKrw).'원',
            '통관대기' => $n($clearance),
            'BL대기' => $n($blWaiting),
            // 카드는 description 20자 컷이라 금액만 억 단위 축약(SKILLS §8 #35).
            AlimtalkTemplates::CARD_VARS_KEY => [
                '매출액' => AlimtalkTemplates::cardMoney($revenue),
                '판매중금액' => AlimtalkTemplates::cardMoney($sellingKrw),
            ],
        ];
    }
}
