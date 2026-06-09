<?php

namespace App\Console\Commands;

use App\Models\Salesman;
use App\Models\Settlement;
use Illuminate\Console\Command;

/**
 * Review2 항목 E (2026-06-09) — 영업담당자별 미흡수 이월(carryover) 리포트.
 *
 * 이월 흡수는 Settlement::creating 훅이 같은 salesman_id 의 '신규 정산' 생성 시에만 발생.
 * → 퇴사·신규 정산 없는 담당자의 carryover_out 이 영구 미흡수로 방치(stranding)될 수 있다.
 * 본 명령은 이를 가시화한다(읽기 전용). 미흡수분 처리(상계/지급/소멸)는 운영 정책 결정 사항.
 */
class CarryoverReport extends Command
{
    protected $signature = 'settlements:carryover-report {--stranded : 미흡수 잔액(≠0)만 표시}';

    protected $description = '영업담당자별 미흡수 이월(carryover) 잔액 리포트 (퇴사자 stranding 점검)';

    public function handle(): int
    {
        $ids = Settlement::query()
            ->where(fn ($q) => $q->whereNotNull('carryover_out_krw')->orWhereNotNull('carryover_in_krw'))
            ->whereNotNull('salesman_id')
            ->distinct()
            ->pluck('salesman_id');

        if ($ids->isEmpty()) {
            $this->info('이월(carryover) 활동이 있는 영업담당자가 없습니다.');

            return self::SUCCESS;
        }

        $rows = [];
        $strandedTotal = 0.0;

        foreach ($ids as $sid) {
            // Settlement::creating 흡수식과 동일 출처 — closed out 합 - 흡수된 in 합.
            $totalOut = (float) Settlement::where('salesman_id', $sid)
                ->where('secondary_status', 'closed')
                ->whereNotNull('carryover_out_krw')
                ->sum('carryover_out_krw');
            $totalIn = (float) Settlement::where('salesman_id', $sid)
                ->whereNotNull('carryover_in_krw')
                ->sum('carryover_in_krw');
            $unconsumed = $totalOut - $totalIn;

            if ($this->option('stranded') && abs($unconsumed) < 0.01) {
                continue;
            }

            $salesman = Salesman::find($sid);
            $isActive = (bool) ($salesman?->is_active);
            $isStranded = abs($unconsumed) >= 0.01 && ! $isActive;
            if ($isStranded) {
                $strandedTotal += $unconsumed;
            }

            $rows[] = [
                $salesman?->name ?? "#$sid",
                $isActive ? '활성' : '비활성',
                number_format($totalOut),
                number_format($totalIn),
                number_format($unconsumed),
                $isStranded ? '⚠ STRANDED' : '',
            ];
        }

        $this->table(
            ['영업담당자', '상태', '누적 이월(out)', '누적 흡수(in)', '미흡수 잔액', '비고'],
            $rows,
        );

        if (abs($strandedTotal) >= 0.01) {
            $this->warn(sprintf(
                '⚠ 비활성 담당자의 미흡수 이월 합계: ₩%s — 운영 정책(상계/지급/소멸) 처리 필요',
                number_format($strandedTotal),
            ));
        }

        return self::SUCCESS;
    }
}
