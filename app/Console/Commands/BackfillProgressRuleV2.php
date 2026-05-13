<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 큐 2.6 — 3-tier 이관 (Specialist 제안).
 *
 * 큐 2.6 마이그 직후 모든 row는 v1으로 grandfather됨.
 * 본 명령으로 차량을 3개 tier로 분류 + 자동 backfill 가능한 tier만 v2 전환.
 *
 * Tier 1 (grandfather): settlement.status=paid 또는 dhl_request=true → v1 고정 (변경 X)
 * Tier 2 (수동 검토):    sale_price > 0 + 미마감 → 수동 검토 큐 (재분류 위험 있음)
 * Tier 3 (자동 backfill): 매입 단계만 또는 데이터 부족 → v2 자동 전환 (안전)
 *
 * 옵션 없이 실행 시 통계만 출력(dry-run). 적용은 --apply-tier3 / --force-all.
 */
class BackfillProgressRuleV2 extends Command
{
    protected $signature = 'vehicles:backfill-progress-rule-v2
                            {--apply-tier3 : Tier 3(자동 backfill 안전군)만 v2 전환}
                            {--review : Tier 2(수동 검토 대상) row 목록 출력}
                            {--force-all : 모든 v1 row를 v2로 강제 전환 (위험)}';

    protected $description = '큐 2.6 progress_status rule v1 → v2 3-tier backfill';

    public function handle(): int
    {
        $vehicles = Vehicle::query()
            ->where('progress_status_rule_version', 1)
            ->with(['settlements', 'finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->get();

        $total = $vehicles->count();
        if ($total === 0) {
            $this->info('v1 row 없음 — backfill 대상 0건.');

            return self::SUCCESS;
        }

        [$tier1, $tier2, $tier3] = $this->classify($vehicles);

        $this->info('=== Tier 분류 (dry-run) ===');
        $this->line("Tier 1 grandfather (paid/dhl): {$tier1->count()}건 — 재계산 skip, v1 고정");
        $this->line("Tier 2 수동 검토 (미마감 판매): {$tier2->count()}건 — 분류 변경 위험");
        $this->line("Tier 3 자동 backfill (매입·기타): {$tier3->count()}건 — v2 안전 전환 가능");

        if ($this->option('review')) {
            $this->newLine();
            $this->info('=== Tier 2 수동 검토 대상 ===');
            $this->table(
                ['ID', '차량번호', '현재 캐시', 'v2 평가 분류', '변경 여부'],
                $tier2->map(function (Vehicle $v) {
                    $currentCache = $v->progress_status_cache;
                    $v->progress_status_rule_version = 2;
                    $v2Status = $v->progress_status;
                    $v->progress_status_rule_version = 1;

                    return [
                        $v->id,
                        $v->vehicle_number,
                        $currentCache ?: '-',
                        $v2Status,
                        $currentCache === $v2Status ? '동일' : '⚠️ 변경',
                    ];
                })->all()
            );
        }

        if ($this->option('apply-tier3')) {
            $this->newLine();
            $this->warn('=== Tier 3 자동 backfill 실행 ===');
            $count = 0;
            foreach ($tier3 as $v) {
                $v->progress_status_rule_version = 2;
                $v->save(); // saving 이벤트로 progress_status_cache 재계산 함께 발동
                $count++;
            }
            $this->info("Tier 3 backfill 완료: {$count}건 → v2 전환");
        }

        if ($this->option('force-all')) {
            $this->newLine();
            $this->error('=== --force-all: 모든 v1 row를 v2로 강제 전환 ===');
            if (! $this->confirm('Tier 1 grandfather까지 포함됩니다. 정말 진행할까요?', false)) {
                $this->warn('취소됨.');

                return self::SUCCESS;
            }
            $count = 0;
            foreach ($vehicles as $v) {
                $v->progress_status_rule_version = 2;
                $v->save();
                $count++;
            }
            $this->info("force-all 완료: {$count}건 → v2 전환");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    private function classify($vehicles): array
    {
        $tier1 = $vehicles->filter(fn (Vehicle $v) => $this->isTier1Grandfather($v));
        $rest = $vehicles->reject(fn (Vehicle $v) => $this->isTier1Grandfather($v));
        $tier2 = $rest->filter(fn (Vehicle $v) => $this->isTier2ReviewNeeded($v));
        $tier3 = $rest->reject(fn (Vehicle $v) => $this->isTier2ReviewNeeded($v));

        return [$tier1, $tier2, $tier3];
    }

    private function isTier1Grandfather(Vehicle $v): bool
    {
        if ($v->dhl_request) {
            return true;
        }

        return $v->settlements->contains(fn (Settlement $s) => $s->status === 'paid');
    }

    /**
     * Tier 2 — v1 → v2 전환 시 분류가 변경될 가능성이 있는 row.
     * sale_price>0 + 미마감 + 단일 트리거 누수 컬럼 중 하나라도 set된 경우.
     */
    private function isTier2ReviewNeeded(Vehicle $v): bool
    {
        if ($v->sale_price <= 0) {
            return false;
        }

        $hasLeakColumn = $v->dhl_request
            || $v->bl_document
            || $v->bl_loading_location
            || $v->export_declaration_document;

        return $hasLeakColumn;
    }
}
