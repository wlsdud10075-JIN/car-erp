<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 신규 정산 게이트 **예외** — 사유만 쓰면 통과하는 처리 (jin 2026-09-12).
 * 정본 = `docs/design/settlement-gate-exception.md`.
 *
 * 🔑 **왜 필요한가** — 자동 생성이 `미수 > 0` 에서 멈추는데(`Vehicle::settlementBlockers`),
 *    운임비는 **정산액에 1원도 영향을 안 주면서** 미수를 만든다. 실측 heymanerp 6건이
 *    미수 = 운임비 그대로인 채 2개월째 담당자 정산이 안 나가고 있었다.
 *
 * 🚫 **금액 조건은 없다**(jin «미수가 얼마든 예외처리»). 소액·운임 자동 판정도 하지 않는다 —
 *    입금이 항목별로 귀속되지 않아 「운임비만 남은 미수」는 원리상 판별 불가이고,
 *    통화별 임계는 곧 뚫린다(SKILLS §8 #49·#72·#83). 사람이 사유를 쓴다.
 *
 * 🚫 **화면에서 조건을 옮겨 적지 말 것** — 대상 판정·생성·해제가 전부 여기 모여 있어야
 *    미리보기와 실행이 갈리지 않는다(§8 #44·#67).
 */
class SettlementGateOverrideService
{
    /** 사유 최소 길이 — 회계 잠금 해제와 같은 기준. */
    public const MIN_REASON_LENGTH = 10;

    /**
     * 예외로 넘길 수 있는 사유 — **이 둘만**이다.
     *
     * 담당자 없음·판매가 0·중복 정산·매입취소·내수 차액 0 은 예외 사유가 아니라 **입력 미비**다.
     * 그건 계속 막는다 — 예외로 뚫으면 담당자 없는 정산이 만들어져 지급 대상이 사라진다.
     */
    public const OVERRIDABLE = ['unpaid', 'freight_unconfirmed'];

    /**
     * 「예외 대상」 목록 — 정산이 없고, 남은 사유가 넘길 수 있는 것뿐인 차량.
     *
     * 🧭 **SQL 로 먼저 좁히고 accessor 로 확정한다.** 앞의 네 조건은 하나라도 거짓이면
     *    `OVERRIDABLE` 밖의 사유가 생기므로 **진짜 후보를 떨어뜨릴 수 없다**(순수 상위집합).
     *    나머지(미수·운임확정·내수 차액)는 캐시가 아니라 accessor 로 본다 — SQL 로 옮겨 적으면
     *    「목록엔 뜨는데 눌러도 안 되는」 행이 생긴다(§8 #44).
     *
     * 🚨 **`wire:poll` 이 도는 렌더에 올리지 말 것.** 미수 accessor 가 행마다 잔금·회수이력을 타므로
     *    모달을 열 때만 계산해야 한다. eager load 3종이 그 N+1 을 막는다.
     */
    public function candidates()
    {
        return Vehicle::query()
            ->with(['salesman', 'finalPayments', 'receivableHistories', 'buyer'])
            ->where('sale_price', '>', 0)                       // no_sale 이면 예외 대상 아님
            ->whereNotNull('salesman_id')->whereHas('salesman')  // no_salesman 이면 예외 대상 아님
            ->whereDoesntHave('settlements')                     // already_exists 이면 예외 대상 아님
            ->where(fn ($q) => $q->whereNull('cancel_status')    // purchase_cancelled 이면 예외 대상 아님
                ->orWhere('cancel_status', Vehicle::CANCEL_NONE))
            ->orderByDesc('sale_date')
            ->orderByDesc('id')   // 동점 tie-break — 적재분은 같은 날짜가 수백 건이다(§8 #92)
            ->get()
            ->filter(function (Vehicle $v) {
                $b = $v->settlementBlockers();
                // 🔑 여기서 계산한 사유를 그 자리에 담아 둔다 — 화면이 칩을 그리려고 다시 부르면
                //    행마다 쿼리가 한 번 더 돈다(458행이면 poll 마다 900쿼리). 단일 출처는 그대로다.
                $v->gateBlockers = $b;

                return $b !== [] && array_diff($b, self::OVERRIDABLE) === [];
            })
            ->values();
    }

    /** 이 차량이 「사유만 쓰면 만들 수 있는」 상태인가 — 넘길 수 없는 사유가 하나라도 있으면 false. */
    public function isOverridable(Vehicle $vehicle): bool
    {
        $blockers = $vehicle->settlementBlockers();

        return $blockers !== [] && array_diff($blockers, self::OVERRIDABLE) === [];
    }

    /**
     * 예외로 정산을 만든다 — **자동 생성과 똑같은 본체**(`createSettlementNow`)를 쓴다.
     *
     * 🚫 수동 「신규 정산」 폼으로 대신하지 말 것 — 거긴 `attributed_month`·`is_domestic` 을
     *    안 채워서 귀속월이 완납월이 아니라 **생성월**이 된다(실사고 #5806 이 그 경로다).
     */
    public function createWithOverride(Vehicle $vehicle, User $actor, string $reason): Settlement
    {
        $this->assertActor($actor);
        $reason = $this->assertReason($reason);

        if (! $this->isOverridable($vehicle)) {
            throw new DomainException(
                '이 차량은 예외로 넘길 수 없는 사유가 있습니다 (담당자·판매가·중복 정산 등은 입력을 고쳐야 합니다).'
            );
        }

        $blockers = $vehicle->settlementBlockers();

        return DB::transaction(function () use ($vehicle, $actor, $reason, $blockers) {
            $settlement = $vehicle->createSettlementNow('예외 처리로 생성 — '.$reason);
            if (! $settlement) {
                throw new DomainException('담당자가 없어 정산을 만들 수 없습니다.');
            }
            $this->stamp($settlement, $actor, $reason, $blockers, $vehicle);
            $this->log($settlement, 'settlement_gate_overridden', $reason, $blockers);

            return $settlement;
        });
    }

    /** 이미 있는 정산(지급보류에 걸린 것)에 예외를 건다 — 부 통로. */
    public function applyToExisting(Settlement $settlement, User $actor, string $reason): void
    {
        $this->assertActor($actor);
        $reason = $this->assertReason($reason);

        if ($settlement->hasGateOverride()) {
            throw new DomainException('이미 예외 처리된 정산입니다.');
        }

        $vehicle = $settlement->vehicle;
        $blockers = $vehicle ? array_values(array_intersect($vehicle->settlementBlockers(), self::OVERRIDABLE)) : [];

        DB::transaction(function () use ($settlement, $actor, $reason, $blockers, $vehicle) {
            $this->stamp($settlement, $actor, $reason, $blockers, $vehicle);
            $this->log($settlement, 'settlement_gate_overridden', $reason, $blockers);
        });
    }

    /**
     * 예외 해제 — 5컬럼을 비운다. 재무 이상 아무나 할 수 있다(jin).
     *
     * ⚠️ **해제해도 이미 지급된 것은 되돌아오지 않는다.** 해제가 하는 일은 ①다음 배치부터 다시
     *    보류 ②회계 잠금 즉시 복귀 두 가지다. 화면이 그렇게 안내해야 한다.
     */
    public function release(Settlement $settlement, User $actor, string $reason = ''): void
    {
        $this->assertActor($actor);

        if (! $settlement->hasGateOverride()) {
            throw new DomainException('예외 처리된 정산이 아닙니다.');
        }

        $blockers = $settlement->gate_override_blockers ?? [];

        DB::transaction(function () use ($settlement, $reason, $blockers) {
            $settlement->forceFill([
                'gate_override_reason' => null,
                'gate_override_by' => null,
                'gate_override_at' => null,
                'gate_override_blockers' => null,
                'gate_override_unpaid_amount' => null,
            ])->save();

            $this->log($settlement, 'settlement_gate_override_released', $reason, $blockers);
        });
    }

    /**
     * 사유 **제안** 문구 — 미리 채워주는 용도로만 쓴다 (§3-8).
     *
     * 🚫 **자동 통과에 쓰지 말 것.** 「미수가 운임비와 같다」는 우연히 맞을 수 있다 —
     *    입금은 항목별로 귀속되지 않고 총액으로만 들어오기 때문이다. 사람이 읽고 고쳐 쓴다.
     */
    public function suggestReason(Vehicle $vehicle): string
    {
        $unpaid = (float) $vehicle->sale_unpaid_amount;
        if ($unpaid <= 0) {
            return '';
        }
        $cur = $vehicle->currency ?: 'KRW';
        $amount = $cur.' '.number_format($unpaid, 2);
        $freight = (float) ($vehicle->transport_fee ?? 0);

        if ($freight > 0 && abs($unpaid - $freight) < 0.01) {
            return "미수 {$amount} — 운임비와 같은 금액입니다. 운임비는 정산 기준액 밖이라 정산 숫자에 영향이 없습니다.";
        }

        $total = (float) $vehicle->sale_total_amount;
        if ($total > 0) {
            $pct = round($unpaid / $total * 100, 2);
            if ($pct < 1) {
                return "미수 {$amount} — 판매금의 {$pct}% 입니다.";
            }
        }

        return "미수 {$amount} — ";
    }

    private function stamp(Settlement $s, User $actor, string $reason, array $blockers, ?Vehicle $vehicle): void
    {
        $s->forceFill([
            'gate_override_reason' => $reason,
            'gate_override_by' => $actor->id,
            'gate_override_at' => now(),
            'gate_override_blockers' => array_values($blockers),
            // 그때의 미수(차량 통화 기준). **보여주기 전용** — 판정에 쓰지 않는다.
            'gate_override_unpaid_amount' => $vehicle ? (float) $vehicle->sale_unpaid_amount : null,
        ])->save();
    }

    private function log(Settlement $s, string $action, string $reason, array $blockers): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Settlement::class,
            'auditable_id' => $s->id,
            'action' => $action,
            'column_name' => 'gate_override_reason',
            'new_value' => ($blockers ? '['.implode(', ', $blockers).'] ' : '').$reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function assertActor(User $actor): void
    {
        if (! $actor->canConfirmFinance()) {
            throw new DomainException('정산 게이트 예외는 재무 이상만 처리할 수 있습니다.');
        }
    }

    private function assertReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new DomainException('예외 사유는 '.self::MIN_REASON_LENGTH.'자 이상 적어야 합니다.');
        }

        return $reason;
    }
}
