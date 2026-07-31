<?php

namespace App\Services;

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\CashSnapshot;
use App\Models\ForwardingInvoice;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * 대표 자금 현황 / 손익 계산 (jin 2026-07-23).
 *
 * 데이터 3층:
 *   - 통장 잔액(KRW/USD/EUR) = 수동 입력(재무·관리·업무관리자), 펌뱅킹 전까지.
 *   - 재고·미수·미지급 = ERP 자동 (입력 시점 캡처 → CashSnapshot).
 *   - 투입 원금(밑천) = Setting capital_principal_krw (super가 기능설정에서).
 *
 * 파생:
 *   청산가치 = 통장현금 + 재고 + 경매보증금 − 미지급 − 가수금   (미수 제외 — 대표 정책, 국내 청산 기준)
 *   굴리는 자금 = 청산가치 + 미수               (운전자본 전체)
 *   손익 = 청산가치 − 투입원금                  (원금 미설정 시 null)
 */
class CapitalStatusService
{
    public const PRINCIPAL_KEY = 'capital_principal_krw';

    private const FX_FALLBACK = ['USD' => 1495, 'EUR' => 1682];

    /** 통화별 최신 환율(→KRW). 최근 판매 차량 exchange_rate, 없으면 fallback. */
    public function fxRates(): array
    {
        $fx = [];
        foreach (['USD', 'EUR'] as $c) {
            $r = Vehicle::where('currency', $c)->where('exchange_rate', '>', 0)
                ->orderByDesc('sale_date')->value('exchange_rate');
            $fx[$c] = $r ? (float) $r : (float) self::FX_FALLBACK[$c];
        }

        return $fx;
    }

    /**
     * 재고 원가 합 = 차에 묶인 자본 (미출고 AND 거래완료 아님).
     * ⚠️ 완납 여부 무관 — 미지급을 별도로 빼므로(현금+재고−미지급), 완납만 세면 자산-부채 비대칭.
     *   재고 화면(scopeInStock, 완납 요구)과 정의가 다름: 여기는 회계상 "묶인 자본" 관점.
     *   대표 승인 프로토타입(31.87억)과 동일 정의. null-safe(orWhereNull → 상태 불명은 재고 잔존).
     */
    public function inventoryKrw(): int
    {
        return (int) Vehicle::whereNull('warehouse_out_date')
            ->where('purchase_price', '>', 0)
            ->where(function ($q) {
                $q->where('progress_status_cache', '!=', '거래완료')
                    ->orWhereNull('progress_status_cache');
            })
            ->sum('purchase_price');
    }

    /**
     * 미지급 합 = 회사가 갚아야 할 돈 (청산가치에서 차감, 양수만).
     *   ① 매입 미지급 (딜러 줄 돈) + ② 포워딩 운임 미지급 + ③ 정산 지급대기(confirmed, 영업 줄 돈).
     *   ②③은 computed accessor 라 SQL sum 불가 → 소량이라 반복 합산. (jin 2026-07-26 완결성 보강)
     */
    public function payableKrw(): int
    {
        $total = 0;
        Vehicle::where('purchase_price', '>', 0)
            ->with('purchaseBalancePayments')
            ->chunkById(300, function ($chunk) use (&$total) {
                foreach ($chunk as $v) {
                    $u = (int) $v->purchase_unpaid_amount;
                    if ($u > 0) {
                        $total += $u;
                    }
                }
            });

        // ② 포워딩 운임 미지급 (paid_at NULL = 미지급 단일출처) — 전액 환산 KRW.
        $total += (int) ForwardingInvoice::whereNull('paid_at')->get()
            ->sum(fn ($i) => max(0, (int) $i->converted_krw));

        // ③ 정산 지급대기 (settlement_status=confirmed, 지급 전) — 영업에게 줄 실지급액.
        $total += (int) Settlement::where('settlement_status', 'confirmed')->get()
            ->sum(fn ($s) => max(0, (int) $s->actual_payout));

        return $total;
    }

    /** 미수 합 (통화별 native → KRW 환산). */
    public function receivableKrw(array $fx): int
    {
        $total = 0;
        Vehicle::where('sale_price', '>', 0)
            ->with(['finalPayments', 'receivableHistories'])
            ->chunkById(300, function ($chunk) use (&$total, $fx) {
                foreach ($chunk as $v) {
                    $u = (float) $v->sale_unpaid_amount;
                    if ($u <= 0) {
                        continue;
                    }
                    $c = $v->currency ?: 'KRW';
                    $total += (int) round($c === 'KRW' ? $u : $u * ($fx[$c] ?? 0));
                }
            });

        return $total;
    }

    /** 투입 원금 (Setting, 미설정 시 null). */
    public function principal(): ?int
    {
        $v = Setting::get(self::PRINCIPAL_KEY);

        return ($v === null || $v === '') ? null : (int) $v;
    }

    /**
     * 오늘 통장잔액 입력 → 그 시점 ERP 캡처하여 스냅샷 저장(하루 1건 upsert).
     *
     * @param  array{krw:int|float,usd:int|float,eur:int|float}  $balances
     */
    public function capture(array $balances, ?User $user = null, ?string $date = null): CashSnapshot
    {
        $fx = $this->fxRates();
        $date ??= now()->toDateString();

        // date 캐스트가 'Y-m-d 00:00:00' 로 저장돼 updateOrCreate 의 정확매칭이 실패 → whereDate 로 조회.
        $snap = CashSnapshot::whereDate('snapshot_date', $date)->first()
            ?? new CashSnapshot(['snapshot_date' => $date]);
        $snap->fill([
            'balance_krw' => (int) round($balances['krw'] ?? 0),
            'balance_usd' => round((float) ($balances['usd'] ?? 0), 2),
            'balance_eur' => round((float) ($balances['eur'] ?? 0), 2),
            'inventory_krw' => $this->inventoryKrw(),
            'receivable_krw' => $this->receivableKrw($fx),
            'payable_krw' => $this->payableKrw(),
            // 예치·가수금 (안건4 2단계) — 실시간이 아니라 이 시점 값을 박는다.
            //   통장잔액과 같은 시점이어야 짝이 맞는다(§derive 주석 참조).
            // ⚠️ **갚아야 할 돈(liability)만** 담는다 (jin 2026-07-31). 대표 본인 돈(equity)은
            //   갚을 의무가 없어 청산가치에서 빼지 않는다. 기본값이 liability 라 분류 전에는 현행과 동일.
            'advance_krw' => AdvanceReceipt::liabilityKrw(),
            'auction_deposit_krw' => AuctionDeposit::totalKrw(),
            'fx_usd' => $fx['USD'],
            'fx_eur' => $fx['EUR'],
            'entered_by' => $user?->id,
        ])->save();

        return $snap;
    }

    /** 최신 스냅샷. */
    public function latest(): ?CashSnapshot
    {
        return CashSnapshot::orderByDesc('snapshot_date')->first();
    }

    /** 스냅샷 → 파생 지표 (청산가치·굴리는자금·손익). */
    public function derive(?CashSnapshot $s): array
    {
        if (! $s) {
            return ['has_data' => false];
        }
        $cash = $s->cash_krw;
        $advance = (int) $s->advance_krw;                 // 가수금 = 갚는 돈(부채)
        $auctionDeposit = (int) $s->auction_deposit_krw;  // 경매보증금 = 예치한 우리 돈(자산)

        // 청산가치 = 통장현금 + 재고 + 경매보증금 − 미지급 − 가수금   (미수 제외 — 대표 정책)
        //   보증금·가수금은 "형태만 바뀌는" 거래라 반영 후에는 청산가치가 안 움직인다:
        //   보증금 예치 = 현금↓ 보증금↑ / 가수금 입금 = 현금↑ 부채↑. 그게 정상이다.
        $liquidation = $cash + $s->inventory_krw + $auctionDeposit - $s->payable_krw - $advance;
        $working = $liquidation + $s->receivable_krw;
        $principal = $this->principal();

        return [
            'has_data' => true,
            'date' => $s->snapshot_date,
            'cash_krw' => $cash,
            'balance_krw' => (int) $s->balance_krw,
            'balance_usd' => (float) $s->balance_usd,
            'balance_eur' => (float) $s->balance_eur,
            'inventory_krw' => (int) $s->inventory_krw,
            'receivable_krw' => (int) $s->receivable_krw,
            'payable_krw' => (int) $s->payable_krw,
            'advance_krw' => $advance,
            'auction_deposit_krw' => $auctionDeposit,
            'fx_usd' => (float) $s->fx_usd,
            'fx_eur' => (float) $s->fx_eur,
            'liquidation_krw' => $liquidation,
            'working_capital_krw' => $working,
            'principal_krw' => $principal,
            'profit_krw' => $principal === null ? null : $liquidation - $principal,
        ];
    }

    /**
     * 추이 (최근 N개 스냅샷, 오래된→최신).
     *
     * grain: 'day'(원본 일별) / 'week' / 'month' / 'year'.
     *   day 외에는 기간별로 묶어 **마지막 스냅샷**(기간 말 시점 잔액)만 남김 — 잔액은 시점값이라
     *   합계·평균이 아니라 "기간 말 잔액"이 맞다. 반환은 항상 오래된→최신, 최근 $limit 개.
     */
    public function history(int $limit = 90, string $grain = 'day'): Collection
    {
        $raw = CashSnapshot::orderBy('snapshot_date')->get();

        if ($grain === 'day') {
            return $raw->values()->take(-$limit)->values();
        }

        $keyFn = match ($grain) {
            'week' => fn ($s) => $s->snapshot_date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => fn ($s) => $s->snapshot_date->format('Y-m'),
            'year' => fn ($s) => $s->snapshot_date->format('Y'),
            default => fn ($s) => $s->snapshot_date->format('Y-m-d'),
        };

        return $raw->groupBy($keyFn)
            ->map(fn (Collection $group) => $group->sortBy('snapshot_date')->last())   // 기간 말 잔액
            ->sortKeys()
            ->values()
            ->take(-$limit)
            ->values();
    }
}
