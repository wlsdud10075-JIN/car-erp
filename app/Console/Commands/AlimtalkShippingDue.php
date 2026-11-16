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
use Illuminate\Support\Facades\Log;

/**
 * 선적 임박 미수 요약 알림톡 (erp_shipping_due) — 매일, 관리에게 목록형 1건.
 * 조건 = 선적일(shipping_date) 5일 이내 & 미완납 & B/L 미발급.
 *   선적 진입 기준 미달(입금 우회 진행)은 [N%미만·사유] 로 강조 — 진입 우회(clearance/shipping) 존재 차량만.
 *   🔀 2026-10-19 (jin) — 구: 50% 고정. 지금은 운영 설정 + 바이어별 재정의(LockThresholdResolver)를 그대로 읽는다.
 *   ⚠️ 본문 끝 안내문의 「[50%미만]」은 BizM 승인 고정문구라 다음 재등록 때 변수로 바꾼다(jin «설정 문장은 변수로»).
 */
class AlimtalkShippingDue extends Command
{
    protected $signature = 'alimtalk:shipping-due';

    protected $description = '선적일 5일 전 미완납 차량 목록 알림톡 — 관리.';

    /** BizM 알림톡 본문 상한(글자). 넘으면 E112:OverLimitMessageLength 로 통째 반려된다. */
    public const MAX_MESSAGE_CHARS = 1000;

    /** 길면 나눠 보내는 최대 통수 (jin 2026-11-16 «2건으로 나눠서»). 그래도 남으면 「외 N건」. */
    public const MAX_PARTS = 2;

    /** 우회 사유는 이 글자까지만 싣고 「…」 — 「입금예정임ㄱㄱㄱ…」 같은 긴 사유가 한 통을 다 먹었다. */
    public const REASON_CHARS = 30;

    /** 「(1/2)\n」 머리 + 「\n\n외 NNN건 → 채권관리」 꼬리 자리. 나눌지는 다 채워 봐야 알므로 늘 비워 둔다. */
    private const RESERVE_CHARS = 30;

    /**
     * 블록을 글자 예산 안에서 최대 MAX_PARTS 통으로 담는다.
     *
     * @param  list<array{text: string, vehicle: bool, context: ?string}>  $blocks
     * @return list<string> 통별 「선적미수목록」 변수 값
     */
    public static function pack(array $blocks, int $budget): array
    {
        $cap = max(100, $budget - self::RESERVE_CHARS);
        $total = count(array_filter($blocks, fn ($b) => $b['vehicle']));
        $parts = [[]];
        $len = 0;
        $placed = 0;

        foreach ($blocks as $b) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $idx = count($parts) - 1;
                $isFresh = $parts[$idx] === [];
                $text = $isFresh ? ltrim($b['text'], "\n") : $b['text'];
                // 2통째가 차량·바이어 줄로 시작하면 무슨 묶음인지 머리를 다시 붙인다.
                if ($isFresh && $idx > 0 && $b['context'] !== null) {
                    $text = $b['context']."\n".$text;
                }
                $add = ($isFresh ? 0 : 1) + mb_strlen($text);
                if ($len + $add <= $cap) {
                    $parts[$idx][] = ['t' => $text, 'v' => $b['vehicle']];
                    $len += $add;
                    $placed += $b['vehicle'] ? 1 : 0;

                    continue 2;
                }
                if (count($parts) >= self::MAX_PARTS || $isFresh) {
                    break 2;   // 더 나눌 수 없다 → 남은 것은 「외 N건」
                }
                // 머리줄(선박·바이어·우회 제목)만 덩그러니 남기지 않는다 — 다음 통이 context 로 다시 붙인다.
                while ($parts[$idx] !== [] && ! end($parts[$idx])['v']) {
                    array_pop($parts[$idx]);
                }
                $parts[] = [];
                $len = 0;
            }
        }

        $parts = array_values(array_filter($parts, fn ($p) => $p !== []));
        if ($parts === []) {
            $parts = [[]];
        }
        $out = array_map(fn ($p) => implode("\n", array_column($p, 't')), $parts);
        if ($placed < $total) {
            $out[count($out) - 1] .= "\n\n외 ".($total - $placed).'건 → 채권관리';
        }
        if (count($out) > 1) {
            foreach ($out as $i => $s) {
                $out[$i] = '('.($i + 1).'/'.count($out).")\n".$s;
            }
        }

        return $out;
    }

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

            // 🔀 2026-11-16 (jin) — 자르는 기준을 「차 20대」에서 **글자 수**로. 우회 차가 33대인 날 20대 × 3줄(+긴 사유)이
            //   1,688자가 되어 BizM E112(OverLimitMessageLength)로 수신자 전원 실패했다(ssancarerp 11-16 09:00·09:10).
            //   길면 **최대 2통**으로 나눠 보내고, 그래도 남으면 2통째 끝에 「외 N건 → 채권관리」.
            $budget = self::MAX_MESSAGE_CHARS - mb_strlen(AlimtalkTemplates::render('erp_shipping_due', ['선적미수목록' => '']));
            // 🔀 2026-10-27 (jin, 폰 가독성) — ① ⚠ 우회 진행 차를 먼저 한 블록(바이어·차량 / D-n·미수·기준 / 사유 줄),
            //   ② 나머지는 🚢 선박+선적일 › ★ 바이어 › 차량. 선적일은 선적사 RORO 리스트를 「선적일·ETA·VSL 일괄」로 넣을 때
            //   들어오므로 선적일이 있는 차는 대개 선박명도 같이 있다(같은 입력). 비면 「선박 미입력」 묶음.
            //   현행엔 바이어명이 없어 누구 차인지 안 보였다 — 넣는다. 변수 안쪽만 바뀐다(승인 본문 무변경, §8 #40).
            //   미리보기 = 2026-10-27 아티팩트 「알림톡 모바일 미리보기」.
            //   블록 = 같이 붙어 있어야 하는 줄 묶음. 나눠 보낼 때 2통째가 차량 줄로 시작하면 선박·바이어 머리를 다시 붙인다.
            $blocksOf = function ($rows) use ($today): array {
                $rows = collect($rows);
                $dday = fn (Vehicle $v): int => max(0, (int) $today->diffInDays($v->shipping_date, false));
                $pctOf = fn (Vehicle $v): ?int => $v->unpaid_ratio !== null ? (int) round($v->unpaid_ratio * 100) : null;

                // ① 우회 진행 = 선적 진입 기준 미달인데 진입 우회(clearance/shipping) 승인이 있는 차. 기준 = 그 바이어의 필요 입금률.
                $bypass = [];
                $normal = [];
                foreach ($rows as $v) {
                    $override = null;
                    if ($v->unpaid_ratio !== null && $v->unpaid_ratio > LockThresholdResolver::threshold($v->buyer, 'shipping_entry')) {
                        $override = UnpaidExportOverride::query()
                            ->where('vehicle_id', $v->id)
                            ->whereIn('stage', ['clearance', 'shipping'])
                            ->latest('id')->first();
                    }
                    $override ? $bypass[] = [$v, $override] : $normal[] = $v;
                }

                // 블록: ['text' => 줄들, 'vehicle' => 차 1대면 true, 'context' => 2통째가 이 블록으로 시작할 때 앞에 다시 붙일 머리]
                $blocks = [];
                if ($bypass !== []) {
                    $head = '⚠ 우회 진행 '.count($bypass).'대';
                    $blocks[] = ['text' => $head, 'vehicle' => false, 'context' => null];
                    foreach ($bypass as [$v, $o]) {
                        $lines = [
                            sprintf('★ %s %s', AlimtalkText::buyerShort($v->buyer?->name), $v->vehicle_number),
                            sprintf('  D-%d · 미수%s · %d%%미만', $dday($v), $pctOf($v) !== null ? $pctOf($v).'%' : '-',
                                LockThresholdResolver::requiredPaidPct($v->buyer, 'shipping_entry')),
                        ];
                        if (($reason = trim(preg_replace('/\s+/u', ' ', (string) $o->reason))) !== '') {
                            $lines[] = '  사유: '.(mb_strlen($reason) > self::REASON_CHARS ? mb_substr($reason, 0, self::REASON_CHARS).'…' : $reason);
                        }
                        $blocks[] = ['text' => implode("\n", $lines), 'vehicle' => true, 'context' => $head.' (이어서)'];
                    }
                }

                // ② 선박+선적일 묶음 — 선적일 빠른 순, 같은 날이면 선박명 순
                $groups = collect($normal)
                    ->groupBy(fn (Vehicle $v) => (trim((string) $v->vessel_name) !== '' ? trim((string) $v->vessel_name) : '선박 미입력').'|'.$v->shipping_date->toDateString())
                    ->sortBy(fn ($g, string $key) => [$g->first()->shipping_date->toDateString(), $key]);
                foreach ($groups as $g) {
                    $v0 = $g->first();
                    $ship = sprintf('🚢 %s · %s (D-%d)', trim((string) $v0->vessel_name) !== '' ? trim((string) $v0->vessel_name) : '선박 미입력',
                        $v0->shipping_date->format('m/d'), $dday($v0));
                    $blocks[] = ['text' => ($blocks !== [] ? "\n" : '').$ship, 'vehicle' => false, 'context' => null];
                    $byBuyer = $g->groupBy(fn (Vehicle $v) => (int) ($v->buyer_id ?? 0))
                        ->sortByDesc(fn ($bg) => (int) $bg->sum('sale_unpaid_amount_krw_cache'));
                    foreach ($byBuyer as $bg) {
                        $buyerLine = '★ '.AlimtalkText::buyerShort($bg->first()->buyer?->name);
                        $blocks[] = ['text' => $buyerLine, 'vehicle' => false, 'context' => $ship];
                        foreach ($bg->sortByDesc('sale_unpaid_amount_krw_cache') as $v) {
                            $blocks[] = ['text' => sprintf('  %s · 미수%s', $v->vehicle_number, $pctOf($v) !== null ? $pctOf($v).'%' : '-'),
                                'vehicle' => true, 'context' => $ship."\n".$buyerLine];
                        }
                    }
                }

                return $blocks;
            };
            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_shipping_due', $rows);
            if (empty($targets)) {
                $this->info('shipping-due: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $parts = self::pack($blocksOf($mine), $budget);
                // 🔁 하루 한 번은 1통째로 판정한다 — 2통째는 1통째가 나갔을 때만 보낸다(09:10 따라잡기가 1통째를 건너뛰면 2통째도 건너뛴다).
                $first = $svc->send('erp_shipping_due', $phone, ['선적미수목록' => $parts[0]], ['once_per_day' => true]);
                if (isset($parts[1]) && $first->status === 'sent') {
                    $svc->send('erp_shipping_due', $phone, ['선적미수목록' => $parts[1]], ['after_first_part_sent' => true]);
                }
            }
            $this->info("shipping-due: {$rows->count()}대, ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:shipping-due 실패', ['error' => $e->getMessage()]);
            $this->error('shipping-due 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
