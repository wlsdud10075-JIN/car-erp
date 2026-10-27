<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkText;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 도착 후 미수 요약 알림톡 (erp_arrived_unpaid_weekly) — 매주 월 09:00, 관리에게 바이어별 묶음 1건 (jin 2026-10-26 C안).
 *
 * 도착예정일이 지나면 「도착 임박 잔금 안내」가 끊기고 채권 등급 「심각」으로만 남는다 — 그 뒤에도 남은 미수를 매주 한 번 보여 준다.
 * 조건 = 운항 「도착예정」(선적일+ETA, ETA ≤ 오늘 — `Vehicle::scopeSailing('arrived')` 단일 출처) & 잔금 미완납.
 *   B/L 을 올렸든 안 올렸든 넣는다(우회로 B/L 까지 나간 미수는 더 급하다). 거래완료(완납)는 미수 0 이라 저절로 빠진다.
 * 묶음 = 바이어별(대수·합계·가장 오래된 도착일), 합계 큰 순. 차량 한 대씩 보내면 수십 줄이라 한 통에 안 들어간다.
 */
class AlimtalkArrivedUnpaidWeekly extends Command
{
    protected $signature = 'alimtalk:arrived-unpaid-weekly';

    protected $description = '도착예정일이 지났는데 잔금이 남은 차량을 바이어별로 묶어 알림톡 — 관리, 주 1회.';

    /** 본문 1000자 — 바이어 줄 상한. 초과분은 "외 N곳 · M대". */
    public const MAX_BUYER_LINES = 15;

    public function handle(): int
    {
        try {
            if (! BizmAlimtalkService::active()->canSend('erp_arrived_unpaid_weekly')) {
                $this->info('arrived-unpaid-weekly: 꺼짐·미설정 — skip.');

                return self::SUCCESS;
            }

            $rows = Vehicle::query()
                ->where('sales_channel', 'export')
                ->sailing('arrived')
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->with('buyer')
                ->get();

            if ($rows->isEmpty()) {
                $this->info('arrived-unpaid-weekly: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $targets = AlimtalkRecipients::scopedFor('erp_arrived_unpaid_weekly', $rows);
            if (empty($targets)) {
                $this->info('arrived-unpaid-weekly: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_arrived_unpaid_weekly', $phone, ['요약목록' => self::buildList(collect($mine))], ['once_per_day' => true]);
            }
            $this->info("arrived-unpaid-weekly: {$rows->count()}대 → ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:arrived-unpaid-weekly 실패', ['error' => $e->getMessage()]);
            $this->error('arrived-unpaid-weekly 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 바이어별 묶음 — 합계 큰 순. 🔀 2026-10-27 (jin, 폰 가독성) — 「최장」 대신 바이어명 옆에 ETA(그 바이어 차 중 가장 먼저 도착한 날),
     *   줄 바꿔 대수·금액·며칠 지났는지. 변수 안쪽만 바뀐다(승인 본문 무변경). 미리보기 = 2026-10-27 아티팩트 「알림톡 모바일 미리보기」.
     *
     *   ★ {바이어} · ETA {월/일}
     *     {n}대 · {만} · {N}일 지남
     *
     * @param  Collection<int, Vehicle>  $rows
     */
    public static function buildList(Collection $rows): string
    {
        $today = now()->startOfDay();
        $groups = $rows->groupBy(fn (Vehicle $v) => (int) ($v->buyer_id ?? 0))
            ->map(fn (Collection $g) => [
                'name' => AlimtalkText::buyerShort($g->first()->buyer?->name ?? '바이어 미지정'),
                'n' => $g->count(),
                'sum' => (int) $g->sum('sale_unpaid_amount_krw_cache'),
                'oldest' => $g->min('eta_date'),
            ])
            ->sortByDesc('sum')
            ->values();

        $lines = $groups->take(self::MAX_BUYER_LINES)
            ->map(function (array $g) use ($today) {
                $oldest = $g['oldest'];
                $since = $oldest ? max(0, (int) $oldest->copy()->startOfDay()->diffInDays($today)) : 0;

                return sprintf("★ %s · ETA %s\n  %d대 · %s · %d일 지남",
                    $g['name'], $oldest ? $oldest->format('m/d') : '-', $g['n'], AlimtalkText::man($g['sum']), $since);
            })
            ->all();
        if ($groups->count() > self::MAX_BUYER_LINES) {
            $rest = $groups->slice(self::MAX_BUYER_LINES);
            $lines[] = '';
            $lines[] = '외 '.$rest->count().'곳 '.$rest->sum('n').'대 → 채권관리';
        }

        return implode("\n", $lines);
    }
}
