<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 면장금액(export_declaration_amount)을 총판매가(sale_total_amount)로 보정.
 *   - 대상: 판매차량(sale_price>0) 중 면장 ≠ 총판매가.
 *   - 미잠금 차량만 자동 보정. 재무확정(완료·잠금, hasConfirmedPaymentLock) 차량은 스킵+리포트.
 *     (완료 차량은 수동 수정했을 수 있어 안 건드림 — jin 2026-07-03. 필요 시 개별 🔓 잠금해제로.)
 *   - dry-run 기본, --apply 로 실제 보정.
 */
class SyncDeclarationAmount extends Command
{
    protected $signature = 'vehicles:sync-declaration-amount {--apply : 실제 보정 (미지정=dry-run)}';

    protected $description = '면장금액을 총판매가로 보정 (미잠금 판매차량만, 완료 차량은 스킵+리포트)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sold = Vehicle::where('sale_price', '>', 0)->get();

        $fix = [];
        $locked = [];
        foreach ($sold as $v) {
            $target = (int) round((float) $v->sale_total_amount);
            $current = (int) round((float) $v->export_declaration_amount);
            if ($target === $current) {
                continue;   // 이미 일치
            }
            if ($v->hasConfirmedPaymentLock()) {
                $locked[] = [$v->vehicle_number, $current, $target];   // 완료(잠금) — 안 건드림

                continue;
            }
            $fix[] = [$v, $current, $target];
        }

        $this->info(sprintf(
            '판매차량 %d대 · 보정대상(미잠금) %d대 · 스킵(재무확정 잠금) %d대',
            $sold->count(), count($fix), count($locked)
        ));

        if (! empty($locked)) {
            $this->warn('── 스킵: 재무확정(완료) 차량 — 수동 수정 가능성이 있어 안 건드림. 개별 확인 후 필요 시 🔓 잠금해제로 ──');
            foreach ($locked as [$num, $cur, $tar]) {
                $this->line(sprintf('  %s  면장 %s → 총판매가 %s', $num, number_format($cur), number_format($tar)));
            }
        }

        if (empty($fix)) {
            $this->info('보정할 미잠금 차량 없음.');

            return self::SUCCESS;
        }

        $this->line('── 보정 대상(미잠금) ──');
        foreach (array_slice($fix, 0, 30) as [$v, $cur, $tar]) {
            $this->line(sprintf('  %s  면장 %s → %s', $v->vehicle_number, number_format($cur), number_format($tar)));
        }
        if (count($fix) > 30) {
            $this->line(sprintf('  … 외 %d대', count($fix) - 30));
        }

        if (! $apply) {
            $this->warn('[dry-run] 실제 보정하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($fix as [$v, $cur, $tar]) {
            $v->export_declaration_amount = $tar;
            $v->save();
            $n++;
        }
        $this->info("✅ {$n}대 면장금액 보정 완료.");

        return self::SUCCESS;
    }
}
