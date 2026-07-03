<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 면장금액(export_declaration_amount)을 총판매가(sale_total_amount)로 보정.
 *   대상: 판매차량(sale_price>0) 중 면장 ≠ 총판매가.
 *   - 미잠금 차량 → 보정.
 *   - 완료(재무확정 잠금) 차량 → 면장이 0 또는 sale_price(구 자동복사·미입력, 수동 아님)면 보정,
 *     그 외 제3의 값(수동수정/실제 신고가 의심)이면 스킵+리포트. (jin 2026-07-03)
 *   artisan 은 auth 없어 Ledger 가드를 우회하므로, 완료차량 보호는 이 커맨드의 명시 분기가 책임진다.
 *   dry-run 기본, --apply 로 실제 보정.
 */
class SyncDeclarationAmount extends Command
{
    protected $signature = 'vehicles:sync-declaration-amount {--apply : 실제 보정 (미지정=dry-run)}';

    protected $description = '면장금액을 총판매가로 보정 (미잠금 + 완료-safe, 수동수정 의심은 스킵)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sold = Vehicle::where('sale_price', '>', 0)->get();

        $fix = [];       // [v, current, target, kind]  kind = unlocked | locked-safe
        $suspect = [];   // [number, current, target]   완료-제3값 → 스킵
        foreach ($sold as $v) {
            $target = (int) round((float) $v->sale_total_amount);
            $current = (int) round((float) $v->export_declaration_amount);
            if ($target === $current) {
                continue;   // 이미 일치
            }

            if ($v->hasConfirmedPaymentLock()) {
                $salePrice = (int) round((float) $v->sale_price);
                if ($current === 0 || $current === $salePrice) {
                    $fix[] = [$v, $current, $target, 'locked-safe'];   // 완료지만 수동 아님 → 보정
                } else {
                    $suspect[] = [$v->vehicle_number, $current, $target];   // 제3값 → 수동 의심, 스킵
                }

                continue;
            }
            $fix[] = [$v, $current, $target, 'unlocked'];
        }

        $unlockedCnt = count(array_filter($fix, fn ($r) => $r[3] === 'unlocked'));
        $safeCnt = count(array_filter($fix, fn ($r) => $r[3] === 'locked-safe'));

        $this->info(sprintf(
            '판매차량 %d대 · 보정대상 %d대 (미잠금 %d + 완료-safe %d) · 스킵(완료-수동의심) %d대',
            $sold->count(), count($fix), $unlockedCnt, $safeCnt, count($suspect)
        ));

        if (! empty($suspect)) {
            $this->warn('── 스킵: 완료 차량 중 면장이 제3의 값(수동수정/실제 신고가 의심) — 안 건드림 ──');
            foreach ($suspect as [$num, $cur, $tar]) {
                $this->line(sprintf('  %s  면장 %s (총판매가 %s)', $num, number_format($cur), number_format($tar)));
            }
        }

        if (empty($fix)) {
            $this->info('보정할 차량 없음.');

            return self::SUCCESS;
        }

        $this->line('── 보정 대상 ──');
        foreach (array_slice($fix, 0, 30) as [$v, $cur, $tar, $kind]) {
            $tag = $kind === 'locked-safe' ? ' [완료-safe]' : '';
            $this->line(sprintf('  %s  면장 %s → %s%s', $v->vehicle_number, number_format($cur), number_format($tar), $tag));
        }
        if (count($fix) > 30) {
            $this->line(sprintf('  … 외 %d대', count($fix) - 30));
        }

        if (! $apply) {
            $this->warn('[dry-run] 실제 보정하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($fix as [$v, $cur, $tar, $kind]) {
            $v->export_declaration_amount = $tar;
            $v->save();
            $n++;
        }
        $this->info("✅ {$n}대 면장금액 보정 완료.");

        return self::SUCCESS;
    }
}
