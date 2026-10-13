<?php

namespace App\Console\Commands;

use App\Http\Controllers\CapitalReportController;
use App\Models\AlimtalkLog;
use App\Services\BizmAlimtalkService;
use App\Services\CapitalStatusService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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
                // 🚨 안 보낸 이유를 **알림톡 로그 화면에 남긴다**. laravel.log 에만 남기면 아무도 못 본다 —
                //    대표 보고가 조용히 멈추는 게 이 프로젝트의 반복 사고 형태다(SKILLS §8 #38).
                $reason = self::$lastSkipReason ?? '통장 잔액 미입력';
                foreach ($recipients as $phone) {
                    AlimtalkLog::create([
                        'template_code' => 'erp_capital_weekly',
                        'phone' => preg_replace('/[^0-9]/', '', $phone),
                        'status' => 'skipped',
                        'error' => $reason,
                    ]);
                }
                $this->info("capital-weekly: {$reason} — skip.");

                return self::SUCCESS;
            }

            // 보고서 링크 버튼 (안건4 3단계) — 대표가 로그인 없이 눌러 항목을 펼쳐 본다.
            //   스냅샷 기준일로 서명, 만료는 CapitalReportController::LINK_TTL_DAYS.
            //   payout 승인 버튼과 동일 형식(button1 = name/type WL/url_mobile/url_pc).
            $buttons = [];
            if ($snap = app(CapitalStatusService::class)->latest()) {
                $buttons[] = [
                    'name' => '자금 보고 보기',
                    'url' => URL::temporarySignedRoute(
                        'capital.report',
                        now()->addDays(CapitalReportController::LINK_TTL_DAYS),
                        ['date' => $snap->snapshot_date->format('Y-m-d')],
                    ),
                ];
            }

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_capital_weekly', $phone, $vars, [], $buttons);
            }
            $this->info('capital-weekly: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:capital-weekly 실패', ['error' => $e->getMessage()]);
            $this->error('capital-weekly 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** 직전 buildVars() 가 빈 배열을 돌려준 이유(사람이 읽는 한글) — handle() 이 로그 화면에 남긴다. */
    public static ?string $lastSkipReason = null;

    /** 최신 스냅샷 → 템플릿 변수(억 단위 포맷). 보낼 수 없으면 빈 배열 + $lastSkipReason. 테스트 재사용. */
    public static function buildVars(): array
    {
        self::$lastSkipReason = null;
        $svc = app(CapitalStatusService::class);
        $d = $svc->derive($svc->latest());
        if (! ($d['has_data'] ?? false)) {
            self::$lastSkipReason = '통장 잔액 미입력';

            return [];
        }

        $eok = fn ($n) => $n === null ? '—'
            : (abs($n / 1e8) >= 10 ? number_format($n / 1e8, 1) : number_format($n / 1e8, 2)).'억';
        // 「원금 대비 손익」은 **정상 회수 기준**으로 보낸다 (jin 2026-07-31).
        //   청산 기준(profit_krw)은 "바이어가 한 명도 안 갚는다"는 파산 가정이라, 카톡에 그 값만 찍히면
        //   매주 큰 마이너스가 와서 실제 상태를 오해하게 된다. 구 스냅샷은 청산 기준으로 폴백.
        $profit = $d['net_profit_krw'] ?? $d['profit_krw'];

        // 🔀 2026-10-13 (jin) — 손익이 요약정보 칸에서 **아이템 줄**로 옮겨졌다(AlimtalkTemplates::ITEMLIST 주석).
        //    요약정보는 숫자·통화단위·쉼표·마침표만 허용이라 손실(음수)과 '원금 미설정'을 표기할 수 없어
        //    2026-09-14~10-12 ssancarerp 6주 연속 보류였다. 아이템 줄은 부호·한글이 통과하므로(실측 '-5.99억' sent)
        //    이제 다른 항목과 같은 억 단위로, 손실은 '-' 를 붙여 보낸다. 보류 분기는 제거.
        //    ⚠️ 3사 BizM 등록본이 아이템 5줄로 재승인된 뒤에야 나간다 — 구 등록본(요약정보 있음)엔 K108/K140.

        return [
            '기준일' => Carbon::parse($d['date'])->format('Y-m-d'),
            '통장현금' => $eok($d['cash_krw']),
            // ⚠️ 굴리는자금(순자산)의 구성요소인 **미판매 재고**를 쓴다 (jin 2026-07-31).
            //   선적 전 재고(inventory_krw)를 찍으면 "통장+재고+미수−미지급"이 굴리는자금과 안 맞아
            //   대표가 카톡에서 항목을 더해봤을 때 계산이 틀린 것처럼 보인다. 구 스냅샷은 폴백.
            '재고' => $eok($d['unsold_inventory_krw'] ?? $d['inventory_krw']),
            '미수' => $eok($d['receivable_krw']),
            '미지급' => $eok($d['payable_krw']),
            '굴리는자금' => $eok($d['working_capital_krw']),
            // 아이템 description 은 20자 컷(AlimtalkTemplates::ITEM_DESC_MAX) — '-12.3억' 은 넉넉하다.
            '손익' => $profit === null ? '원금 미설정' : (($profit < 0 ? '-' : '').$eok(abs((int) $profit))),
        ];
    }
}
