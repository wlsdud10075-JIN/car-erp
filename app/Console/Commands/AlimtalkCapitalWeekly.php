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
            // 🔇 꺼진 알림은 대상을 모으지도 않는다 — 「건너뜀」 행도 안 남긴다 (jin 2026-10-19, BizmAlimtalkService::canSend).
            if (! BizmAlimtalkService::active()->canSend('erp_capital_weekly')) {
                $this->info('capital-weekly: 꺼짐·미설정 — skip.');

                return self::SUCCESS;
            }

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

            $buttons = self::reportButtons();

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

    /**
     * 보고서 링크 버튼 (안건4 3단계) — 대표가 로그인 없이 눌러 항목을 펼쳐 본다.
     *   스냅샷 기준일로 서명, 만료는 CapitalReportController::LINK_TTL_DAYS. payout 승인 버튼과 동일 형식.
     * 🔗 **테스트 발송도 이걸 실어야 한다** — 등록본에 버튼이 있어 버튼 없이 보내면 K108(2026-11-02 로컬 실측).
     *   스냅샷이 없으면 [] (그때는 buildVars 도 비어 발송 자체가 skip 이다).
     */
    public static function reportButtons(): array
    {
        $snap = app(CapitalStatusService::class)->latest();
        if (! $snap) {
            return [];
        }

        return [[
            'name' => '자금 보고 보기',
            'url' => URL::temporarySignedRoute(
                'capital.report',
                now()->addDays(CapitalReportController::LINK_TTL_DAYS),
                ['date' => $snap->snapshot_date->format('Y-m-d')],
            ),
        ]];
    }

    /** 직전 buildVars() 가 빈 배열을 돌려준 이유(사람이 읽는 한글) — handle() 이 로그 화면에 남긴다. */
    public static ?string $lastSkipReason = null;

    /**
     * 최신 스냅샷 → 템플릿 변수. 보낼 수 없으면 빈 배열 + $lastSkipReason. 테스트 재사용.
     *
     * 🧾 2026-11-02 (대표 «자산 = 자본 + 부채») — 「총자산 = 자본 + 부채」 세 값 + 변동. 구 6값(굴리는자금·통장현금·재고·미수·미지급·손익)은
     *   같은 코드의 수정 재등록으로 대체됐다(jin «v2 를 따로 두지 말자»). 원금 미설정이어도 보낸다 — 자본은 원금과 무관.
     *   - 총자산 = total_assets_krw(현금+미판매재고+미수+보증금) → 강조 제목 '총자산 80.0억'
     *   - 자본 = working_capital_krw(순자산 = 총자산 − 부채) · 부채 = liabilities_krw(미지급 + 가수금)
     *   - 변동 = 7일 이상 앞선 스냅샷 대비(compare()) — 값 뒤 ' (+0.30억)'. 비교 대상 없으면(첫 주) 값만.
     *     등록본의 고정 문구·항목은 그대로이고 값만 길어지므로 변동 표기 자체는 재등록 사유가 아니다(괄호·부호 11-02 로컬 실측 sent).
     */
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
        $cmp = $svc->compare($svc->latest());
        $withDelta = function (string $value, string $key) use ($cmp, $eok): string {
            $v = $cmp['delta'][$key] ?? null;
            if ($v === null) {
                return $value;
            }

            return $value.' ('.($v < 0 ? '-' : '+').$eok(abs((int) $v)).')';
        };

        return [
            '기준일' => Carbon::parse($d['date'])->format('Y-m-d'),
            '총자산' => '총자산 '.$eok($d['total_assets_krw']),
            '자본' => $withDelta($eok($d['working_capital_krw']), 'working_capital_krw'),
            '부채' => $withDelta($eok($d['liabilities_krw']), 'liabilities_krw'),
        ];
    }
}
