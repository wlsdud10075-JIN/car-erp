<?php

namespace App\Console\Commands;

use App\Services\BizmAlimtalkService;
use App\Services\CapitalStatusService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 대표 주간 자금/손익 보고 알림톡 (erp_capital_weekly) — 매주 월요일 09:00.
 *   최신 CashSnapshot 기준 통장현금·재고·미수·미지급·굴리는자금·손익. 수신자 = 대표(admin) 전용(자본 기밀).
 * fire-and-forget: BizmAlimtalkService 가 게이트/미설정 시 자동 skip(로그만). 통장 미입력이면 스냅샷 없음 → skip.
 */
class AlimtalkCapitalWeekly extends Command
{
    protected $signature = 'alimtalk:capital-weekly';

    protected $description = '대표 주간 자금 현황 알림톡 — 통장현금·재고·미수·미지급·손익.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::forBroadcast('erp_capital_weekly');
            if (empty($recipients)) {
                $this->info('capital-weekly: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            $vars = self::buildVars();
            if (empty($vars)) {
                $this->info('capital-weekly: 통장 스냅샷 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_capital_weekly', $phone, $vars);
            }
            $this->info('capital-weekly: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:capital-weekly 실패', ['error' => $e->getMessage()]);
            $this->error('capital-weekly 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** 최신 스냅샷 → 템플릿 변수(억 단위 포맷). 스냅샷 없으면 빈 배열. 테스트 재사용. */
    public static function buildVars(): array
    {
        $svc = app(CapitalStatusService::class);
        $d = $svc->derive($svc->latest());
        if (! ($d['has_data'] ?? false)) {
            return [];
        }

        $eok = fn ($n) => $n === null ? '—'
            : (abs($n / 1e8) >= 10 ? number_format($n / 1e8, 1) : number_format($n / 1e8, 2)).'억';
        $profit = $d['profit_krw'];

        return [
            '기준일' => Carbon::parse($d['date'])->format('Y-m-d'),
            '통장현금' => $eok($d['cash_krw']),
            '재고' => $eok($d['inventory_krw']),
            '미수' => $eok($d['receivable_krw']),
            '미지급' => $eok($d['payable_krw']),
            '굴리는자금' => $eok($d['working_capital_krw']),
            '손익' => $profit === null ? '원금 미설정'
                : ($profit >= 0 ? '+' : '−').$eok(abs($profit)),
        ];
    }
}
