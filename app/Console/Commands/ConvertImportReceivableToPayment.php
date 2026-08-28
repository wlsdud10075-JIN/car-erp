<?php

namespace App\Console\Commands;

use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 소급 적재가 만든 「미수 정리」 회수이력 → **확정 판매 잔금**으로 전환 (2026-08-28, jin 승인).
 *
 * ## 왜
 *
 * `ssancarerp:import-settled` 는 미수를 0 으로 만들 때 부족분을
 * `ReceivableHistory(method='other', note='과거데이터 임포트 …')` 로 기록했다.
 * ERP 미수 accessor 는 그 행을 차감하지만 **판매계약서의 Received 는 확정 잔금만 센다**
 * (`DocValue::confirmedReceived`, jin 2026-07-29 결정). 그래서 ERP 는 완납인데
 * **계약서만 그 금액이 미수로 남는다**(실사고 06더2956 = 382 EUR).
 *
 * ## 계산상 완전 중립이다
 *
 *   미수      회수이력 −382  →  확정 잔금 +382        ⇒ 합계 불변
 *   실입금KRW  회수이력은 판매환율로 평가된다(`Vehicle::sale_received_krw_accumulated`).
 *             그래서 잔금에도 **판매환율을 그대로** 넣으면 KRW 도 같다.
 *   ⇒ 정산환율·총마진·정산액·이월 전부 불변. 바뀌는 것은 **계약서 Received 한 칸**뿐이다.
 *
 * 그 중립성을 **믿지 않고 행마다 실측**한다 — 어긋나면 그 차량만 롤백하고 보고한다.
 *
 * ## 안전
 *
 * 🔒 **note 표식이 있는 `other` 행만** 건드린다. 사람이 채권관리에서 기록한 회수이력은 대상 밖이다.
 * 🔒 `final_payment_id` 가 이미 있는 행은 건너뛴다(미러가 있으면 이중 계상이 된다).
 * ⚙️ 2차 마감(`closed`) 차량이 대상의 다수다(실측 317대 중 228대). `FinalPayment::creating` 의
 *    마감 가드는 **`auth()->check()` 일 때만** 도므로 artisan 에서는 걸리지 않는다 —
 *    우회 플래그를 쓰지 않는다. 잠금을 뚫는 게 아니라 애초에 시스템 경로다.
 * 🧭 멱등 — 전환된 행은 사라지므로 다시 돌려도 대상이 0 이다.
 *
 * ⚠️ **반대 방향(과입금)은 이 명령의 대상이 아니다.** 적재는 과입금을 `sale_other_costs` 로
 *    흡수했는데 계약서 Total 엔 그 칸이 없어 Balance 가 **음수**로 찍힌다(실측 79대).
 *    그건 계약서 양식의 문제라 여기서 못 고친다.
 *
 *   php artisan ssancarerp:convert-import-receivables            # dry-run (기본)
 *   php artisan ssancarerp:convert-import-receivables --apply
 */
class ConvertImportReceivableToPayment extends Command
{
    /** 적재기가 남긴 표식 — 이 문자열로만 대상을 고른다. */
    private const NOTE_MARKER = '과거데이터 임포트%';

    protected $signature = 'ssancarerp:convert-import-receivables
        {--apply : 실제 전환 (미지정 시 dry-run)}
        {--limit=0 : 처리할 최대 건수 (0=전체)}';

    protected $description = '소급 적재의 「미수 정리」 회수이력을 확정 판매 잔금으로 전환 (기본 dry-run)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $query = ReceivableHistory::query()
            ->where('note', 'like', self::NOTE_MARKER)
            ->where('method', 'other')
            ->whereNull('final_payment_id')
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'vehicle.settlements.salesman'])
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('대상 없음 — 전환할 회수이력이 없다.');

            return self::SUCCESS;
        }

        $this->line(sprintf('대상 %d건%s%s', $total,
            $limit ? " (limit {$limit})" : '',
            $apply ? '' : '  ※ dry-run — 아무것도 저장하지 않는다'));

        $done = 0;
        $drift = [];
        $rows = [];

        foreach (($limit ? $query->limit($limit)->get() : $query->get()) as $rh) {
            $vehicle = $rh->vehicle;
            if (! $vehicle) {
                $drift[] = ['-', $rh->id, '차량 없음'];

                continue;
            }

            // 전환 전 값 — 계산 사슬의 출발점을 그대로 읽는다.
            $before = $this->snapshot($vehicle);

            DB::beginTransaction();
            try {
                $rate = (float) ($rh->exchange_rate ?? $vehicle->exchange_rate ?? 1) ?: 1.0;
                $amount = (float) $rh->amount;

                (new FinalPayment)->forceFill([
                    'vehicle_id' => $vehicle->id,
                    'type' => 'balance',
                    'amount' => $amount,
                    'exchange_rate' => $rate,
                    'amount_krw' => (int) round($amount * $rate),
                    'payment_date' => $rh->collected_at ?: $vehicle->sale_date,
                    'confirmed_at' => now(),
                    'note' => '과거적재 미수 정리 — 회수이력에서 전환',
                ])->save();

                $rh->delete();

                $after = $this->snapshot($vehicle->fresh(['finalPayments', 'receivableHistories', 'settlements.salesman']));
                $diff = $this->compare($before, $after);

                if ($diff !== null) {
                    DB::rollBack();
                    $drift[] = [$vehicle->vehicle_number, $rh->id, $diff];

                    continue;
                }

                if ($apply) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }

                $done++;
                if (count($rows) < 15) {
                    $rows[] = [$vehicle->vehicle_number, $vehicle->currency, number_format($amount, 2), number_format($rate, 2)];
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $drift[] = [$vehicle->vehicle_number, $rh->id, $e->getMessage()];
            }
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['차량번호', '통화', '금액', '환율'], $rows);
            if ($done > count($rows)) {
                $this->line(sprintf('  … 외 %d건 (표는 앞 %d건만)', $done - count($rows), count($rows)));
            }
        }

        $this->newLine();
        $this->info(sprintf('%s %d건%s', $apply ? '전환 완료' : '전환 가능', $done, $apply ? '' : ' (저장 안 함)'));

        if ($drift !== []) {
            $this->newLine();
            $this->error(sprintf('건너뜀 %d건 — 값이 움직이거나 오류. 롤백했다.', count($drift)));
            $this->table(['차량번호', '회수이력 id', '사유'], $drift);
        }

        return self::SUCCESS;
    }

    /** 전환이 건드리면 안 되는 값들. 정산은 id 별로 마진·지급액을 담는다. */
    private function snapshot($vehicle): array
    {
        $settlements = [];
        foreach ($vehicle->settlements as $s) {
            $settlements[$s->id] = [(int) $s->total_margin, (int) $s->actual_payout];
        }

        return [
            'unpaid' => round((float) $vehicle->sale_unpaid_amount, 2),
            'received_krw' => (int) $vehicle->sale_received_krw_accumulated,
            'rate' => round((float) $vehicle->settlement_exchange_rate, 4),
            'settlements' => $settlements,
        ];
    }

    /** 어긋난 항목 이름을 돌려준다(없으면 null). 금액은 소수 잔차를 허용한다. */
    private function compare(array $a, array $b): ?string
    {
        if (abs($a['unpaid'] - $b['unpaid']) > 0.01) {
            return sprintf('미수 %s → %s', $a['unpaid'], $b['unpaid']);
        }
        if (abs($a['received_krw'] - $b['received_krw']) > 1) {
            return sprintf('실입금KRW %s → %s', $a['received_krw'], $b['received_krw']);
        }
        if (abs($a['rate'] - $b['rate']) > 0.0001) {
            return sprintf('정산환율 %s → %s', $a['rate'], $b['rate']);
        }
        foreach ($a['settlements'] as $id => [$margin, $payout]) {
            [$m2, $p2] = $b['settlements'][$id] ?? [null, null];
            if ($m2 !== $margin || $p2 !== $payout) {
                return sprintf('정산#%d 마진 %s→%s · 지급 %s→%s', $id, $margin, $m2, $payout, $p2);
            }
        }

        return null;
    }
}
