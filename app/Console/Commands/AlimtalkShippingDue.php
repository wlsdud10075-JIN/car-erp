<?php

namespace App\Console\Commands;

use App\Models\UnpaidExportOverride;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Services\LockThresholdResolver;
use App\Support\AlimtalkRecipients;
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

            $cap = 20;   // 알림톡 본문 1000자 제한 — 목록 상한(차량 줄). 초과분은 "외 N건", 상세는 채권관리.
            // 🔀 2026-10-27 (jin, 폰 가독성) — ① ⚠ 우회 진행 차를 먼저 한 블록(바이어·차량 / D-n·미수·기준 / 사유 줄),
            //   ② 나머지는 🚢 선박+선적일 › ★ 바이어 › 차량. 선적일은 선적사 RORO 리스트를 「선적일·ETA·VSL 일괄」로 넣을 때
            //   들어오므로 선적일이 있는 차는 대개 선박명도 같이 있다(같은 입력). 비면 「선박 미입력」 묶음.
            //   현행엔 바이어명이 없어 누구 차인지 안 보였다 — 넣는다. 변수 안쪽만 바뀐다(승인 본문 무변경, §8 #40).
            //   미리보기 = 2026-10-27 아티팩트 「알림톡 모바일 미리보기」.
            $listOf = function ($rows) use ($today, $cap): string {
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

                $lines = [];
                $shown = 0;
                if ($bypass !== []) {
                    $lines[] = '⚠ 우회 진행 '.count($bypass).'대';
                    foreach ($bypass as [$v, $o]) {
                        if ($shown >= $cap) {
                            break;
                        }
                        $lines[] = sprintf('★ %s %s', AlimtalkText::buyerShort($v->buyer?->name), $v->vehicle_number);
                        $lines[] = sprintf('  D-%d · 미수%s · %d%%미만', $dday($v), $pctOf($v) !== null ? $pctOf($v).'%' : '-',
                            LockThresholdResolver::requiredPaidPct($v->buyer, 'shipping_entry'));
                        if (($reason = trim((string) $o->reason)) !== '') {
                            $lines[] = '  사유: '.$reason;
                        }
                        $shown++;
                    }
                }

                // ② 선박+선적일 묶음 — 선적일 빠른 순, 같은 날이면 선박명 순
                $groups = collect($normal)
                    ->groupBy(fn (Vehicle $v) => (trim((string) $v->vessel_name) !== '' ? trim((string) $v->vessel_name) : '선박 미입력').'|'.$v->shipping_date->toDateString())
                    ->sortBy(fn ($g, string $key) => [$g->first()->shipping_date->toDateString(), $key]);
                foreach ($groups as $g) {
                    if ($shown >= $cap) {
                        break;
                    }
                    $v0 = $g->first();
                    if ($lines !== []) {
                        $lines[] = '';
                    }
                    $lines[] = sprintf('🚢 %s · %s (D-%d)', trim((string) $v0->vessel_name) !== '' ? trim((string) $v0->vessel_name) : '선박 미입력',
                        $v0->shipping_date->format('m/d'), $dday($v0));
                    $byBuyer = $g->groupBy(fn (Vehicle $v) => (int) ($v->buyer_id ?? 0))
                        ->sortByDesc(fn ($bg) => (int) $bg->sum('sale_unpaid_amount_krw_cache'));
                    foreach ($byBuyer as $bg) {
                        if ($shown >= $cap) {
                            break;
                        }
                        $lines[] = '★ '.AlimtalkText::buyerShort($bg->first()->buyer?->name);
                        foreach ($bg->sortByDesc('sale_unpaid_amount_krw_cache') as $v) {
                            if ($shown >= $cap) {
                                break;
                            }
                            $lines[] = sprintf('  %s · 미수%s', $v->vehicle_number, $pctOf($v) !== null ? $pctOf($v).'%' : '-');
                            $shown++;
                        }
                    }
                }
                if ($shown < $rows->count()) {
                    $lines[] = '';
                    $lines[] = '외 '.($rows->count() - $shown).'건 → 채권관리';
                }

                return implode("\n", $lines);
            };
            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (jin 2026-08-24).
            $targets = AlimtalkRecipients::scopedFor('erp_shipping_due', $rows);
            if (empty($targets)) {
                $this->info('shipping-due: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_shipping_due', $phone, ['선적미수목록' => $listOf($mine)], ['once_per_day' => true]);
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
