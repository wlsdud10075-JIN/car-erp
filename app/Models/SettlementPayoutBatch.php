<?php

namespace App\Models;

use App\Support\SettlementCkBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (jin 2026-07-07) — 월배치 정산지급 승인 사다리.
 *
 * [관리](rank1)/업무관리자(rank2) 제출 → 제출자보다 위 계단이 순서대로 서명(current_level 정확 일치) →
 * 대표(admin, rank3=TOP) 최종 승인 시 배치 전 confirmed 정산 일괄 paid(상태만). super(4)=override 즉시 완료.
 * 정산 지급(1차)만 대상. 2차+환차는 carryover 이월(별개).
 */
class SettlementPayoutBatch extends Model
{
    public const TOP_RANK = 3;   // 대표(admin) — 고객사 사다리 최상단

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'month', 'submitter_id', 'submitter_rank', 'current_level', 'status',
        'total_payout', 'settlement_count', 'submitted_at', 'decided_at', 'reject_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SettlementPayoutApproval::class, 'batch_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'payout_batch_id');
    }

    /** 제출 — 해당 귀속월(confirmed_at+10일 앵커)의 confirmed & 미배치 정산을 묶어 배치 생성. */
    public static function submitForMonth(User $submitter, string $month): self
    {
        if (! $submitter->canSubmitPayoutBatch()) {
            throw new \DomainException('월배치 제출 권한이 없습니다.');
        }
        [$start, $end] = SettlementCkBatch::monthRange($month);

        $ids = Settlement::query()
            ->where('settlement_status', 'confirmed')
            ->whereNull('payout_batch_id')
            ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$start])
            ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$end])
            ->pluck('id');

        if ($ids->isEmpty()) {
            throw new \DomainException('해당 월에 제출할 확정 정산이 없습니다.');
        }

        $rank = $submitter->approvalRank();

        return DB::transaction(function () use ($submitter, $month, $rank, $ids) {
            $settlements = Settlement::whereIn('id', $ids)->get();
            $batch = self::create([
                'month' => $month,
                'submitter_id' => $submitter->id,
                'submitter_rank' => $rank,
                'current_level' => $rank + 1,
                'status' => self::STATUS_PENDING,
                'total_payout' => (int) $settlements->sum(fn ($s) => $s->actual_payout),
                'settlement_count' => $settlements->count(),
                'submitted_at' => now(),
            ]);
            Settlement::whereIn('id', $ids)->update(['payout_batch_id' => $batch->id]);

            return $batch;
        });
    }

    /** 현재 단계에서 이 사용자가 승인/반려할 수 있나 — rank 정확 일치 또는 super override. */
    public function canDecide(User $u): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($u->isSuperAdmin() || $u->approvalRank() === $this->current_level);
    }

    public function approveBy(User $u, ?string $note = null): void
    {
        if (! $this->canDecide($u)) {
            throw new \DomainException('이 배치의 현재 승인 단계 권한이 없습니다.');
        }

        DB::transaction(function () use ($u, $note) {
            $this->approvals()->create([
                'approver_id' => $u->id, 'approver_rank' => $u->approvalRank(),
                'action' => 'approved', 'note' => $note ?: null, 'created_at' => now(),
            ]);

            // 대표(TOP) 서명 또는 super override → 완료 + 일괄 paid. 아니면 다음 계단으로.
            if ($u->isSuperAdmin() || $this->current_level >= self::TOP_RANK) {
                $this->status = self::STATUS_APPROVED;
                $this->decided_at = now();
                $this->save();
                $this->execute();
            } else {
                $this->current_level++;
                $this->save();
            }
        });
    }

    public function rejectBy(User $u, string $reason): void
    {
        if (! $this->canDecide($u)) {
            throw new \DomainException('이 배치의 현재 승인 단계 권한이 없습니다.');
        }

        DB::transaction(function () use ($u, $reason) {
            $this->approvals()->create([
                'approver_id' => $u->id, 'approver_rank' => $u->approvalRank(),
                'action' => 'rejected', 'note' => $reason, 'created_at' => now(),
            ]);
            $this->status = self::STATUS_REJECTED;
            $this->decided_at = now();
            $this->reject_reason = $reason;
            $this->save();

            // 멤버 정산 배치 해제 → 재배치 가능 (settlement_status=confirmed 유지)
            $this->settlements()->update(['payout_batch_id' => null]);
        });
    }

    /** 대표 최종 승인 시 — 배치 전 confirmed 정산을 paid 일괄 전환(상태만, 실제 이체는 별건). */
    private function execute(): void
    {
        Settlement::$allowBatchPayout = true;
        try {
            foreach ($this->settlements()->where('settlement_status', 'confirmed')->get() as $s) {
                $s->settlement_status = 'paid';
                $s->paid_at = now();
                $s->save();   // Settlement::saving 훅: secondary_status='pending' + confirmed_snapshot 자동
            }
        } finally {
            Settlement::$allowBatchPayout = false;
        }
    }
}
