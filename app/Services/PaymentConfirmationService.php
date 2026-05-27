<?php

namespace App\Services;

use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 큐 20-B — 매입·판매 잔금 재무 확정 Service (회의록 2026-05-17, P2 채택).
 *
 * SAP/Odoo Draft/Posted 정석 패턴:
 *   영업이 잔금 row 입력 = Draft (confirmed_at IS NULL, ledger 미반영)
 *   ↓ 재무가 confirmPayment() 호출
 *   confirmed_at SET = Posted (ledger 반영, Vehicle::getSaleUnpaidAmountAttribute 분자에 포함)
 *
 * ledger == sale_unpaid_amount / purchase_unpaid_amount 단일 기준 → 회계 무결성 SoT.
 *
 * 가드:
 *   1. 권한 — canConfirmFinance (super/admin/role='재무')
 *   2. 재확정 차단 — confirmed_at 이미 SET이면 차단
 *   3. transfer 잔금 차단 — final_payments.transfer_id IS NOT NULL은
 *      InterVehicleTransfer 시스템이 관리 (이중 확정 방지)
 *   4. paid Settlement H4 — vehicle에 paid 정산 있으면 차단
 *      (retroactive ledger 변경 방어, InterVehicleTransferService 동일 패턴)
 *
 * 트랜잭션: DB::transaction 안에서 update — 동시 호출 시 row lock 의존.
 *
 * 큐 20-D 예정: FinalPayment::updating 훅으로 confirmed_at SET 후 mutation 차단 추가.
 */
class PaymentConfirmationService
{
    /**
     * 판매 잔금 재무 확정 — final_payments.confirmed_at SET.
     *
     * @throws DomainException 권한/재확정/transfer/paid 가드 위반 시
     */
    public function confirmPayment(FinalPayment $payment, User $financeUser, ?string $note = null): void
    {
        if (! $financeUser->canConfirmFinance()) {
            throw new DomainException('재무 확정 권한이 없습니다 (settlement role 필요).');
        }

        $payment = $payment->fresh();

        if ($payment->transfer_id !== null) {
            throw new DomainException('자금 이체로 생성된 잔금은 InterVehicleTransfer 흐름에서 재무 확정됩니다. 본 Service로 직접 확정할 수 없습니다.');
        }

        $this->assertPaidSettlementGuard($payment->vehicle);

        // claudefinalreview C — 동시/중복 확정(더블클릭·재전송) 시 확정자·스냅샷 덮어쓰기(감사오염) 방지.
        // 행 잠금(lockForUpdate)으로 직렬화 후 재확인 — 2번째 호출은 confirmed_at SET 된 걸 보고 차단.
        // ⚠️ 모델 update 유지(query-builder 아님) → saved 이벤트로 부모 차량 캐시 refresh 보존.
        // (SQLite 테스트는 lockForUpdate 무동작이나 순차 재확인은 검증됨 / 운영 MySQL 은 실제 row lock.)
        DB::transaction(function () use ($payment, $financeUser, $note) {
            $locked = FinalPayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked === null || $locked->confirmed_at !== null) {
                throw new DomainException('이미 재무 확정된 잔금입니다 (재확정 차단).');
            }
            $locked->update([
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => now(),
                'finance_note' => $note,
            ]);
        });

        $payment->refresh();
    }

    /**
     * 매입 잔금 재무 확정 — purchase_balance_payments.confirmed_at SET.
     *
     * @throws DomainException 권한/재확정/paid 가드 위반 시
     */
    public function confirmPurchasePayment(PurchaseBalancePayment $payment, User $financeUser, ?string $note = null): void
    {
        if (! $financeUser->canConfirmFinance()) {
            throw new DomainException('재무 확정 권한이 없습니다 (settlement role 필요).');
        }

        $payment = $payment->fresh();

        $this->assertPaidSettlementGuard($payment->vehicle);

        // claudefinalreview C — 행 잠금으로 동시/중복 확정 직렬화 + 재확인 (감사오염 방지, 캐시 이벤트 보존).
        DB::transaction(function () use ($payment, $financeUser, $note) {
            $locked = PurchaseBalancePayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked === null || $locked->confirmed_at !== null) {
                throw new DomainException('이미 재무 확정된 매입 잔금입니다 (재확정 차단).');
            }
            $locked->update([
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => now(),
                'finance_note' => $note,
            ]);
        });

        $payment->refresh();
    }

    /**
     * H4 paid Settlement 가드 — InterVehicleTransferService 동일 패턴.
     * vehicle에 paid 정산이 있으면 ledger retroactive 변경 차단.
     */
    private function assertPaidSettlementGuard(?Vehicle $vehicle): void
    {
        if ($vehicle && $vehicle->settlements()->where('settlement_status', 'paid')->exists()) {
            throw new DomainException("차량({$vehicle->vehicle_number})에 paid 정산이 있어 잔금을 재무 확정할 수 없습니다.");
        }
    }
}
