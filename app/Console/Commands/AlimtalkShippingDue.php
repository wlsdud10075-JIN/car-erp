<?php

namespace App\Console\Commands;

use App\Models\UnpaidExportOverride;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Services\LockThresholdResolver;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use App\Support\AlimtalkText;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 선적 임박 미수 요약 알림톡 (erp_shipping_due) — 평일, 관리에게 **한 통**.
 * 조건 = 선적일(shipping_date) 5일 이내 & 미완납 & B/L 미발급.
 *
 * 🔀 2026-11-16 (jin) — 배(VSL)+선적일 묶음마다 **대표 차 1대 + 「외 N대」** 한 줄, 우회 사유는 뺀다.
 *   경위: 같은 날 오전 ①차 20대 × 3줄(+긴 사유) 1,688자 → BizM E112 로 수신자 전원 실패 ②글자 수로 자르고 2통으로 나눴더니
 *   jin «09시에 4~6통 받는데 2통이면 밀려서 안 본다» → 「무엇이 · 언제 · 몇 대」만 한 통에, 차 목록은 채권관리.
 *   우회 진행(선적 진입 기준 미달 + 진입 우회 승인) 차는 묶음 줄에 「우회 N」으로 센다 — 기준 = 운영 설정 + 바이어별 재정의(LockThresholdResolver).
 *   ⚠️ 본문 끝 안내문의 「[50%미만]」은 BizM 승인 고정문구라 다음 재등록 때 고친다(jin «설정 문장은 변수로»).
 */
class AlimtalkShippingDue extends Command
{
    protected $signature = 'alimtalk:shipping-due';

    protected $description = '선적일 5일 전 미완납 차량 — 배별 한 줄 알림톡 — 관리.';

    /** BizM 알림톡 본문 상한(글자). 넘으면 E112:OverLimitMessageLength 로 통째 반려된다. */
    public const MAX_MESSAGE_CHARS = 1000;

    /** 「\n\n외 NN척 NNN대 → 채권관리」 꼬리 자리 */
    private const RESERVE_CHARS = 30;

    public function handle(): int
    {
        try {
            // 🔇 꺼진 알림은 대상을 모으지도 않는다 — 「건너뜀」 행도 안 남긴다 (jin 2026-10-19, BizmAlimtalkService::canSend).
            if (! BizmAlimtalkService::active()->canSend('erp_shipping_due')) {
                $this->info('shipping-due: 꺼짐·미설정 — skip.');

                return self::SUCCESS;
            }

            $today = now()->startOfDay();
            $rows = Vehicle::query()
                ->where('sales_channel', 'export')
                ->whereNotNull('shipping_date')
                ->whereBetween('shipping_date', [$today->toDateString(), $today->copy()->addDays(5)->toDateString()])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->whereNull('bl_document')
                ->with('buyer')
                ->get();

            if ($rows->isEmpty()) {
                $this->info('shipping-due: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $bypassIds = self::bypassIds($rows);
            $budget = self::MAX_MESSAGE_CHARS - mb_strlen(AlimtalkTemplates::render('erp_shipping_due', ['선적미수목록' => '']));

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_shipping_due', $rows);
            if (empty($targets)) {
                $this->info('shipping-due: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_shipping_due', $phone, ['선적미수목록' => self::listText(collect($mine), $bypassIds, $budget, $today)], ['once_per_day' => true]);
            }
            $this->info("shipping-due: {$rows->count()}대, ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:shipping-due 실패', ['error' => $e->getMessage()]);
            $this->error('shipping-due 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 우회 진행 차 id — 선적 진입 기준 미달(그 바이어의 필요 입금률)인데 진입 우회(clearance/shipping) 승인이 있는 차.
     *
     * @return array<int, true>
     */
    public static function bypassIds(Collection $rows): array
    {
        $under = $rows->filter(fn (Vehicle $v) => $v->unpaid_ratio !== null && $v->unpaid_ratio > LockThresholdResolver::threshold($v->buyer, 'shipping_entry'))->pluck('id');
        if ($under->isEmpty()) {
            return [];
        }

        return array_fill_keys(UnpaidExportOverride::query()->whereIn('vehicle_id', $under)->whereIn('stage', ['clearance', 'shipping'])
            ->distinct()->pluck('vehicle_id')->all(), true);
    }

    /**
     * 「선적미수목록」 변수 — 머리줄 + 배별 두 줄, 글자 예산 안에서. 넘치면 「외 N척 M대 → 채권관리」.
     *
     *   ⚠ 우회 진행 33대 포함 · 전체 76대
     *
     *   🚢 GLOVIS SUNRISE · 11/18 (D-2)
     *     Korea Auto 27거2011 외 11대 (우회 5)
     */
    public static function listText(Collection $rows, array $bypassIds, int $budget, $today): string
    {
        $bypassTotal = $rows->filter(fn (Vehicle $v) => isset($bypassIds[$v->id]))->count();
        $head = ($bypassTotal > 0 ? '⚠ 우회 진행 '.$bypassTotal.'대 포함 · ' : '').'전체 '.$rows->count().'대';

        $groups = $rows
            ->groupBy(fn (Vehicle $v) => (trim((string) $v->vessel_name) !== '' ? trim((string) $v->vessel_name) : '선박 미입력').'|'.$v->shipping_date->toDateString())
            ->sortBy(fn ($g, string $key) => [$g->first()->shipping_date->toDateString(), $key]);

        $cap = $budget - self::RESERVE_CHARS;
        $text = $head;
        $shownShips = 0;
        $shownCars = 0;
        foreach ($groups as $g) {
            $v0 = $g->first();
            // 대표 차 = 미수(원화) 가장 큰 차, 같으면 우회 차 먼저
            $rep = $g->sortBy([
                fn (Vehicle $a, Vehicle $b) => (int) $b->sale_unpaid_amount_krw_cache <=> (int) $a->sale_unpaid_amount_krw_cache,
                fn (Vehicle $a, Vehicle $b) => (int) isset($bypassIds[$b->id]) <=> (int) isset($bypassIds[$a->id]),
            ])->first();
            $bypass = $g->filter(fn (Vehicle $v) => isset($bypassIds[$v->id]))->count();
            $ship = trim((string) $v0->vessel_name) !== '' ? trim((string) $v0->vessel_name) : '선박 미입력';
            $dday = max(0, (int) $today->diffInDays($v0->shipping_date, false));
            $block = "\n\n".sprintf('🚢 %s · %s (D-%d)', $ship, $v0->shipping_date->format('m/d'), $dday)
                ."\n  ".trim(AlimtalkText::buyerShort($rep->buyer?->name).' '.$rep->vehicle_number)
                .($g->count() > 1 ? ' 외 '.($g->count() - 1).'대' : '')
                .($bypass > 0 ? ' (우회 '.$bypass.')' : '');
            if (mb_strlen($text.$block) > $cap) {
                break;
            }
            $text .= $block;
            $shownShips++;
            $shownCars += $g->count();
        }
        if ($shownShips < $groups->count()) {
            $text .= "\n\n외 ".($groups->count() - $shownShips).'척 '.($rows->count() - $shownCars).'대 → 채권관리';
        }

        return $text;
    }
}
