<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkText;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ETA 잔금 완납 필요 알림톡 (erp_eta_balance_due) — 매일, 관리에게 목록형 1건.
 * 조건 = 도착(eta_date) N일 이내 & 잔금 미완납(sale_unpaid_amount_krw_cache > 0) & B/L 미발급.
 *   N = 기능설정 「ETA 임박 일수」(`alarm_eta_lead_days`, 기본 10) — 대시보드 ETA 임박 알람과 **같은 값**(jin 2026-10-26, 구: 7일 고정).
 *
 * 🔀 2026-10-26 (jin «도착 10일 전인데 미수금이 남아 있는 운항중 VSL 안에 바이어의 미수금을 표시») — 목록을 **배(선박명)별로 묶고**
 *   머리줄에 그 배의 미수 대수·합계, 차량 줄에 [100% 미수]·[우회 승인]·[선적대기 허용 항로] 표시와 바이어 합계를 붙인다.
 *   알바니아 RORO 처럼 선적대기 허용 항로는 미수 100% 로도 승인 없이 실리고 회사는 선적사 RORO 리스트를 넣을 때 그걸 안다 —
 *   도착 전에 「이 배에 100% 미수 차가 있다」를 관리자가 보게 하려는 것.
 *   ⚠️ 등록 템플릿은 `#{잔금목록}` 한 변수라 **그 안만 바뀐다**(BizM 재승인 없음, §8 #40). 제목·첫 줄·끝 줄은 손대지 않는다.
 */
class AlimtalkEtaBalance extends Command
{
    protected $signature = 'alimtalk:eta-balance';

    protected $description = 'ETA(도착) 임박 잔금 미완납 차량 알림톡 — 관리. 배별 묶음.';

    /** 알림톡 본문 1000자 — 목록 상한(차량 줄 수). 초과분은 "외 N대", 상세는 채권관리. */
    public const MAX_VEHICLE_LINES = 20;

    public static function leadDays(): int
    {
        return max(0, (int) Setting::get('alarm_eta_lead_days', 10));
    }

    public function handle(): int
    {
        try {
            // 🔇 꺼진 알림은 대상을 모으지도 않는다 — 「건너뜀」 행도 안 남긴다 (jin 2026-10-19, BizmAlimtalkService::canSend).
            if (! BizmAlimtalkService::active()->canSend('erp_eta_balance_due')) {
                $this->info('eta-balance: 꺼짐·미설정 — skip.');

                return self::SUCCESS;
            }

            $today = now()->startOfDay();
            $rows = Vehicle::query()
                ->where('sales_channel', 'export')
                ->whereNotNull('eta_date')
                ->whereBetween('eta_date', [$today->toDateString(), $today->copy()->addDays(self::leadDays())->toDateString()])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->whereNull('bl_document')
                ->with(['buyer', 'dischargePort', 'unpaidExportOverrides'])
                ->get();

            if ($rows->isEmpty()) {
                $this->info('eta-balance: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_eta_balance_due', $rows);
            if (empty($targets)) {
                $this->info('eta-balance: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_eta_balance_due', $phone, ['잔금목록' => self::buildList(collect($mine), $today)], ['once_per_day' => true]);
            }
            $this->info("eta-balance: {$rows->count()}대 → ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:eta-balance 실패', ['error' => $e->getMessage()]);
            $this->error('eta-balance 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 배(선박명)별 묶음 본문 — 도착일 빠른 배부터. 선박명이 비면 「선박 미입력」 묶음.
     * 🔀 2026-10-27 (jin, 폰 가독성) — 배 안에서 **바이어로 한 번 더** 묶는다. 바이어명이 차량마다 반복되지 않고,
     *   바이어 합계는 ★ 줄에(2대 이상일 때만 — 1대면 차량 줄 금액과 같다). 원 → 만 단위, 날짜 월/일, 꼬리표는 있을 때만 차량 줄 아래 한 줄.
     *   미리보기 = 2026-10-27 아티팩트 「알림톡 모바일 미리보기」. 변수 안쪽만 바뀐다(승인 본문 무변경, §8 #40).
     *
     *   🚢 {선박}
     *   도착 {월/일} (D-n) · {k}대 · {만}
     *   ★ {바이어} · {m}대 · {만}        ← m ≥ 2 일 때만 숫자
     *     {차량번호} · {미수 만}
     *     ⚠100%미수 ·우회승인 ·대기항로   ← 있을 때만
     *
     * @param  Collection<int, Vehicle>  $rows
     */
    public static function buildList(Collection $rows, CarbonInterface $today): string
    {
        $groups = $rows->groupBy(fn (Vehicle $v) => trim((string) $v->vessel_name) !== '' ? trim((string) $v->vessel_name) : '선박 미입력')
            ->sortBy(fn (Collection $g, string $name) => [optional($g->min('eta_date'))->format('Y-m-d') ?? '9999', $name]);

        $lines = [];
        $shown = 0;
        $total = $rows->count();
        foreach ($groups as $vessel => $g) {
            if ($shown >= self::MAX_VEHICLE_LINES) {
                break;
            }
            $eta = $g->min('eta_date');
            $daysLeft = $eta ? max(0, $today->diffInDays($eta, false)) : 0;
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '🚢 '.$vessel;
            $lines[] = sprintf('도착 %s (D-%d) · %d대 · %s', $eta ? $eta->format('m/d') : '-', $daysLeft, $g->count(),
                AlimtalkText::man((int) $g->sum('sale_unpaid_amount_krw_cache')));

            $byBuyer = $g->groupBy(fn (Vehicle $v) => (int) ($v->buyer_id ?? 0))
                ->sortByDesc(fn (Collection $bg) => (int) $bg->sum('sale_unpaid_amount_krw_cache'));
            foreach ($byBuyer as $bg) {
                if ($shown >= self::MAX_VEHICLE_LINES) {
                    break;
                }
                $name = AlimtalkText::buyerShort($bg->first()->buyer?->name);
                $lines[] = $bg->count() >= 2
                    ? sprintf('★ %s · %d대 · %s', $name, $bg->count(), AlimtalkText::man((int) $bg->sum('sale_unpaid_amount_krw_cache')))
                    : '★ '.$name;
                foreach ($bg->sortByDesc('sale_unpaid_amount_krw_cache') as $v) {
                    if ($shown >= self::MAX_VEHICLE_LINES) {
                        break;
                    }
                    $lines[] = sprintf('  %s · %s', $v->vehicle_number, AlimtalkText::man((int) $v->sale_unpaid_amount_krw_cache));
                    if (($f = self::flags($v)) !== '') {
                        $lines[] = '  '.$f;
                    }
                    $shown++;
                }
            }
        }
        if ($shown < $total) {
            $lines[] = '';
            $lines[] = '외 '.($total - $shown).'대 → 채권관리 ⚓도착예정';
        }

        return implode("\n", $lines);
    }

    /** 차량 줄 꼬리표 — 사실상 전액 미수 · 우회 승인 이력 · 선적대기 허용 항로(승인 없이 통과). 없으면 빈 문자열. 🔀 10-27 짧은 표기(폰). */
    public static function flags(Vehicle $v): string
    {
        $out = [];
        $rate = (float) ($v->exchange_rate ?? 0);
        $totalKrw = $rate > 0 ? (float) $v->sale_total_amount * $rate : 0.0;
        if ($totalKrw > 0 && (float) $v->sale_unpaid_amount_krw_cache >= 0.99 * $totalKrw) {
            $out[] = '⚠100%미수';
        }
        if ($v->relationLoaded('unpaidExportOverrides') ? $v->unpaidExportOverrides->isNotEmpty() : $v->unpaidExportOverrides()->exists()) {
            $out[] = '·우회승인';
        }
        if ($v->isShippingWaitRoute()) {
            $out[] = '·대기항로';
        }

        return $out === [] ? '' : implode(' ', $out);
    }
}
