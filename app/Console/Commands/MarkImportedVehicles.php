<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 이미 들어온 엑셀 적재 차에 imported_at 표식 찍기 (jin 2026-11-12, 1회성 소급).
 *
 * 적재(vehicles:import)는 2026-11-12 전엔 표식을 안 남겼다. 사람 손으로는 1분에 수십 대를 만들 수 없으므로
 * 「같은 분(created_at 분 단위)에 --min 대 이상 생성된 묶음」을 적재로 본다 — ssancarerp 실측 08-28 13:48~13:49 에 3,839대,
 * 08-29 17:36 에 852대. 기본은 dry-run(묶음과 대수만), --apply 로 imported_at = created_at.
 *
 *   php artisan vehicles:mark-imported            # 묶음 보기
 *   php artisan vehicles:mark-imported --apply    # 표식
 */
class MarkImportedVehicles extends Command
{
    protected $signature = 'vehicles:mark-imported
        {--min=20 : 같은 분에 이 대수 이상이면 적재 묶음으로 본다}
        {--apply : 실제로 imported_at 을 찍는다 (기본 dry-run)}';

    protected $description = '같은 분에 N대 이상 생성된 차량 묶음을 엑셀 적재로 보고 imported_at 을 찍는다 (기본 dry-run)';

    public function handle(): int
    {
        $min = max(2, (int) $this->option('min'));
        $minute = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:%M', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')";

        $groups = DB::table('vehicles')->whereNull('imported_at')
            ->selectRaw("{$minute} as m, COUNT(*) as n")->groupBy('m')->havingRaw("COUNT(*) >= {$min}")->orderBy('m')->get();

        if ($groups->isEmpty()) {
            $this->info("같은 분에 {$min}대 이상 생성된 묶음이 없다 — 표식할 적재 차 없음");

            return self::SUCCESS;
        }
        $this->table(['분(created_at)', '대수'], $groups->map(fn ($g) => [$g->m, $g->n])->all());
        $total = (int) $groups->sum('n');

        if (! $this->option('apply')) {
            $this->info("dry-run — {$total}대 (--apply 로 imported_at = created_at)");

            return self::SUCCESS;
        }
        $done = 0;
        foreach ($groups as $g) {
            $done += DB::table('vehicles')->whereNull('imported_at')->whereRaw("{$minute} = ?", [$g->m])
                ->update(['imported_at' => DB::raw('created_at')]);
        }
        $this->info("✅ {$done}대 imported_at 표식");

        return self::SUCCESS;
    }
}
