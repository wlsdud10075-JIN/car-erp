<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Support\SettlementCkBatch;
use Illuminate\Console\Command;

/**
 * A-3 (jin 2026-07-08) — 기존 정산의 attributed_month(귀속월) 백필.
 *   값 = 현재 그룹핑 보존: payrollMonthOf(confirmed_at ?? created_at) 의 그 달 1일.
 *   (monthRange 10일 cutoff 의 역함수 = 지금 배치가 묶는 월 그대로 → 기존 배치·그룹핑 무변.)
 *   A-3 배포 직후 1회 실행. attributed_month IS NULL 인 것만 대상(멱등).
 *   dry-run 기본, --apply.
 */
class BackfillAttributedMonth extends Command
{
    protected $signature = 'settlements:backfill-attributed-month {--apply : 실제 백필 (미지정=dry-run)}';

    protected $description = '기존 정산 attributed_month 백필 (현재 귀속월 보존, A-3 배포 후 1회)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = Settlement::whereNull('attributed_month')->get(['id', 'confirmed_at', 'created_at']);
        if ($rows->isEmpty()) {
            $this->info('백필할 정산 없음 (전부 attributed_month 있음).');

            return self::SUCCESS;
        }

        $byMonth = [];
        foreach ($rows as $s) {
            $anchor = $s->confirmed_at ?? $s->created_at;
            $ym = $anchor ? SettlementCkBatch::payrollMonthOf($anchor) : now()->format('Y-m');
            $byMonth[$ym] = ($byMonth[$ym] ?? 0) + 1;
        }
        ksort($byMonth);

        $this->info(sprintf('백필 대상 %d건 · 귀속월 분포:', $rows->count()));
        foreach ($byMonth as $ym => $cnt) {
            $this->line(sprintf('  %s : %d건', $ym, $cnt));
        }

        if (! $apply) {
            $this->warn('[dry-run] 실제 백필하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($rows as $s) {
            $anchor = $s->confirmed_at ?? $s->created_at;
            $ym = $anchor ? SettlementCkBatch::payrollMonthOf($anchor) : now()->format('Y-m');
            // 모델 이벤트/가드 우회 — 순수 컬럼 백필 (attributed_month 만).
            Settlement::where('id', $s->id)->update(['attributed_month' => $ym.'-01']);
            $n++;
        }
        $this->info("✅ {$n}건 attributed_month 백필 완료.");

        return self::SUCCESS;
    }
}
