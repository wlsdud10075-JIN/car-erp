<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 2차 정산 마감 되돌리기 — 「1차만 끝난 상태」로 (2026-08-28, jin).
 *
 * ## 왜 필요한가
 *
 * 엑셀 소급 적재는 전 기간을 `paid + secondary_status='closed'` 로 넣는다. 그런데
 * **가장 최근 달은 2차 정산이 아직 안 끝났다** — 명세서 기입(비용 10개) 변동분이 남아 있다.
 * 그 달만 마감을 풀어 **실무자가 직접 2차를 마무리**하게 한다.
 *
 * ## 무엇이 바뀌나
 *
 * `secondary_status: closed → pending` · `secondary_closed_at → null` 뿐이다.
 * 🔒 **금액·상태는 안 건드린다** — `settlement_status='paid'`(1차 지급 완료)는 그대로다.
 *
 * ⚠️ 마감은 **회계 잠금의 단일 트리거**(`Vehicle::hasClosedSecondarySettlement()`)다.
 *    풀면 그 차량들의 매입가·판매가·환율·비용·잔금이 **다시 수정 가능**해진다 — 그게 목적이다.
 *    ⇒ 2차 대기 목록에 뜨고, 실무자가 비용을 보정한 뒤 화면에서 마감한다.
 *
 * 🚫 **마감할 때 계산된 값이 남아 있으면 지운다** — `carryover_out_krw`·`exchange_difference_krw`·
 *    `exchange_rate_at_close` 는 「마감 시점에 확정된 것」이라, 마감을 되돌리면서 남겨 두면
 *    **다시 마감할 때 이월이 이중 계상**된다. 지우고 경고를 찍는다.
 *
 * 🚫 이미 `carryover_in_krw` 로 **다른 정산이 흡수해 간** 이월이 있으면 그 행은 **건너뛴다** —
 *    끊으면 흡수한 쪽의 근거가 사라진다. 사람이 판단할 일이다.
 *
 *   php artisan settlements:reopen-secondary --paid-at=2026-08-10            # dry-run (기본)
 *   php artisan settlements:reopen-secondary --paid-at=2026-08-10 --apply
 */
class ReopenSecondarySettlement extends Command
{
    protected $signature = 'settlements:reopen-secondary
        {--paid-at= : 지급일 (YYYY-MM-DD). 그 날 지급된 정산만 대상}
        {--month= : 귀속월 (YYYY-MM). paid-at 과 함께 쓰면 둘 다 만족하는 것만}
        {--apply : 실제 적용 (미지정 시 dry-run)}';

    protected $description = '지정한 지급일의 2차 정산 마감을 풀어 「1차만 완료」 상태로 되돌린다 (기본 dry-run)';

    public function handle(): int
    {
        $paidAt = (string) $this->option('paid-at');
        $month = (string) $this->option('month');

        if ($paidAt === '' && $month === '') {
            $this->error('--paid-at 또는 --month 중 하나는 반드시 준다. 전량을 실수로 푸는 것을 막는다.');

            return self::FAILURE;
        }

        $query = Settlement::query()
            ->where('settlement_status', 'paid')
            ->where('secondary_status', 'closed')
            ->when($paidAt !== '', fn ($q) => $q->whereDate('paid_at', $paidAt))
            ->when($month !== '', fn ($q) => $q->whereDate('attributed_month', $month.'-01'))
            ->with('vehicle:id,vehicle_number');

        $total = (clone $query)->count();

        $this->info('── 대상 ──');
        $this->line('  조건: '.($paidAt !== '' ? "지급일 {$paidAt}" : '').($month !== '' ? "  귀속월 {$month}" : ''));
        $this->line("  paid + 2차 closed : {$total}건");

        if ($total === 0) {
            $this->newLine();
            $this->info('되돌릴 것이 없다.');

            return self::SUCCESS;
        }

        // 마감 시점 값이 남아 있는 행 / 이미 흡수된 이월이 있는 행을 가려낸다.
        $withCloseValues = (clone $query)->where(function ($q) {
            $q->whereNotNull('carryover_out_krw')
                ->orWhereNotNull('exchange_difference_krw')
                ->orWhereNotNull('exchange_rate_at_close');
        })->count();

        $consumedIds = [];
        (clone $query)->select('id', 'salesman_id', 'carryover_out_krw')->chunkById(500, function ($rows) use (&$consumedIds) {
            foreach ($rows as $s) {
                if ($s->carryover_out_krw === null) {
                    continue;
                }
                // 그 담당자의 다른 정산이 이월을 이미 흡수했나 — 흡수한 쪽의 근거를 끊으면 안 된다.
                $absorbed = Settlement::where('salesman_id', $s->salesman_id)
                    ->whereNotNull('carryover_in_krw')->exists();
                if ($absorbed) {
                    $consumedIds[] = $s->id;
                }
            }
        });

        $this->line('  그중 마감 시점 값이 남은 행 : '.$withCloseValues.'건'.($withCloseValues > 0 ? '  ← 지운다(다시 마감할 때 이중계상 방지)' : ''));
        $this->line('  건너뜀 (이월이 이미 흡수됨) : '.count($consumedIds).'건'.(count($consumedIds) > 0 ? '  ← 사람이 판단할 일' : ''));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('── 표본 5건 ──');
            foreach ((clone $query)->limit(5)->get() as $s) {
                $this->line(sprintf('  정산#%-6d %-12s 실지급 %14s  closed_at=%s',
                    $s->id,
                    $s->vehicle?->vehicle_number ?? '(차량없음)',
                    number_format($s->actual_payout),
                    $s->secondary_closed_at?->format('Y-m-d') ?? '-'));
            }
            $this->newLine();
            $this->warn('dry-run 이다 — 아무것도 쓰지 않았다. 실제 적용은 --apply.');
            $this->warn('⚠️ 적용하면 그 차량들의 회계 잠금이 풀린다(매입가·판매가·환율·비용·잔금 수정 가능).');

            return self::SUCCESS;
        }

        $done = 0;
        // 훅을 태우지 않는다 — `saving` 가드가 마감 행의 수정을 막는다.
        Model::withoutEvents(function () use ($query, $consumedIds, &$done) {
            (clone $query)->whereNotIn('id', $consumedIds ?: [0])
                ->select('id')->chunkById(500, function ($rows) use (&$done) {
                    $ids = $rows->pluck('id')->all();
                    $done += DB::table('settlements')->whereIn('id', $ids)->update([
                        'secondary_status' => 'pending',
                        'secondary_closed_at' => null,
                        // 마감 시점 확정값 — 되돌리면서 남기면 다시 마감할 때 이월이 이중 계상된다.
                        'carryover_out_krw' => null,
                        'exchange_difference_krw' => null,
                        'exchange_rate_at_close' => null,
                        'updated_at' => now(),
                    ]);
                });
        });

        $this->newLine();
        $this->info("✅ 되돌림 완료 — {$done}건이 「1차 완료 · 2차 대기」로 돌아갔다.");
        $this->line('   실무자가 정산 화면 2차 대기 목록에서 비용을 보정한 뒤 마감하면 된다.');
        if ($consumedIds) {
            $this->warn('   ⚠️ 건너뛴 '.count($consumedIds).'건은 이월이 이미 흡수돼 사람이 봐야 한다: '.implode(', ', array_slice($consumedIds, 0, 10)));
        }

        return self::SUCCESS;
    }
}
