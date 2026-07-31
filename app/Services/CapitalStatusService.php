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

    /** 거래완료(B/L 발급 = 소유권 이전) 전 차량만 — 세 재고 계산의 공통 조건. */
    private function notHandedOver($q)
    {
        return $q->where(function ($q2) {
            $q2->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache');
        });
    }

    /**
     * 재고 원가 합 = **선적 전**(한국에 남아 있는) 차에 묶인 자본. 청산가치용.
     *
     * ⚠️ 2026-07-31 기준 변경: `warehouse_out_date`(출고일) → `shipping_date`(선적일).
     *   출고일은 **위치 이동**(우리 야드 → 항구 야드)이지 소유권 이동이 아니다. 한국을 떠나는 건 선적일이고,
     *   배 타기 전이면 돈을 못 받았을 때 안 보내고 되팔 수 있으므로 청산 시 자산으로 친다(jin).
     *   실제로 07-29 출고일 소급 입력만으로 손익이 +9.85억 → -5.01억으로 뒤집혔다.
     * ⚠️ 완납 여부 무관 — 미지급을 별도로 빼므로 완납만 세면 자산-부채 비대칭.
     *   재고 화면(scopeInStock)과 정의가 다르다: 그쪽은 "어디 있나", 여기는 "아직 우리 것인가".
     */
    public function inventoryKrw(): int
    {
        return (int) $this->notHandedOver(
            Vehicle::whereNull('shipping_date')->where('purchase_price', '>', 0)
        )->sum('purchase_price');
    }

    /**
     * 선수금 = **선적 전인데 이미 받은 판매대금**. 청산가치에서 차감한다.
     *
     * 차를 팔고 돈은 받았는데 아직 안 실었다면, 회사를 정리할 때 그 차를 되팔아도 **받은 돈은 반환**해야 한다.
     * 이걸 안 빼면 차(재고)와 현금(통장)을 둘 다 자산으로 세는 이중계상이 된다.
     * 실측(2026-07-31): 선적 전 재고 7.63억 중 7.13억이 이미 팔린 차, 그중 받은 돈 2.67억.
     */
    public function advancePaymentKrw(array $fx): int
    {
        $total = 0;
        $this->notHandedOver(
            Vehicle::whereNull('shipping_date')->where('purchase_price', '>', 0)->where('sale_price', '>', 0)
        )->with(['finalPayments', 'receivableHistories'])
            ->chunkById(200, function ($chunk) use (&$total, $fx) {
                foreach ($chunk as $v) {
                    $got = (float) $v->sale_total_amount - (float) $v->sale_unpaid_amount;
                    if ($got <= 0) {
                        continue;
                    }
                    $c = $v->currency ?: 'KRW';
                    $total += (int) round($c === 'KRW' ? $got : $got * ($fx[$c] ?? 0));
                }
            });

        return $total;
    }

    /**
     * 안 팔린 차의 매입가 — **순자산(굴리는 자금)** 계산용.
     *
     * 차를 파는 순간 그 가치는 "차"에서 "받을 돈(미수)" 또는 "받은 돈(현금)"으로 형태가 바뀐다.
     * 순자산은 미수를 자산에 넣으므로, 팔린 차를 재고로 또 세면 이중계상이다.
     * (청산가치는 반대로 미수를 안 세는 대신 선적 전 차를 재고로 세고 선수금을 뺀다.)
     */
    public function unsoldInventoryKrw(): int
    {
        return (int) $this->notHandedOver(
            Vehicle::where('purchase_price', '>', 0)
                ->where(fn ($q) => $q->where('sale_price', '<=', 0)->orWhereNull('sale_price'))
        )->sum('purchase_price');
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
            'advance_payment_krw' => $this->advancePaymentKrw($fx),
            'unsold_inventory_krw' => $this->unsoldInventoryKrw(),
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
    /**
     * 스냅샷 → 파생값. **청산가치·순자산 공식의 단일 출처다.**
     *
     * @param  int|null  $principal  투입원금. 추이처럼 여러 건을 돌 때 밖에서 한 번만 조회해 넘기면
     *                               Setting 조회가 N번 반복되지 않는다. null 이면 여기서 조회한다.
     */
    public function derive(?CashSnapshot $s, ?int $principal = null): array
    {
        if (! $s) {
            return ['has_data' => false];
        }
        $cash = $s->cash_krw;
        $advance = (int) $s->advance_krw;                 // 가수금 = 갚는 돈(부채)
        $auctionDeposit = (int) $s->auction_deposit_krw;  // 경매보증금 = 예치한 우리 돈(자산)

        /*
         * 청산가치 = 통장현금 + 선적전재고 − 선수금 + 경매보증금 − 미지급 − 가수금(갚을 돈)
         *   "지금 접으면 확실히 손에 쥐는 돈". 미수는 받을지 모르므로 제외한다(대표 정책).
         *   선수금 = 선적 전인데 이미 받은 대금 → 되팔면 돌려줘야 하므로 차감(이중계상 제거, 2026-07-31).
         *   보증금·가수금은 "형태만 바뀌는" 거래라 반영 후에는 청산가치가 안 움직인다:
         *   보증금 예치 = 현금↓ 보증금↑ / 가수금 입금 = 현금↑ 부채↑. 그게 정상이다.
         *
         * 순자산(working) = 통장현금 + 미판매재고 + 미수 + 경매보증금 − 미지급 − 가수금
         *   회계적 실체. 팔린 차의 가치는 이미 현금·미수로 옮겨갔으므로 재고에서 뺀다.
         *
         * ⚠️ 새 컬럼이 없는 과거 스냅샷은 **옛 방식으로 폴백**한다(그 시점 기록을 그대로 읽기 위함).
         */
        $advancePayment = (int) ($s->advance_payment_krw ?? 0);
        $liquidation = $cash + $s->inventory_krw - $advancePayment + $auctionDeposit - $s->payable_krw - $advance;

        $unsold = $s->unsold_inventory_krw;
        $working = $unsold === null
            ? $liquidation + $s->receivable_krw                                                   // 구 스냅샷 폴백
            : $cash + (int) $unsold + (int) $s->receivable_krw + $auctionDeposit - $s->payable_krw - $advance;
        $principal ??= $this->principal();

        return [
            'has_data' => true,
            'date' => $s->snapshot_date,
            'cash_krw' => $cash,
            'balance_krw' => (int) $s->balance_krw,
            'balance_usd' => (float) $s->balance_usd,
            'balance_eur' => (float) $s->balance_eur,
            'inventory_krw' => (int) $s->inventory_krw,
            'advance_payment_krw' => $advancePayment,
            'unsold_inventory_krw' => $unsold === null ? null : (int) $unsold,
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
