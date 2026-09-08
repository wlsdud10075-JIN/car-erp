<?php

namespace App\Services;

use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Setting;
use App\Models\Vehicle;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 바이어 현금 원장 3단계 — 판매잔금이 「바이어가 실제로 보낸 현금」 안에서만 들어가게 한다.
 * 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🔑 **소진 시점 = 재무 확정 시점**(`confirmed_at` 이 채워지는 순간)이다. 실측 근거:
 *    ①미수도 확정분만 센다(`Vehicle::getSaleUnpaidAmountAttribute`) ②채권관리 「입금」이 만드는
 *    미러 잔금은 **Draft** 로 생성된다(실측: 저장 직후 `confirmed_at=NULL`, 미수 불변).
 *    ⇒ 생성 시점에 현금을 빼면 **현금은 줄었는데 미수는 그대로**인 구간이 생긴다. 두 숫자가
 *    같은 순간에 움직여야 사람이 대조할 수 있다.
 *
 * 🚫 **배분은 사람이 고르지 않는다**(jin: 배분은 현 상태 그대로 판매잔금 N행에 기록).
 *    어느 입금에서 뺄지는 FIFO 로 시스템이 정하고, 그 결과를 **행으로 남긴다**
 *    (`buyer_cash_allocations`) — 적립금처럼 매번 재생하면 조인·엑셀·포털로 못 뽑는다.
 */
class BuyerCashService
{
    /** 외화 소수 둘째 자리까지 쓰므로 그 아래 차이는 0 으로 본다. */
    public const EPSILON = BuyerCashReceipt::EPSILON;

    /**
     * 이 잔금이 현금 원장의 대상인가 — **단일 출처**. 게이트·배분·화면이 전부 이걸 본다.
     *
     * 제외를 여기 모아 두는 이유 = 다음 사람이 「구멍」으로 오해하고 막지 않게 하려는 것이다.
     * 각 줄은 jin 이 지정한 범위(판매탭 잔금 + 채권관리 입금 2경로)에서 나온다.
     */
    public function gated(FinalPayment $payment): bool
    {
        if (! Setting::buyerCashEnabled()) {
            return false;                       // 안 켠 회사 — 종전 그대로
        }
        if (FinalPayment::$skipCashGate) {
            return false;                       // 명시 우회(마이그레이션·복구 스크립트)
        }
        if (! auth()->check()) {
            return false;                       // 콘솔 적재·시드 — 기존 creating 가드와 같은 관례
        }
        if ($payment->type !== 'balance') {
            return false;                       // 계약금·중도금·선수금·수수료는 jin 명시 제외
        }
        if ($payment->transfer_id !== null) {
            return false;                       // 차량 간 이체 = 새 돈이 아니라 차량 사이 이동
        }

        $vehicle = $payment->vehicle;
        if (! $vehicle || ! $vehicle->buyer_id) {
            return false;                       // 귀속할 바이어가 없으면 뺄 지갑도 없다
        }

        // 🚨 KRW 차량은 안 막는다 — 국내 거래·원화 판매가 첫날부터 막히면 안 된다.
        return $vehicle->currency !== 'KRW';
    }

    /** 그 차량의 바이어·통화로 지금 쓸 수 있는 현금(외화). 화면 합계와 같은 출처. */
    public function availableFor(Vehicle $vehicle): float
    {
        if (! $vehicle->buyer_id) {
            return 0.0;
        }

        return BuyerCashReceipt::balanceFor($vehicle->buyer_id, $vehicle->currency);
    }

    /**
     * 부족하면 던진다. **행이 생기기 전(creating/updating)에** 부른다 —
     * 세 소비 경로가 전부 DomainException 을 잡아 토스트·필드오류로 보여준다.
     */
    public function assertAvailable(FinalPayment $payment): void
    {
        if (! $this->gated($payment)) {
            return;
        }
        // 🔑 **줄이는 정정은 통과시킨다** (jin 2026-09-07 제보로 발견).
        //    토글을 켜기 «전에» 확정된 잔금은 배분 행이 없어 shortfallBase 가 «전액»을 요구한다.
        //    그래서 채권관리 「적립금으로 전환」처럼 잔금을 **감액**하는 작업이, 현금을 한 푼도 더
        //    쓰지 않는데도 «현금 부족»으로 막혔다. 게이트의 취지는 «현금 밖의 돈이 들어오는 것»을
        //    막는 것이지 이미 기록된 돈을 되돌리는 것을 막는 게 아니다.
        //    ⚠️ 확정으로 넘어가는 순간은 예외다 — 그건 새로 현금을 쓰는 것이라 반드시 검사한다.
        $becomesConfirmed = $payment->isDirty('confirmed_at') && $payment->confirmed_at !== null;
        if (! $becomesConfirmed && $payment->exists && $payment->isDirty('amount')
            && (float) $payment->amount <= (float) $payment->getOriginal('amount') + self::EPSILON) {
            return;
        }

        $need = $this->shortfallBase($payment);
        if ($need <= self::EPSILON) {
            return;                             // 이미 배분된 만큼이면 더 뺄 게 없다
        }

        $available = $this->availableFor($payment->vehicle);
        if ($need - $available > self::EPSILON) {
            $currency = $payment->vehicle->currency;
            throw new DomainException(__('buyer.cash.gate_blocked', [
                'buyer' => $payment->vehicle->buyer?->name ?? '-',
                'need' => number_format($need, 2).' '.$currency,
                'available' => number_format($available, 2).' '.$currency,
                'short' => number_format(max(0, $need - $available), 2).' '.$currency,
            ]));
        }
    }

    /**
     * FIFO 로 배분 행을 만든다. **기존 배분을 지우고 다시 깐다** — 확정 후 금액이 정정되면
     * (마감 전엔 허용된다) 배분 합과 잔금 금액이 어긋나기 때문.
     *
     * ⚠️ 반드시 호출부가 트랜잭션 안이어야 한다 — 세 경로 전부 이미 그렇다
     *    (차량 판매탭 save · 채권관리 saveHistory · PaymentConfirmationService::confirmPayment).
     */
    public function allocate(FinalPayment $payment): void
    {
        if (! $this->gated($payment) || $payment->confirmed_at === null) {
            return;
        }

        DB::transaction(function () use ($payment) {
            BuyerCashAllocation::where('final_payment_id', $payment->id)->delete();

            $remaining = round((float) $payment->amount, 2);
            if ($remaining <= self::EPSILON) {
                return;
            }

            $vehicle = $payment->vehicle;
            $receipts = BuyerCashReceipt::forBuyerCurrency($vehicle->buyer_id, $vehicle->currency)
                ->fifo()
                ->lockForUpdate()
                ->get();

            foreach ($receipts as $receipt) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                // ⚠️ 관계를 eager load 하지 말 것 — 방금 지운 배분이 캐시에 남아 잔액이 부풀어 보인다.
                $free = $receipt->remaining_amount;
                if ($free <= self::EPSILON) {
                    continue;
                }
                $take = round(min($free, $remaining), 2);

                BuyerCashAllocation::create([
                    'receipt_id' => $receipt->id,
                    'final_payment_id' => $payment->id,
                    'vehicle_id' => $payment->vehicle_id,
                    'amount' => $take,
                    'created_by' => auth()->id(),
                ]);
                $remaining = round($remaining - $take, 2);
            }

            if ($remaining > self::EPSILON) {
                // assertAvailable 직후라 여기 오면 동시 저장이 끼어든 것이다. 조용히 두면
                //   「잔금은 들어갔는데 현금은 그만큼 안 빠진」 상태가 남는다 — 통째로 되돌린다.
                throw new DomainException(__('buyer.cash.gate_race'));
            }
        });
    }

    /**
     * 💸 **수수료로 남은 현금을 턴다** (jin 2026-09-08).
     *
     * 바이어가 보낸 돈을 쓰다 보면 **한참 뒤에 송금 수수료가 잡혀** 실제 들어온 돈이 기재액보다
     * 조금 적었던 것으로 드러난다. 그러면 원장에 영영 안 없어지는 잔돈이 남는다(예: 12.35 USD).
     *
     * 판매잔금 배분과 **완전히 같은 FIFO** 로 오래된 입금부터 갉아먹는다 — 같은 테이블을 쓰므로
     * 입금의 `remaining_amount`·`balanceFor` 가 손대지 않아도 따라온다.
     *
     * @param  float  $amount  외화(그 통화 기준). 남은 현금보다 크면 던진다.
     *
     * @throws DomainException 남은 현금이 모자랄 때
     */
    public function chargeFee(BuyerCashFee $fee): void
    {
        DB::transaction(function () use ($fee) {
            // 다시 깔 수 있게 먼저 지운다 — 입금이 지워져 그 수수료를 다시 태워야 할 때 그대로 쓴다
            //   (판매잔금 `allocate()` 와 같은 형태).
            BuyerCashAllocation::where('fee_id', $fee->id)->delete();

            $remaining = round((float) $fee->amount, 2);
            if ($remaining <= self::EPSILON) {
                throw new DomainException(__('buyer.cash.fee_zero'));
            }

            $receipts = BuyerCashReceipt::forBuyerCurrency($fee->buyer_id, $fee->currency)
                ->fifo()
                ->lockForUpdate()
                ->get();

            foreach ($receipts as $receipt) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                // ⚠️ 관계를 eager load 하지 말 것 — 방금 만든 배분이 캐시에 없어 잔액이 부풀어 보인다.
                $free = $receipt->remaining_amount;
                if ($free <= self::EPSILON) {
                    continue;
                }
                $take = round(min($free, $remaining), 2);

                BuyerCashAllocation::create([
                    'receipt_id' => $receipt->id,
                    'fee_id' => $fee->id,
                    // 판매잔금이 아니라 수수료다 — 잔금·차량은 비운다(모델 `isFee()` 가 이걸로 가른다).
                    'final_payment_id' => null,
                    'vehicle_id' => null,
                    'amount' => $take,
                    'created_by' => auth()->id(),
                ]);
                $remaining = round($remaining - $take, 2);
            }

            if ($remaining > self::EPSILON) {
                // 🚫 모자란 만큼만 털고 끝내지 않는다 — 그러면 사람이 적은 금액과 원장이 어긋난 채
                //    「처리됐다」로 보인다. 통째로 되돌리고 얼마가 부족한지 알려준다.
                throw new DomainException(__('buyer.cash.fee_short', [
                    'short' => number_format($remaining, 2),
                    'currency' => $fee->currency,
                ]));
            }
        });
    }

    /** 그 바이어·통화로 지금 수수료로 털 수 있는 최대 금액 = 남은 현금. 화면 안내와 같은 출처. */
    public function feeCeilingFor(int $buyerId, string $currency): float
    {
        return BuyerCashReceipt::balanceFor($buyerId, $currency);
    }

    /**
     * 이미 원장에 물려 있는 잔금만 다시 배분한다 — **금액 정정(감액) 경로 전용** (jin 2026-09-07).
     *
     * 🔑 토글을 켜기 «전에» 확정된 잔금은 배분 행이 없다. 그런 잔금을 감액하면 allocate 가
     *    새 금액 전액을 배분하려 들고, 그 바이어의 현금은 0 이라 race 가드에 걸려 죽는다
     *    (실제로 채권관리 「적립금으로 전환」이 그렇게 막혔다). **원장 밖 돈은 원장 밖에 둔다.**
     * ⚠️ 신규 확정(`confirmed_at` 이 채워지는 순간)에는 쓰지 말 것 — 그건 배분 행이 없는 게
     *    정상이고 반드시 새로 깔아야 한다. 그래서 호출부가 두 경우를 갈라 부른다.
     */
    public function reallocateIfTracked(FinalPayment $payment): void
    {
        if (! BuyerCashAllocation::where('final_payment_id', $payment->id)->exists()) {
            return;
        }

        $this->allocate($payment);
    }

    /** 확정이 풀리거나 대상에서 벗어나면 현금을 돌려놓는다. */
    public function release(FinalPayment $payment): void
    {
        BuyerCashAllocation::where('final_payment_id', $payment->id)->delete();
    }

    /**
     * 확정된 잔금의 금액이 **모델 훅을 안 타는 경로**로 바뀐 뒤 배분을 다시 맞춘다.
     * `ReceivableHistory::syncFinalPayment` 가 query-builder update 를 쓰기 때문에 필요하다 —
     * 안 부르면 금액만 바뀌고 현금은 그대로라 둘이 조용히 어긋난다(SKILLS §8 #66 의 그 자리).
     */
    public function resyncAfterRawUpdate(FinalPayment $payment): void
    {
        if (! $this->gated($payment) || $payment->confirmed_at === null) {
            return;
        }
        $this->assertAvailable($payment);
        // 원장 밖에서 확정된 잔금은 여기서도 건드리지 않는다 — 위 reallocateIfTracked 와 같은 이유.
        if (! BuyerCashAllocation::where('final_payment_id', $payment->id)->exists()) {
            return;
        }
        $this->allocate($payment);
    }

    /** 이번에 새로 빼야 할 금액 = 잔금 금액 − 이 잔금에 이미 배분된 금액. */
    private function shortfallBase(FinalPayment $payment): float
    {
        $already = $payment->exists
            ? (float) BuyerCashAllocation::where('final_payment_id', $payment->id)->sum('amount')
            : 0.0;

        return round((float) $payment->amount - $already, 2);
    }
}
