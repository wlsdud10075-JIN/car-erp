<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * ⏸ 지급보류 · ➕ 지급추가 장부 한 줄 (jin 2026-11-06) — 대표까지 승인된 사내직원 월정산에서 한 사람에 대해
 *   kind=hold  : 그 달 지급합계(+/−)의 전부·일부를 **송금에서 빼** 둔다(회사이익 무관 — 원래 달에 이미 반영).
 *   kind=extra : 대표가 따로 정해 기안자가 기입하는 **추가 지급**(+/−). 지급되는 달의 회사이익에서 차감된다.
 *
 * 열린 줄(held·queued)의 합 = 사내직원관리 이름 옆 「보류금」·「추가금」. 처리 = 「지급」(월정산 밖, released_paid) 또는
 * 「월정산 추가」(queued → 다음 월정산 제출 때 held_release / extra_release 조정 줄, released_batch). 반려되면 queued 로 돌아간다.
 * 🔑 금액은 기입 때 박제 — 다시 계산하지 않는다. 사유(note)는 둘 다 필수(jin 11-06 «무엇 때문에 보류하는지»).
 */
class PayoutHold extends Model
{
    public const KIND_HOLD = 'hold';

    public const KIND_EXTRA = 'extra';

    public const KINDS = [self::KIND_HOLD, self::KIND_EXTRA];

    public const HELD = 'held';

    public const QUEUED = 'queued';

    public const RELEASED_BATCH = 'released_batch';

    public const RELEASED_PAID = 'released_paid';

    public const CANCELLED = 'cancelled';

    /** 아직 돈이 안 나간 상태 — 보류금·추가금 잔액에 들어간다. */
    public const OPEN = [self::HELD, self::QUEUED];

    protected $fillable = [
        'salesman_id', 'payout_batch_id', 'kind', 'month', 'amount_krw', 'status',
        'released_batch_id', 'held_by', 'released_by', 'released_at', 'note',
    ];

    protected $casts = ['amount_krw' => 'integer', 'released_at' => 'datetime'];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'payout_batch_id');
    }

    public function releasedBatch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'released_batch_id');
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function scopeOfKind($q, string $kind)
    {
        return $q->where('kind', $kind);
    }

    public function isExtra(): bool
    {
        return $this->kind === self::KIND_EXTRA;
    }

    /** 사람별 열린 금액 합(종류별) — 사내직원관리 뱃지. */
    public static function openBalanceFor(int $salesmanId, string $kind = self::KIND_HOLD): int
    {
        return (int) self::query()->open()->ofKind($kind)->where('salesman_id', $salesmanId)->sum('amount_krw');
    }

    /**
     * 전원의 열린 금액 — `[salesman_id => ['hold' => int, 'extra' => int]]`. 뱃지용 한 쿼리.
     *
     * @return array<int, array{hold:int, extra:int}>
     */
    public static function openBalances(): array
    {
        $out = [];
        foreach (self::query()->open()->selectRaw('salesman_id, kind, SUM(amount_krw) t')->groupBy('salesman_id', 'kind')->get() as $r) {
            $out[(int) $r->salesman_id] ??= ['hold' => 0, 'extra' => 0];
            $out[(int) $r->salesman_id][$r->kind] = (int) $r->t;
        }

        return $out;
    }

    /**
     * 사내직원관리에서 보류금·추가금 처리 (jin 2026-11-06) — 권한 = 월정산 제출자 + 최고관리자(SettlementPayoutBatch::canEditAdjustments).
     *   paid    = 월정산 밖에서 지급함 → released_paid(잔액에서 빠짐)
     *   queue   = 다음 월정산에 추가 → queued(제출 때 「2026-10월 지급보류분/지급추가분」 줄)
     *   unqueue = 추가 취소 → held
     * `$amount` = 처리할 금액(null·전액이면 전부). 전액이면 줄을 전부 옮긴다(부호 섞인 사람을 금액으로 걷으면 줄이 남는다).
     * 일부면 오래된 달부터 채우고 마지막 줄을 **쪼갠다**(남는 몫은 원래 상태로 남는 새 줄) — jin «150만 지급하고 180만 보류하고,
     * 이월된 보류금에서도 나눠서». 부호는 잔액과 같아야 하고 잔액을 넘을 수 없다. 종류(hold/extra)는 따로 처리한다.
     *
     * @return int 옮긴 금액
     */
    public static function resolve(User $by, int $salesmanId, string $action, ?int $amount = null, string $kind = self::KIND_HOLD): int
    {
        if (! SettlementPayoutBatch::canEditAdjustments($by)) {
            throw new \DomainException(__('payout_batch.hold.forbidden'));
        }
        // 감사 액션은 글자 그대로 둔다 — 정적 검사(PaymentRowAuditTest)가 이어붙인 이름을 못 읽는다(§8 #41).
        [$from, $to, $auditAction] = match ($kind.':'.$action) {
            'hold:paid' => [self::OPEN, self::RELEASED_PAID, 'payout_hold_paid'],
            'hold:queue' => [[self::HELD], self::QUEUED, 'payout_hold_queue'],
            'hold:unqueue' => [[self::QUEUED], self::HELD, 'payout_hold_unqueue'],
            'extra:paid' => [self::OPEN, self::RELEASED_PAID, 'payout_extra_paid'],
            'extra:queue' => [[self::HELD], self::QUEUED, 'payout_extra_queue'],
            'extra:unqueue' => [[self::QUEUED], self::HELD, 'payout_extra_unqueue'],
            default => throw new \DomainException('알 수 없는 처리입니다.'),
        };
        $rows = self::query()->where('salesman_id', $salesmanId)->ofKind($kind)->whereIn('status', $from)->orderBy('month')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            throw new \DomainException(__('payout_batch.hold.nothing'));
        }
        $total = (int) $rows->sum('amount_krw');
        $amount ??= $total;
        if ($amount === 0 || ($amount <=> 0) !== ($total <=> 0) || abs($amount) > abs($total)) {
            throw new \DomainException(__('payout_batch.hold.amount_invalid', ['max' => number_format($total)]));
        }

        return DB::transaction(function () use ($by, $salesmanId, $rows, $to, $auditAction, $amount, $total) {
            $moveAttrs = $to === self::RELEASED_PAID
                ? ['status' => $to, 'released_by' => $by->id, 'released_at' => now()]
                : ['status' => $to];
            // 전액이면 줄을 전부 옮긴다 — 부호가 섞인 사람(10월 +, 11월 −)을 금액으로 걷으면 합이 맞는 지점에서 멈춰 줄이 남는다.
            if ($amount === $total) {
                foreach ($rows as $row) {
                    $row->update($moveAttrs);
                }
                self::audit($by, $salesmanId, $auditAction, $rows, $to, $amount, $total);

                return $amount;
            }
            $remaining = $amount;
            $moved = [];
            foreach ($rows as $row) {
                if ($remaining === 0) {
                    break;
                }
                if (abs((int) $row->amount_krw) <= abs($remaining)) {   // 줄 통째
                    $row->update($moveAttrs);
                    $remaining -= (int) $row->amount_krw;
                    $moved[] = $row;

                    continue;
                }
                // 줄 쪼개기 — 옮기는 몫은 새 줄(같은 배치·달·종류), 남는 몫은 이 줄에 그대로(원래 상태)
                $row->update(['amount_krw' => (int) $row->amount_krw - $remaining]);
                $moved[] = self::create([
                    'salesman_id' => $row->salesman_id, 'payout_batch_id' => $row->payout_batch_id, 'kind' => $row->kind, 'month' => $row->month,
                    'amount_krw' => $remaining, 'held_by' => $row->held_by, 'note' => $row->note,
                ] + $moveAttrs);
                $remaining = 0;
            }
            self::audit($by, $salesmanId, $auditAction, $moved, $to, $amount, $total);

            return $amount;
        });
    }

    private static function audit(User $by, int $salesmanId, string $action, iterable $moved, string $to, int $amount, int $total): void
    {
        AuditLog::create([
            'user_id' => $by->id, 'auditable_type' => Salesman::class, 'auditable_id' => $salesmanId,
            'action' => $action, 'column_name' => 'payout_hold',
            'old_value' => self::reasonFor($moved).' '.number_format($amount).'원'.($amount !== $total ? ' (잔액 '.number_format($total).'원 중)' : ''),
            'new_value' => $to, 'ip_address' => request()?->ip(),
        ]);
    }

    /** 화면 금액칸 → 정수(빈칸·숫자 외 문자 제거, 맨 앞 − 유지). 빈칸이면 null(= 전액). */
    public static function parseAmount(?string $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $raw);

        return $digits === '' ? null : (int) ((str_starts_with($raw, '-') || str_starts_with($raw, '−') ? '-' : '').$digits);
    }

    /** 사유 정리 — 비면 예외(보류·추가 둘 다 필수, jin 11-06). 200자 자름. */
    public static function requireNote(?string $note): string
    {
        $note = trim((string) $note);
        if ($note === '') {
            throw new \DomainException(__('payout_batch.hold.note_required'));
        }

        return mb_substr($note, 0, 200);
    }

    /** 「2026-10월·2026-11월 지급보류분」 / 「… 지급추가분」 — 월정산 추가 줄의 사유. 종류는 첫 줄로 본다. */
    public static function reasonFor(iterable $holds): string
    {
        $rows = collect($holds);
        $months = $rows->pluck('month')->unique()->sort()->values()->all();
        $key = ($rows->first()?->kind ?? self::KIND_HOLD) === self::KIND_EXTRA ? 'payout_batch.hold.extra_reason' : 'payout_batch.hold.release_reason';

        return __($key, ['months' => implode('·', array_map(fn ($m) => $m.'월', $months))]);
    }
}
