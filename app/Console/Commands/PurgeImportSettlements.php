<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 정산 데이터 정리 (2026-06-22) — import 가 일괄 생성한 잘못된 정산 전면 제거.
 *
 * 배경: `vehicles:import --with-payments` 가 판매가>0 인 모든 차량에 CK(정산 여부) 확인 없이
 *       paid+closed 정산을 전부 ratio 50% 로 생성 → 146건 전부 오류.
 *       (진행중 차량까지 정산완료로 박혀 회계잠금 → 환율/판매가 수정 불가, 예: 96더5119)
 *
 * jin 결정(2026-06-22): 146건 전부 삭제 → Part B(사내직원 차등 tier) 구현 후 CK='정산'
 *       차량만 올바른 금액으로 재산정. 이 커맨드는 그 1단계(전면 삭제)만 담당.
 *
 * 보존: import 입금(FinalPayment note='import 입금')은 건드리지 않음 — 실제 수금/미수/진행상태
 *       근거라 유지. 정산행만 제거한다.
 *
 * 가드 우회: Settlement::deleting 가드는 auth() 없으면 통과(Settlement.php:98). artisan 컨텍스트라
 *       paid/closed 여도 삭제 가능. query-builder forceDelete 로 모델 이벤트 없이 하드삭제.
 */
class PurgeImportSettlements extends Command
{
    protected $signature = 'settlements:purge-import
        {--apply : 실제 삭제 실행 (미지정 시 dry-run — 명단만 출력, 쓰기 없음)}';

    protected $description = 'import 가 일괄 생성한 잘못된 정산(note=import — %)을 전면 삭제. 기본 dry-run.';

    public function handle(): int
    {
        $query = Settlement::withTrashed()->where('note', 'like', 'import — %');
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('삭제 대상 import 정산 없음 (note LIKE "import — %"). 이미 정리됨.');

            return self::SUCCESS;
        }

        $rows = (clone $query)->with('vehicle:id,vehicle_number,progress_status_cache,salesman_id')->get();

        // 진행상태별 분포
        $byProgress = [];
        $vehicleIds = [];
        foreach ($rows as $s) {
            $prog = $s->vehicle?->progress_status_cache ?? '(차량없음)';
            $byProgress[$prog] = ($byProgress[$prog] ?? 0) + 1;
            if ($s->vehicle_id) {
                $vehicleIds[$s->vehicle_id] = true;
            }
        }
        ksort($byProgress);

        $this->info("대상 import 정산: {$total}건 (영향 차량 ".count($vehicleIds).'대)');
        $this->newLine();
        $this->line('진행상태별 분포:');
        foreach ($byProgress as $prog => $cnt) {
            $this->line(sprintf('  %-10s %4d건', $prog, $cnt));
        }
        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn('[DRY-RUN] 쓰기 없음. 실제 삭제하려면 --apply 추가.');
            $this->line('  보존: import 입금(FinalPayment note="import 입금")은 삭제하지 않음.');

            return self::SUCCESS;
        }

        // 실제 삭제 — query-builder forceDelete (모델 이벤트/가드 우회, 하드삭제)
        $deleted = (clone $query)->forceDelete();
        $this->info("✅ 삭제 완료: {$deleted}건 forceDelete.");

        // 방어적 캐시 재계산 (정산은 차량 캐시에 영향 없지만 import 패턴 일치 + 안전망)
        $ids = array_keys($vehicleIds);
        if ($ids) {
            $this->line('캐시 재계산 중 ('.count($ids).'대)...');
            Vehicle::whereIn('id', $ids)->get()->each(fn (Vehicle $v) => $v->refreshCaches());
        }

        $remaining = Settlement::count();
        $this->info("정산 잔여: {$remaining}건.");
        $this->warn('다음 단계: Part B(사내직원 차등 tier) 구현 후 CK="정산" 차량만 올바른 금액으로 재산정.');

        return self::SUCCESS;
    }
}
