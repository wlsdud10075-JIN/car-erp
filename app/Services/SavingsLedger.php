<?php

namespace App\Services;

use App\Models\SavingsStatus;
use Illuminate\Support\Collection;

/**
 * 적립금 선입선출(FIFO) 원장 (jin 2026-07-29).
 *
 * 적립금은 `savings_statuses` 에 **유입(+)/사용(−) 이 섞인 단일 원장**으로 쌓인다(잔액은 행마다의
 * 러닝 스냅샷). jin 요구:
 *   ① 사용 시 **먼저 적립된 것부터 차감**
 *   ② 적립 시점 환율로 **원화 병기**
 *   ③ 사용 기록에 **"언제 적립분을 얼마 썼고, 그 적립분에 얼마 남았는지"** 가 보일 것
 *
 * ③ 때문에 최종 잔여만 계산해서는 부족하고 **거래별 소진 내역(allocation)** 을 남겨야 한다.
 * 그래서 원장을 처음부터 순서대로 시뮬레이션한다.
 *
 * ⚠️ 소진량을 DB 컬럼으로 들고 있지 않고 매번 원장에서 재현한다. 되감기(REFUND·CANCELLED) 로직이
 *    틀리면 컬럼은 조용히 어긋난 채 남지만, 재현식은 상태가 원장 하나뿐이라 드리프트가 없다.
 *    행 수가 바이어당 수십 건 규모라 비용도 무의미하다.
 *
 * ⚠️ 환율은 **유입 시점에 고정**된다 — 크레딧의 원화 가치는 적립될 때 정해지므로 사용 시점 환율이 아니다.
 *    그래서 "어느 적립분이 나갔는가"(FIFO)가 곧 원화 환산을 좌우한다.
 *
 * ⚠️ 되돌림(REFUND·CANCELLED 의 양수)은 **새 크레딧이 아니라 나갔던 것의 되감기**다. 마지막에 소진된
 *    적립분부터(LIFO) **원래 환율 그대로** 되차오른다. 새 lot 으로 만들면 되돌아온 돈이 되돌림 행의
 *    환율을 갖게 돼 원화 가치가 바뀐다.
 */
class SavingsLedger
{
    /** @var array<string, array> 같은 요청 안에서 재계산 방지 */
    private array $memo = [];

    /**
     * 원장을 순서대로 시뮬레이션해 lot 잔여와 거래별 소진 내역을 만든다.
     *
     * @return array{lots: array<int, array>, rows: array<int, array>}
     */
    private function simulate(int $buyerId, string $currency): array
    {
        $key = $buyerId.'|'.$currency;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $records = SavingsStatus::where('buyer_id', $buyerId)
            ->where('currency', $currency)
            ->orderBy('id')
            ->get();

        $lots = [];          // 유입 lot (선입선출 대상)
        $rows = [];          // row_id => 표시용 소진/적립 정보
        $consumed = [];      // 되감기용 스택 — [lotIdx, amount] 소진 순서

        $newLot = function (SavingsStatus $r, float $amount) use (&$lots): int {
            $lots[] = [
                'id' => $r->id,
                'at' => $r->created_at?->format('Y-m-d') ?? '',
                'amount' => $amount,
                'remaining' => $amount,
                'exchange_rate' => $r->exchange_rate !== null ? (float) $r->exchange_rate : null,
                'vehicle_id' => $r->vehicle_id,
            ];

            return array_key_last($lots);
        };

        foreach ($records as $r) {
            $amount = (float) $r->savings;
            $isUnwind = in_array($r->transaction_type, ['REFUND', 'CANCELLED'], true);

            // ── 유입: 새 적립분 ──────────────────────────────────────────
            if ($amount > 0 && ! $isUnwind) {
                $idx = $newLot($r, $amount);
                $rows[$r->id] = ['kind' => 'in', 'lot_index' => $idx, 'takes' => []];

                continue;
            }

            // ── 되감기: 마지막에 나간 적립분부터 되돌린다 (LIFO) ──────────
            if ($amount > 0 && $isUnwind) {
                $left = $amount;
                $backs = [];
                while ($left > 0.004 && $consumed !== []) {
                    $top = &$consumed[array_key_last($consumed)];
                    $give = min($left, $top['amount']);
                    $lots[$top['lot_index']]['remaining'] = round($lots[$top['lot_index']]['remaining'] + $give, 2);
                    $top['amount'] = round($top['amount'] - $give, 2);
                    $left = round($left - $give, 2);
                    $backs[] = ['lot_index' => $top['lot_index'], 'amount' => $give];
                    if ($top['amount'] <= 0.004) {
                        array_pop($consumed);
                    }
                    unset($top);
                }
                // 되돌릴 소진이 없는 초과분은 실제 새 크레딧 (Σ잔여 = 잔액 불변식 유지)
                $extraIdx = null;
                if ($left > 0.004) {
                    $extraIdx = $newLot($r, $left);
                }
                $rows[$r->id] = ['kind' => 'back', 'backs' => $backs, 'lot_index' => $extraIdx, 'takes' => []];

                continue;
            }

            // ── 유출: 오래된 적립분부터 차감 (FIFO) ───────────────────────
            $left = abs($amount);
            $takes = [];
            foreach ($lots as $i => &$lot) {
                if ($left <= 0.004) {
                    break;
                }
                if ($lot['remaining'] <= 0.004) {
                    continue;
                }
                $take = min($left, $lot['remaining']);
                $lot['remaining'] = round($lot['remaining'] - $take, 2);
                $left = round($left - $take, 2);
                $consumed[] = ['lot_index' => $i, 'amount' => $take];
                $takes[] = [
                    'lot_index' => $i,
                    'at' => $lot['at'],
                    'take' => $take,
                    'lot_left' => $lot['remaining'],       // 그 적립분에 남은 액수 (jin 요구 ③)
                    'exchange_rate' => $lot['exchange_rate'],
                ];
            }
            unset($lot);
            $rows[$r->id] = ['kind' => 'out', 'takes' => $takes, 'shortfall' => max(0, $left)];
        }

        return $this->memo[$key] = ['lots' => $lots, 'rows' => $rows];
    }

    /**
     * (바이어 × 통화) 유입 적립분을 오래된 순으로, FIFO 소진 반영 잔여와 함께.
     *
     * @return Collection<int, array{id:int, at:string, amount:float, remaining:float, exchange_rate:float|null, krw:int|null, consumed:bool}>
     */
    public function lots(int $buyerId, string $currency): Collection
    {
        return collect($this->simulate($buyerId, $currency)['lots'])->map(function (array $l) {
            $rate = $l['exchange_rate'];

            return $l + [
                'krw' => ($rate !== null && $rate > 0) ? (int) round($l['remaining'] * $rate) : null,
                'consumed' => $l['remaining'] <= 0.004,
            ];
        })->values();
    }

    /**
     * 거래(row) 별 선입선출 내역 — 화면 표에 "언제 적립분을 얼마 썼고 그 적립분 잔여는 얼마"를 찍기 위함.
     *
     * @return array<int, array> row_id => ['kind'=>'in'|'out'|'back', 'takes'=>[...], ...]
     */
    public function rowDetails(int $buyerId, string $currency): array
    {
        $sim = $this->simulate($buyerId, $currency);
        $lots = $sim['lots'];

        // lot_index 를 화면이 바로 쓸 수 있는 값으로 풀어준다.
        foreach ($sim['rows'] as $rowId => &$row) {
            if ($row['kind'] === 'in' && $row['lot_index'] !== null) {
                $lot = $lots[$row['lot_index']];
                $row['remaining'] = $lot['remaining'];
                $row['exchange_rate'] = $lot['exchange_rate'];
            }
            if ($row['kind'] === 'back') {
                $row['backs'] = array_map(fn ($b) => [
                    'at' => $lots[$b['lot_index']]['at'],
                    'amount' => $b['amount'],
                ], $row['backs']);
            }
        }

        return $sim['rows'];
    }

    /**
     * 잔여 적립금의 원화 환산 합.
     *
     * @return array{krw:int, unrated:float} unrated = 환율이 없어 환산 못한 잔여 외화액
     */
    public function balanceKrw(int $buyerId, string $currency): array
    {
        $krw = 0;
        $unrated = 0.0;

        foreach ($this->lots($buyerId, $currency) as $lot) {
            if ($lot['remaining'] <= 0) {
                continue;
            }
            if ($lot['krw'] === null) {
                $unrated += $lot['remaining'];

                continue;
            }
            $krw += $lot['krw'];
        }

        return ['krw' => $krw, 'unrated' => round($unrated, 2)];
    }

    /**
     * 지금 `$amount` 를 쓰면 어느 적립분이 얼마씩 나가는지 (표시·검증용, 저장 안 함).
     *
     * @return array{lots:array<int,array{id:int,at:string,take:float,exchange_rate:float|null,krw:int|null}>, krw:int, shortfall:float}
     */
    public function plan(int $buyerId, string $currency, float $amount): array
    {
        $take = [];
        $krw = 0;
        $left = round($amount, 2);

        foreach ($this->lots($buyerId, $currency) as $lot) {
            if ($left <= 0) {
                break;
            }
            if ($lot['remaining'] <= 0) {
                continue;
            }
            $t = min($left, $lot['remaining']);
            $left = round($left - $t, 2);
            $lotKrw = ($lot['exchange_rate'] !== null && $lot['exchange_rate'] > 0)
                ? (int) round($t * $lot['exchange_rate'])
                : null;
            $krw += $lotKrw ?? 0;
            $take[] = [
                'id' => $lot['id'], 'at' => $lot['at'], 'take' => $t,
                'exchange_rate' => $lot['exchange_rate'], 'krw' => $lotKrw,
            ];
        }

        return ['lots' => $take, 'krw' => $krw, 'shortfall' => max(0, $left)];
    }
}
