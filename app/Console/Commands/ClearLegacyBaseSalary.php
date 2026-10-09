<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Salesman;
use Illuminate\Console\Command;

/**
 * 월정산 v3 (jin 2026-10-08 «기본급은 그냥 빈칸으로 두고 매달 새롭게») — 구 `salesmen.base_salary_krw` 를 비운다.
 *
 * 🚫 v3 배포 **뒤**에만 돌린다 — 10/10 월배치(구 코드)의 「기본급만」 줄이 이 칸을 읽는다. 배포 뒤에는 어떤 화면도 이 칸을
 *    쓰지 않는다(급여 항목 = PayrollEntry). 기본은 dry-run; `--apply` 로 실행. 지운 값은 감사로그에 남는다(되돌릴 근거).
 */
class ClearLegacyBaseSalary extends Command
{
    protected $signature = 'payroll:clear-legacy-base-salary {--apply : 실제로 비운다 (기본 = 미리보기)}';

    protected $description = '월정산 v3 — 구 기본급 칸(salesmen.base_salary_krw)을 비운다 (급여 항목으로 대체됨). 기본 dry-run';

    public function handle(): int
    {
        $rows = Salesman::query()->withTrashed()->whereNotNull('base_salary_krw')->orderBy('id')->get(['id', 'name', 'type', 'base_salary_krw']);
        $this->line('대상 '.$rows->count().'명 (base_salary_krw 가 비어 있지 않은 담당자)');
        foreach ($rows as $sm) {
            $this->line(sprintf('  #%d %s (%s) %s원', $sm->id, $sm->name, $sm->type, number_format((int) $sm->base_salary_krw)));
        }
        if (! $this->option('apply')) {
            $this->warn('dry-run — 실제로 비우려면 --apply');

            return self::SUCCESS;
        }
        foreach ($rows as $sm) {
            AuditLog::recordChange($sm, 'base_salary_krw', $sm->base_salary_krw, null);
            Salesman::withTrashed()->whereKey($sm->id)->update(['base_salary_krw' => null]);
        }
        $this->info($rows->count().'명 비움 (감사로그 base_salary_krw 기록)');

        return self::SUCCESS;
    }
}
