<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [관리] 1인 테스트용 표본만 남기고 차량 도메인 데이터를 삭제 (2026-06-24 jin 요청).
 *
 *   php artisan vehicles:keep-sample-for-test --force
 *
 * ⚠️ 파괴적. 반드시 `php artisan db:backup` (S3 업로드 확인) 후 실행.
 *    복구 = 백업 SQL 재적재 (docs/operations/settlement-test-data-reset.md 참조).
 *
 * 남기는 것: 랜덤 거래완료 N대 + 완납 진행중 M대(정산 수동추가 테스트 대상) + 그들의 모든 종속행.
 * 지우는 것: 그 외 모든 차량 + 차량 도메인 종속행 (잔금/정산/적립/서류로그/알람/선적요청/이체/승인요청).
 * 유지하는 것: 바이어·컨사이니·영업담당자·유저·국가·포워딩사 (마스터 데이터).
 *
 * created_at 분산(--spread-months): 남은 정산의 created_at 을 최근 N개월에 흩뿌려
 *   월별 드롭다운(월급 귀속월)을 첫날부터 시연 가능하게 한다. 테스트용 데이터 가공임.
 */
class KeepSampleForTest extends Command
{
    protected $signature = 'vehicles:keep-sample-for-test
                            {--completed=10 : 남길 거래완료 차량 수 (랜덤)}
                            {--in-progress=3 : 남길 완납 진행중 차량 수 (정산 수동추가 테스트 대상)}
                            {--spread-months=5 : 남은 정산 created_at 을 최근 N개월에 분산 (0=분산 안 함)}
                            {--force : 확인 없이 즉시 실행 (운영 비대화형). 반드시 db:backup 선행!}';

    protected $description = '운영 차량 데이터를 [관리] 테스트 표본만 남기고 삭제 (⚠ db:backup 후 실행). 차량 도메인만, 마스터 유지.';

    /** vehicle_id 컬럼을 가진 종속 테이블 — 표본 외 행 삭제 대상. */
    private const VEHICLE_CHILD_TABLES = [
        'final_payments',
        'purchase_balance_payments',
        'settlements',
        'receivable_histories',
        'document_access_logs',
        'unpaid_export_overrides',
        'vehicle_photos',
        'task_alarms',
        'shipping_requests',
    ];

    public function handle(): int
    {
        $completedN = max(0, (int) $this->option('completed'));
        $inProgressN = max(0, (int) $this->option('in-progress'));
        $spread = max(0, (int) $this->option('spread-months'));

        // ── 표본 선정 ──────────────────────────────────────────────
        $completedIds = Vehicle::whereNotNull('bl_document')
            ->inRandomOrder()
            ->limit($completedN)
            ->pluck('id')
            ->all();

        // 완납 진행중 = 거래완료 아님 + 판매가 있음 + 미수 잔액 ≤ 0 (통화 무관 accessor 로 정확 판정).
        $inProgressIds = Vehicle::whereNull('bl_document')
            ->where('sale_price', '>', 0)
            ->get()
            ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount <= 0)
            ->shuffle()
            ->take($inProgressN)
            ->pluck('id')
            ->all();

        $keepIds = array_values(array_unique(array_merge($completedIds, $inProgressIds)));

        if (empty($keepIds)) {
            $this->error('표본으로 남길 차량이 없습니다 (거래완료 0대 + 완납 진행중 0대). 중단.');

            return self::FAILURE;
        }

        $totalVehicles = Vehicle::count();
        $toDelete = $totalVehicles - count($keepIds);

        $this->line('');
        $this->info('━━━ 표본 계획 ━━━');
        $this->line('  거래완료 표본 : '.count($completedIds)."대 (요청 {$completedN})");
        $this->line('  완납 진행중   : '.count($inProgressIds)."대 (요청 {$inProgressN}) — 정산 수동추가 테스트 대상");
        $this->line('  남길 차량 합계: '.count($keepIds).'대 → '.implode(', ', $keepIds));
        $this->line("  삭제될 차량   : {$toDelete}대 (전체 {$totalVehicles}대 중)");
        $this->line('  정산 created_at 분산: '.($spread > 0 ? "최근 {$spread}개월" : '안 함'));
        $this->line('');

        if (! $this->option('force')) {
            $this->warn('⚠️  --force 없이 실행됨 → 미실행(드라이런). 반드시 `php artisan db:backup` 후 --force 로 재실행.');

            return self::SUCCESS;
        }

        // ── 삭제 (FK 무시 + 하드삭제로 SoftDeletes/deleting 가드 우회) ──
        $driver = DB::connection()->getDriverName();

        DB::transaction(function () use ($keepIds, $driver, $spread) {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            }

            foreach (self::VEHICLE_CHILD_TABLES as $table) {
                DB::table($table)->whereNotIn('vehicle_id', $keepIds)->delete();
            }

            // 적립금: vehicle_id 없는 수기 조정행은 보존, 표본 외 차량 거래만 삭제.
            DB::table('savings_statuses')
                ->whereNotNull('vehicle_id')
                ->whereNotIn('vehicle_id', $keepIds)
                ->delete();

            // 차량 이체(양 차량 참조)·승인요청(폴리모픽 차량/정산 대상) — 표본 단순화 위해 전체 클리어.
            DB::table('inter_vehicle_transfers')->delete();
            DB::table('approval_requests')->delete();

            DB::table('vehicles')->whereNotIn('id', $keepIds)->delete();

            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            // 남은 정산 created_at 을 최근 N개월에 분산 (월별 드롭다운 시연용).
            if ($spread > 0) {
                $keptSettlementIds = DB::table('settlements')
                    ->whereIn('vehicle_id', $keepIds)
                    ->pluck('id')
                    ->shuffle()
                    ->values();

                foreach ($keptSettlementIds as $i => $sid) {
                    $date = now()->startOfMonth()
                        ->subMonths($i % $spread)
                        ->setTime(10, 0)
                        ->setDay(15)
                        ->format('Y-m-d H:i:s');
                    DB::table('settlements')->where('id', $sid)->update(['created_at' => $date]);
                }
            }
        });

        // 남긴 차량 진행상태 캐시 재계산 (벌크 삭제는 모델 이벤트 미발화 — SKILLS §2).
        Vehicle::whereIn('id', $keepIds)->get()->each->refreshProgressCache();

        $this->line('');
        $this->info('✓ 완료. 남은 차량 '.Vehicle::count().'대 / 정산 '.DB::table('settlements')->count().'건.');
        $this->line('  복구: 백업 SQL 재적재 (docs/operations/settlement-test-data-reset.md).');

        return self::SUCCESS;
    }
}
