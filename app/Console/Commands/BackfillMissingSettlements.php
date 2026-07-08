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
 * 대상 = 판매완료/거래완료 && sale_price>0 && 완납(미입금≤0) && 담당자 있음 && 정산 없음
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

        // 판매완료/거래완료 + 담당자 + 정산 없음 후보 (완납 여부는 createSettlementIfComplete 가 재확인).
        $candidates = Vehicle::query()
            ->whereIn('progress_status_cache', ['판매완료', '거래완료'])
            ->where('sale_price', '>', 0)
            ->whereNotNull('salesman_id')
            ->whereDoesntHave('settlements')
            ->with('salesman')
            ->get()
            ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount <= 0);   // 완납만

        $noSalesman = Vehicle::query()
            ->whereIn('progress_status_cache', ['판매완료', '거래완료'])
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
            $this->warn("완납/거래완료 + 정산없음 + 담당자 없음 : {$noSalesman}대 (담당자 지정 필요 — 백필 불가)");
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
