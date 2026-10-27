<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 판매 미입금 안내 알림톡 (erp_sale_unpaid) — 매일 아침, 관리에게 목록형 1건.
 * 대상 = scopeAction('sale_unpaid') 단일출처(= grace 10일 유예 제외 이미 반영).
 * 차량 여러 대를 한 통에 목록으로(건건이 폭주 방지). 상세는 채권관리.
 */
class AlimtalkSaleUnpaid extends Command
{
    protected $signature = 'alimtalk:sale-unpaid';

    protected $description = '판매 미입금 차량 목록 알림톡(1건) — 관리.';

    public function handle(): int
    {
        try {
            // 🔇 꺼진 알림은 대상을 모으지도 않는다 — 「건너뜀」 행도 안 남긴다 (jin 2026-10-19, BizmAlimtalkService::canSend).
            if (! BizmAlimtalkService::active()->canSend('erp_sale_unpaid')) {
                $this->info('sale-unpaid: 꺼짐·미설정 — skip.');

                return self::SUCCESS;
            }

            $rows = Vehicle::query()->action('sale_unpaid')->with('buyer')->get();
            if ($rows->isEmpty()) {
                $this->info('sale-unpaid: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $cap = 20;   // 알림톡 본문 1000자 제한 — 목록 상한(차량 줄). 초과분은 "외 N건", 상세는 채권관리.
            // 🔀 2026-10-27 (jin, 폰 가독성) — 맨 위 「미입금 N건 · 합계」, 그 아래 ★ 바이어 › 차량번호 · 만 단위.
            //   같은 바이어 차가 여럿이면 ★ 한 번에 차량 줄만 늘어난다. 바이어 합계 큰 순, 안에서는 금액 큰 순.
            //   변수 안쪽만 바뀐다(승인 본문 무변경). 미리보기 = 2026-10-27 아티팩트 「알림톡 모바일 미리보기」.
            $listOf = function ($rows) use ($cap): string {
                $rows = collect($rows);
                $groups = $rows->groupBy(fn (Vehicle $v) => (int) ($v->buyer_id ?? 0))
                    ->sortByDesc(fn ($g) => (int) $g->sum('sale_unpaid_amount_krw_cache'));
                $lines = [sprintf('미입금 %d건 · 합계 %s', $rows->count(), AlimtalkText::man((int) $rows->sum('sale_unpaid_amount_krw_cache'))), ''];
                $shown = 0;
                foreach ($groups as $g) {
                    if ($shown >= $cap) {
                        break;
                    }
                    $lines[] = '★ '.AlimtalkText::buyerShort($g->first()->buyer?->name);
                    foreach ($g->sortByDesc('sale_unpaid_amount_krw_cache') as $v) {
                        if ($shown >= $cap) {
                            break;
                        }
                        $lines[] = sprintf('  %s · %s', $v->vehicle_number, AlimtalkText::man((int) $v->sale_unpaid_amount_krw_cache));
                        $shown++;
                    }
                }
                if ($shown < $rows->count()) {
                    $lines[] = '';
                    $lines[] = '외 '.($rows->count() - $shown).'건 → 채권관리';
                }

                return implode("\n", $lines);
            };

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_sale_unpaid', $rows);
            if (empty($targets)) {
                $this->info('sale-unpaid: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_sale_unpaid', $phone, ['미입금목록' => $listOf($mine)], ['once_per_day' => true]);
            }
            $this->info("sale-unpaid: {$rows->count()}대 → ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:sale-unpaid 실패', ['error' => $e->getMessage()]);
            $this->error('sale-unpaid 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
