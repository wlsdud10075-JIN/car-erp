<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 💱 다른 회사에서 정산된 금액 — 이 회사 급여에 얹어 보낸다 (jin 2026-10-21).
 *
 * 예: 무사백 9월 = heymanerp 정산 2,800,000 + ssancarerp 정산 6,400,000 을 ssancar 가 한 번에 지급한다.
 * - 귀속월·사람·출처(source)별 한 행. 지금은 수기 입력, 나중에 heymanerp 연동이 같은 행을 채운다.
 * - 월정산 카드: **송금엔 들어가고 회사이익에선 뺀다**(마진은 그 회사 장부에 있다). 퇴직연금 1/12 기준엔 들어간다.
 */
class ExternalSettlementPay extends Model
{
    public const SOURCE_HEYMAN = 'heyman';

    protected $fillable = ['salesman_id', 'month', 'source', 'amount', 'note', 'updated_by'];

    protected $casts = ['amount' => 'integer'];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /** 그 달 사람별 합 — 한 쿼리로 여러 사람. @return array<int,int> */
    public static function totalsFor(array $salesmanIds, string $month): array
    {
        if ($salesmanIds === []) {
            return [];
        }

        return self::query()->where('month', $month)->whereIn('salesman_id', $salesmanIds)
            ->selectRaw('salesman_id, SUM(amount) as t')->groupBy('salesman_id')->pluck('t', 'salesman_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** 한 사람·한 달의 헤이맨 정산금 — 행 없으면 null(미입력). */
    public static function heymanFor(int $salesmanId, string $month): ?int
    {
        $v = self::query()->where('salesman_id', $salesmanId)->where('month', $month)->where('source', self::SOURCE_HEYMAN)->value('amount');

        return $v === null ? null : (int) $v;
    }

    /**
     * 헤이맨 정산금 저장 — null 이면 행을 지운다. 감사로그 + 결재 중 월정산 변경 이력(급여와 같은 경로).
     *
     * @return array{was:?int, now:?int}
     */
    public static function writeHeyman(Salesman $sm, string $month, ?int $amount, ?User $actor): array
    {
        $was = self::heymanFor($sm->id, $month);
        if ($amount === null) {
            self::query()->where('salesman_id', $sm->id)->where('month', $month)->where('source', self::SOURCE_HEYMAN)->delete();
        } else {
            self::updateOrCreate(
                ['salesman_id' => $sm->id, 'month' => $month, 'source' => self::SOURCE_HEYMAN],
                ['amount' => $amount, 'updated_by' => $actor?->id],
            );
        }
        if (AuditLog::valuesDiffer($was, $amount)) {
            AuditLog::recordChange($sm, 'heyman_pay_'.$month, $was, $amount);
            if ($actor) {
                SettlementPayoutBatch::monthly()->where('month', $month)->where('status', 'pending')->get()
                    ->each(fn ($b) => $b->recordChange($actor, $sm->id, SettlementPayoutBatchChange::FIELD_HEYMAN, $was, $amount, '헤이맨 정산금'));
            }
        }

        return ['was' => $was, 'now' => $amount];
    }
}
