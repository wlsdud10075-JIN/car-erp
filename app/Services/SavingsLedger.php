<?php

namespace App\Services;

use App\Models\SavingsStatus;
use Illuminate\Support\Collection;

/**
 * 적립금 선입선출(FIFO) 원장 (jin 2026-07-29).
 *
 * 적립금은 `savings_statuses` 에 **유입(+)/사용(−) 이 섞인 단일 원장**으로 쌓인다(잔액은 행마다의
 * 러닝 스냅샷). jin 요구 = ① 사용 시 **먼저 적립된 것부터 차감** ② 적립 시점 환율로 **원화 병기**.
 *
 * ⚠️ 소진량을 컬럼으로 들고 있지 않고 **매번 원장에서 계산**한다. 이유:
 *    REFUND·ADJUSTMENT·CANCELLED 로 유출이 되돌려지면 소진 컬럼은 되감기(unwind) 로직이 필요한데,
 *    그게 틀리면 조용히 어긋난 채 남는다. 총유출을 오래된 유입부터 흡수시키는 계산식은
 *    되돌림이 자동으로 반영되고(유출 총합이 줄면 최신 lot 부터 다시 차오름) 상태가 하나뿐이라 드리프트가 없다.
 *    행 수가 바이어당 수십 건 규모라 비용도 무의미하다.
 *
 * 환율은 **유입 시점에 고정**된다 — 크레딧의 원화 가치는 적립될 때 정해지므로, 사용 시점 환율이 아니다.
 * 그래서 "어느 적립분이 나갔는가"(FIFO)가 곧 원화 환산을 좌우한다. 두 요구가 한 몸인 이유.
 */
class SavingsLedger
{
    /**
     * (바이어 × 통화) 유입 lot 을 오래된 순으로, FIFO 소진을 반영한 잔여와 함께 반환.
     *
     * @return Collection<int, array{
     *   id:int, at:string, type:string, amount:float, remaining:float,
     *   exchange_rate:float|null, krw:int|null, vehicle_id:int|null, note:string|null, consumed:bool
     * }>
     */
    public function lots(int $buyerId, string $currency): Collection
    {
        $rows = SavingsStatus::where('buyer_id', $buyerId)
            ->where('currency', $currency)
            ->orderBy('id')
            ->get();

        // ── 1단계: 행을 유입 lot / 유출 로 분류 ────────────────────────────
        // ⚠️ REFUND·CANCELLED 의 양수는 **새 크레딧이 아니라 나갔던 것의 되감기**다. 유출 총합을
        //    줄이는 쪽으로 처리해야 마지막에 소진됐던 lot 이 **원래 환율 그대로** 되차오른다.
        //    새 lot 으로 만들면 되돌아온 돈이 엉뚱한 환율을 갖게 된다(실측으로 잡은 버그).
        $lots = [];
        $outflow = 0.0;
        foreach ($rows as $r) {
            $amount = (float) $r->savings;

            if ($amount < 0) {
                $outflow += abs($amount);

                continue;
            }
            if (in_array($r->transaction_type, ['REFUND', 'CANCELLED'], true)) {
                $unwind = min($amount, $outflow);
                $outflow -= $unwind;
                $leftover = round($amount - $unwind, 2);
                // 되돌릴 유출이 없는 초과분은 실제 새 크레딧이다 → lot 으로 남긴다
                // (이래야 Σ잔여 = 러닝 잔액 불변식이 유지된다).
                if ($leftover > 0.004) {
                    $lots[] = [$r, $leftover];
                }

                continue;
            }
            $lots[] = [$r, $amount];
        }

        // ── 2단계: 남은 유출을 오래된 lot 부터 흡수 (FIFO) ──────────────────
        return collect($lots)->map(function (array $pair) use (&$outflow) {
            [$r, $amount] = $pair;
            $eaten = min($outflow, $amount);
            $outflow -= $eaten;
            $remaining = round($amount - $eaten, 2);
            $rate = $r->exchange_rate !== null ? (float) $r->exchange_rate : null;

            return [
                'id' => $r->id,
                'at' => $r->created_at?->format('Y-m-d') ?? '',
                'type' => $r->transaction_type,
                'amount' => $amount,
                'remaining' => $remaining,
                'exchange_rate' => $rate,
                'krw' => ($rate !== null && $rate > 0) ? (int) round($remaining * $rate) : null,
                'vehicle_id' => $r->vehicle_id,
                'note' => $r->note,
                'consumed' => $remaining <= 0.004,   // 통화 소수 2자리 기준
            ];
        })->values();
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
     * 지금 `$amount` 를 쓰면 어느 lot 이 얼마씩 나가는지 (표시·검증용, 저장 안 함).
     *
     * @return array{lots:array<int,array{id:int,at:string,take:float,exchange_rate:float|null,krw:int|null}>, krw:int, shortfall:float}
     *                                                                                                                                   shortfall > 0 이면 잔액 부족분
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
