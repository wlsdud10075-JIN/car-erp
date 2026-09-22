<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 지급 완료 정산의 `confirmed_snapshot` 소급 백필 (2026-08-28).
 *
 * ## 왜 필요한가
 *
 * `paid` 전환 훅이 「지급 시점 회계 상태」를 스냅샷으로 박제한다. 그런데 **엑셀 소급 적재**는
 * `Model::withoutEvents` 안에서 행을 만들기 때문에 그 훅이 돌지 않는다
 * (실측 ssancarerp: `paid` 3,815건 **전부** 스냅샷 없음).
 *
 * 비어 있으면 두 가지가 무너진다:
 *   1. **감사추적** — 「지급할 때 얼마였나」가 남지 않는다. 회계 잠금의 근거가 그 값이다.
 *   2. **성능** — 관리자 대시보드의 정산 집계는 스냅샷이 있으면 **읽기만** 하고 없으면
 *      행마다 마진 사슬을 다시 계산한다(차량의 잔금·회수이력까지 읽는다).
 *
 * ## 지금 값으로 박제해도 되는 이유
 *
 * 대상은 **2차 정산까지 마감(`secondary_status='closed'`)된 행**뿐이다. 마감은 회계 잠금의
 * 단일 트리거라 차량의 회계 컬럼이 이미 잠겨 있다 ⇒ **지금 값 = 지급 시점 값**이다.
 * 🚫 마감 안 된 행은 손대지 않는다 — 아직 움직이는 값이라 박제하면 그 시점을 거짓으로 못박는다.
 *
 * ## 안전
 *
 * 🚫 **이미 스냅샷이 있는 행은 절대 덮지 않는다.** 그건 진짜 지급 시점 기록이다.
 * 🔒 스냅샷 컬럼 하나만 쓴다 — 금액·상태는 건드리지 않는다.
 * ⚙️ `withoutEvents` 로 쓴다. 훅을 태우면 `saving` 가드가 마감 행의 수정을 막는다.
 *
 *   php artisan settlements:backfill-snapshot            # dry-run (기본)
 *   php artisan settlements:backfill-snapshot --apply
 */
class BackfillSettlementSnapshot extends Command
{
    protected $signature = 'settlements:backfill-snapshot
        {--apply : 실제 기록 (미지정 시 dry-run)}
        {--include-pending : 2차 미마감(paid+pending) 행도 「오늘 값」으로 채운다 — 스냅샷에 backfilled_at 표식}
        {--chunk=200 : 한 번에 처리할 행 수}';

    protected $description = '지급 완료·2차 마감 정산의 confirmed_snapshot 소급 백필 (기본 dry-run)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(20, (int) $this->option('chunk'));

        // --include-pending (2026-09-22, jin) — 2차 미마감 행은 값이 아직 움직이지만, 스냅샷이 **없으면**
        //   ① 관리자 대시보드가 매 렌더마다 마진 사슬을 다시 계산하고(운영 10초의 절반)
        //   ② 2차 마감이 `?? 0` 기준으로 실지급액 전액을 이월로 만들 뻔했다(마감 코드에 가드 추가).
        //   그래서 「오늘 값」을 박되 `backfilled_at` 을 남겨 지급 시점 기록이 아님을 밝힌다.
        //   대시보드가 보여주는 값과 같은 값이므로 화면 숫자는 움직이지 않는다.
        $includePending = (bool) $this->option('include-pending');

        $query = Settlement::query()
            ->where('settlement_status', 'paid')
            // 🔒 기본은 마감된 것만 — 마감이 회계 잠금이라 지금 값이 곧 지급 시점 값이다.
            ->when(! $includePending, fn ($q) => $q->where('secondary_status', 'closed'))
            ->whereNull('confirmed_snapshot')
            // 마진 사슬이 차량의 잔금·회수이력을 읽는다 — 같이 싣지 않으면 행마다 쿼리가 붙는다.
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'vehicle.purchaseBalancePayments', 'salesman']);

        $total = (clone $query)->count();
        $skipped = Settlement::where('settlement_status', 'paid')
            ->where('secondary_status', '!=', 'closed')
            ->whereNull('confirmed_snapshot')->count();
        $already = Settlement::whereNotNull('confirmed_snapshot')->count();

        $this->info('── 대상 ──');
        $this->line('  백필 대상 (paid + '.($includePending ? '2차 무관' : '2차 closed').' + 스냅샷 없음) : '.$total.'건');
        $this->line('  '.($includePending ? '포함' : '건너뜀').'   (paid 인데 2차 미마감)              : '.$skipped.'건  ← 아직 값이 움직인다'.($includePending ? ' → 오늘 값 + backfilled_at 표식' : ''));
        $this->line("  이미 있음 (덮지 않는다)                       : {$already}건");

        if ($total === 0) {
            $this->newLine();
            $this->info('백필할 것이 없다.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('── 표본 3건 (실제로 어떤 값이 박히나) ──');
            foreach ((clone $query)->limit(3)->get() as $s) {
                $snap = $s->buildConfirmedSnapshot();
                $this->line(sprintf('  정산#%-6d %-12s 총마진 %14s · 실지급 %14s · 확정잔금 %d건',
                    $s->id,
                    $s->vehicle?->vehicle_number ?? '(차량없음)',
                    number_format((int) $snap['total_margin']),
                    number_format((int) $snap['actual_payout']),
                    count($snap['confirmed_final_payments'])));
            }
            $this->newLine();
            $this->warn('dry-run 이다 — 아무것도 쓰지 않았다. 실제 기록은 --apply.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $done = 0;
        $noVehicle = 0;

        // 훅을 태우지 않는다 — `saving` 가드가 마감 행의 수정을 막는다.
        Model::withoutEvents(function () use ($query, $chunk, $bar, &$done, &$noVehicle) {
            $query->chunkById($chunk, function ($rows) use ($bar, &$done, &$noVehicle) {
                foreach ($rows as $s) {
                    if (! $s->vehicle) {
                        // 차량이 지워진 고아 정산 — 스냅샷의 차량 칸이 전부 null 이 된다.
                        // 그래도 마진 값은 남으므로 기록한다(감사추적 목적).
                        $noVehicle++;
                    }
                    $snap = $s->buildConfirmedSnapshot();
                    if ($s->secondary_status !== 'closed') {
                        $snap['backfilled_at'] = now()->toIso8601String();   // 지급 시점 기록이 아니라 소급 박제다
                    }
                    DB::table('settlements')->where('id', $s->id)
                        ->update(['confirmed_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
                    $done++;
                }
                $bar->advance($rows->count());
            });
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ 백필 완료 — {$done}건");
        if ($noVehicle > 0) {
            $this->warn("  ⚠️ 차량이 없는 정산 {$noVehicle}건 — 스냅샷의 차량 칸은 null 이다(마진 값은 기록됨).");
        }

        return self::SUCCESS;
    }
}
