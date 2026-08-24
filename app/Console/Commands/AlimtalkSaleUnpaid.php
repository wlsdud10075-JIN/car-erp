<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
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
            $rows = Vehicle::query()->action('sale_unpaid')->with('buyer')->get();
            if ($rows->isEmpty()) {
                $this->info('sale-unpaid: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $cap = 20;   // 알림톡 본문 1000자 제한 — 목록 상한. 초과분은 "외 N건", 상세는 채권관리.
            $listOf = fn ($rows) => collect($rows)->take($cap)->map(fn (Vehicle $v) => sprintf(
                '▶ %s · %s · %s원',
                $v->vehicle_number,
                $v->buyer?->name ?? '-',
                number_format((int) $v->sale_unpaid_amount_krw_cache)
            ))->implode("\n").(count($rows) > $cap ? "\n▶ 외 ".(count($rows) - $cap).'건' : '');

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_sale_unpaid', $rows);
            if (empty($targets)) {
                $this->info('sale-unpaid: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_sale_unpaid', $phone, ['미입금목록' => $listOf($mine)]);
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
