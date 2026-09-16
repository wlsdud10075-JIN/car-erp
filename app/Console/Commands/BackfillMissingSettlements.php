<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 완납/거래완료됐으나 정산이 아예 없는 차량의 누락 정산 백필 (A-3 방식, 2026-07-08 개편).
 *
 *   php artisan settlements:backfill-missing                        # 전체 완납월 분포 dry-run
 *   php artisan settlements:backfill-missing --month=2026-07        # 해당 완납월 대상 dry-run
 *   php artisan settlements:backfill-missing --month=2026-07 --force # 실제 생성
 *
 * 갭 원인: A-3(2026-07-08) 정산 트리거는 FinalPayment::saved(완납 감지) 훅 — 배포 후 발생한
 *   완납만 잡는다. 배포 전에 이미 완납/거래완료된 차량은 정산이 안 만들어져 배치에서 영구 누락.
 *   (settlements:backfill-attributed-month 는 기존 정산의 귀속월만 채움 — 없는 정산은 못 만듦.)
 *   과거 import/CLI 무인증 유입 갭(구 버전 목적)도 동일하게 흡수.
 *
 * 대상 = sale_price>0 && 완납(미입금≤0) && 담당자 있음 && 정산 없음
 *        (진행상태는 보지 않는다 — 2026-09-16. 완납된 차는 선적·통관 단계에 있는 게 정상이다.)
 *        && 완납월(fullPaymentMonth) == --month.
 * 생성 = Vehicle::createSettlementIfComplete (A-3 로직 재사용 — attributed_month=완납월 고정, 멱등).
 * ⚠️ --month 필수(--force 시) — 과거 이미 지급된 배치로의 귀속 오염 방지. 완납월별 명시 실행.
 */
class BackfillMissingSettlements extends Command
{
    protected $signature = 'settlements:backfill-missing
                            {--month= : 대상 완납월 YYYY-MM (--force 시 필수)}
                            {--force : 실제 생성 (없으면 드라이런)}';

    protected $description = '완납/거래완료됐으나 정산 누락된 차량에 pending 정산 백필 (A-3, 완납월 지정·멱등).';

    public function handle(): int
    {
        $month = (string) $this->option('month');
        $force = (bool) $this->option('force');

        if ($month !== '' && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('--month 는 YYYY-MM 형식이어야 합니다. (예: --month=2026-07)');

            return self::INVALID;
        }
        if ($force && $month === '') {
            $this->error('실제 생성(--force)에는 완납월(--month=YYYY-MM) 지정이 필수입니다 — 과거 배치 귀속 오염 방지.');

            return self::INVALID;
        }

        /*
         * 완납 + 담당자 + 정산 없음 후보 (완납 여부는 아래 filter 와 createSettlementIfComplete 가 재확인).
         *
         * 🔀 **2026-09-16 — 진행상태 제한(`판매완료`·`거래완료`)을 걷어냈다** (jin).
         *    정산 자동생성 자체는 진행상태를 보지 않는다(완납이면 만든다). 그런데 이 도구만
         *    두 상태로 좁혀 놔서, **완납인데 이미 배를 탄 차가 후보에서 통째로 빠졌다.**
         *    v4 cascade 는 선적·통관을 판매완료보다 **먼저** 평가하므로(CLAUDE.md 진행상태 10단계),
         *    완납된 차의 진행상태는 대부분 `선적중`·`선적완료`·`통관중` 이다 — 그게 정상이다.
         *    실사고 = ssancarerp `263버8577`(완납·`선적완료`) — 정산도 없고 이 도구로도 안 잡혔다.
         * ⚠️ **순수 확대다** — 구 조건이 뽑던 차는 한 대도 안 빠진다(가드가 그걸 단언한다, §8 #55).
         *    `sale_price > 0` + 완납이면 진행상태는 그 다섯 중 하나뿐이라 엉뚱한 차가 들어오지 않는다.
         */
        $candidates = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereNotNull('salesman_id')
            ->whereDoesntHave('settlements')
            ->with('salesman')
            ->get()
            ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount <= 0);   // 완납만

        // 담당자 없는 누락분도 같은 범위로 센다 — 한쪽만 넓히면 «후보는 늘었는데 경고는 그대로» 가 된다.
        $noSalesman = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereNull('salesman_id')
            ->whereDoesntHave('settlements')
            ->count();

        // 완납월 분포 (항상 표시 — 운영자 가시성).
        $byMonth = [];
        foreach ($candidates as $v) {
            $ym = substr($v->fullPaymentMonth(), 0, 7);
            $byMonth[$ym] = ($byMonth[$ym] ?? 0) + 1;
        }
        ksort($byMonth);
        $this->info(sprintf('완납+정산없음 후보 %d대 · 완납월 분포:', $candidates->count()));
        foreach ($byMonth as $ym => $cnt) {
            $this->line(sprintf('  %s : %d대', $ym, $cnt));
        }
        if ($noSalesman > 0) {
            $this->warn("완납 + 정산없음 + 담당자 없음 : {$noSalesman}대 (담당자 지정 필요 — 백필 불가)");
        }

        if ($month === '') {
            $this->warn('완납월(--month=YYYY-MM)을 지정해 대상을 좁히세요. --force 로 실제 생성.');

            return self::SUCCESS;
        }

        $targets = $candidates->filter(fn (Vehicle $v) => substr($v->fullPaymentMonth(), 0, 7) === $month);
        if ($targets->isEmpty()) {
            $this->info("완납월 {$month} 대상 없음.");

            return self::SUCCESS;
        }

        $this->info(sprintf('완납월 %s 대상 %d대:', $month, $targets->count()));
        foreach ($targets as $v) {
            $this->line(sprintf(
                '  #%d %s | %s | %s | 판매가=%s',
                $v->id, $v->vehicle_number, $v->progress_status_cache,
                $v->salesman->name.'('.$v->salesman->defaultSettlementType().')',
                number_format((float) $v->sale_price)
            ));
        }

        if (! $force) {
            $this->warn('⚠️  --force 없이 실행됨 → 드라이런(미생성). --force 로 실제 생성.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($targets as $v) {
            $v->createSettlementIfComplete("A-3 백필(배포 전 완납 누락, 완납월 {$month})");
            if ($v->settlements()->exists()) {
                $created++;
            }
        }
        $this->info("✓ {$created}건 pending 정산 백필 완료 (완납월 {$month}, attributed_month 고정). 재무가 확정하면 됨.");

        return self::SUCCESS;
    }
}
