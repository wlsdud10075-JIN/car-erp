<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 운임 확정 게이트 배포 후속 (jin 2026-07-09) — incoterms 미입력 차량 CFR 백필.
 *
 * 근거: 운임비(transport_fee) > 0 이면 운임이 매출에 얹혀 있다는 뜻 → 명백히 CFR(FOB 아님).
 *   운임 확정 게이트(Vehicle::isFreightConfirmedForSettlement)는 incoterms 값을 보므로,
 *   NULL 로 방치하면 완납했어도 정산이 안 뜨고 대기 큐로 쏟아진다.
 *   → transport_fee>0 + incoterms NULL 을 CFR 로 확정하면 대기 대상이
 *      "정말 애매한 것(운임0 + NULL = FOB 여부 사람 판단)"만 남는다.
 *
 * ⚠️ 반드시 settlements:reconcile-freight-gate 보다 **먼저** 실행.
 *    (그래야 이 CFR 차량들의 기존 정산이 재정렬 삭제 대상에서 빠진다.)
 *
 * dry-run 기본, --apply. 멱등(NULL 인 것만 대상). 배포 후 서버별 1회.
 */
class BackfillIncotermsCfr extends Command
{
    protected $signature = 'vehicles:backfill-incoterms-cfr {--apply : 실제 백필 (미지정=dry-run)}';

    protected $description = '운임비>0 + incoterms NULL 차량을 CFR 로 확정 (운임 게이트 배포 후속, reconcile 前 실행)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // 외화만 대상 — KRW(원화 정산)는 게이트 자동통과라 incoterms 무관.
        $q = Vehicle::query()->where('currency', '!=', 'KRW')
            ->whereNull('incoterms')->where('transport_fee', '>', 0);
        $total = (clone $q)->count();

        // 참고 지표 — 남는 애매 대상(외화 + 운임0 + incoterms NULL): 사람이 FOB/CFR 판단해야 할 것.
        $ambiguous = Vehicle::query()->where('currency', '!=', 'KRW')
            ->whereNull('incoterms')
            ->where(fn ($w) => $w->whereNull('transport_fee')->orWhere('transport_fee', '<=', 0))
            ->count();

        if ($total === 0) {
            $this->info('백필 대상 없음 (운임비>0 + incoterms NULL 차량 0건).');
            $this->line("참고: 운임0 + incoterms NULL 차량 = {$ambiguous}건 (사람이 FOB/CFR 판단 대상).");

            return self::SUCCESS;
        }

        $this->info("CFR 백필 대상 (운임비>0 + incoterms NULL): {$total}건");
        $this->line("남는 애매 대상 (운임0 + incoterms NULL, 사람 판단): {$ambiguous}건");

        if (! $apply) {
            $this->warn('[dry-run] 실제 백필하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        // 모델 이벤트/게이트 우회 — 순수 컬럼 백필 (incoterms 는 캐시·진행상태 무영향).
        $n = $q->update(['incoterms' => 'CFR']);
        $this->info("✅ {$n}건 incoterms=CFR 백필 완료. 이제 settlements:reconcile-freight-gate 실행 가능.");

        return self::SUCCESS;
    }
}
