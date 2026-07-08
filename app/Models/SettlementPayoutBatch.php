<?php

namespace App\Models;

use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\SettlementCkBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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

    /** 월배치 수동 조정 (jin 2026-07-08) — 담당자별 +/− 조정, 배치 총액에만 반영. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(SettlementPayoutAdjustment::class, 'batch_id');
    }

    /** 배치 총액 재계산 — 정산 실지급 합 + 조정 합(음수 포함). 조정 변경 시 호출. */
    public function recomputeTotal(): void
    {
        $settleSum = (int) $this->settlements()->get()->sum(fn ($s) => $s->actual_payout);
        $adjSum = (int) $this->adjustments()->sum('amount');
        $this->total_payout = max(0, $settleSum + $adjSum);
        $this->save();
    }

    /** 조정 추가 — pending 배치 + 관리 권한. 사유 필수. 총액 재계산 + 감사로그. */
    public function addAdjustment(User $by, int $salesmanId, int $amount, string $reason): SettlementPayoutAdjustment
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('승인 대기 중인 배치에만 조정을 추가할 수 있습니다.');
        }
        if (! $by->canSubmitPayoutBatch()) {
            throw new \DomainException('조정 입력 권한이 없습니다.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('조정 사유는 필수입니다.');
        }
        if ($amount === 0) {
            throw new \DomainException('조정 금액은 0이 될 수 없습니다.');
        }

        return DB::transaction(function () use ($by, $salesmanId, $amount, $reason) {
            $adj = $this->adjustments()->create([
                'salesman_id' => $salesmanId,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $by->id,
            ]);
            $this->recomputeTotal();
            AuditLog::create([
                'user_id' => $by->id, 'approval_request_id' => null,
                'auditable_type' => self::class, 'auditable_id' => $this->id,
                'action' => 'payout_adjustment_added', 'column_name' => 'amount',
                'old_value' => null, 'new_value' => $amount.' ('.$reason.')',
                'ip_address' => request()?->ip(),
            ]);

            return $adj;
        });
    }

    /** 조정 삭제 — pending 배치 + 관리 권한. 총액 재계산 + 감사로그. */
    public function removeAdjustment(User $by, int $adjustmentId): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('승인 대기 중인 배치에서만 조정을 삭제할 수 있습니다.');
        }
        if (! $by->canSubmitPayoutBatch()) {
            throw new \DomainException('조정 삭제 권한이 없습니다.');
        }
        $adj = $this->adjustments()->find($adjustmentId);
        if (! $adj) {
            return;
        }

        DB::transaction(function () use ($by, $adj) {
            $amount = $adj->amount;
            $reason = $adj->reason;
            $adj->delete();
            $this->recomputeTotal();
            AuditLog::create([
                'user_id' => $by->id, 'approval_request_id' => null,
                'auditable_type' => self::class, 'auditable_id' => $this->id,
                'action' => 'payout_adjustment_removed', 'column_name' => 'amount',
                'old_value' => $amount.' ('.$reason.')', 'new_value' => null,
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    /** 제출 — 해당 귀속월(confirmed_at+10일 앵커)의 confirmed & 미배치 정산을 묶어 배치 생성. */
    public static function submitForMonth(User $submitter, string $month): self
    {
        if (! $submitter->canSubmitPayoutBatch()) {
            throw new \DomainException('월배치 제출 권한이 없습니다.');
        }
        // 월당 진행중(pending) 배치 1개 — 동시 제출로 정산이 재지목돼 phantom 배치가 되는 것 방지.
        if (self::where('month', $month)->where('status', self::STATUS_PENDING)->exists()) {
            throw new \DomainException('해당 월에 이미 승인 대기 중인 배치가 있습니다.');
        }
        // A-3 (2026-07-08) — 귀속월 앵커 = attributed_month(완납월, 달력 1일~말일). NULL(백필 전/누락)은 기존 앵커 fallback.
        [$start, $end] = SettlementCkBatch::monthRange($month);
        $monthStart = $month.'-01';

        $ids = Settlement::query()
            ->where('settlement_status', 'confirmed')
            ->whereNull('payout_batch_id')
            ->where(function ($q) use ($monthStart, $start, $end) {
                $q->whereDate('attributed_month', $monthStart)
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('attributed_month')
                            ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$start])
                            ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$end]);
                    });
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            throw new \DomainException('해당 월에 제출할 확정 정산이 없습니다.');
        }

        // 지급 게이트 (jin 2026-07-08) — 미수 있는 차량의 정산은 배치에서 제외(지급보류).
        //   근거: 받을 돈(미수)을 다 못 받았는데 영업 정산을 지급하면 회사 리스크 + 수금 동기 약화.
        //   완납 기준 A-3로 생성돼도, 운임비 후입력 등으로 완납 후 미수가 재발하면 지급 시점에 재차단.
        //   비파괴적 — 정산은 유지(귀속월·스냅샷 보존), 지급만 보류. 완납되면 다음 배치에 자동 재진입.
        $ids = Settlement::whereIn('id', $ids)->with('vehicle')->get()
            ->reject(fn ($s) => (int) ($s->vehicle?->sale_unpaid_amount ?? 0) > 0)
            ->pluck('id');
        if ($ids->isEmpty()) {
            throw new \DomainException('지급 가능한 정산이 없습니다 (해당 월 확정 정산이 전부 미수 있는 차량이라 지급보류됨. 완납 후 지급).');
        }

        $rank = $submitter->approvalRank();

        $batch = DB::transaction(function () use ($submitter, $month, $rank, $ids) {
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

        // 커밋 후 fire-and-forget — 첫 승인 계단에게 '승인 요청 도착' 알림톡.
        $batch->notifyPayoutRequest();

        return $batch;
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

        $becameFinal = false;
        DB::transaction(function () use ($u, $note, &$becameFinal) {
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
                $becameFinal = true;
            } else {
                $this->current_level++;
                $this->save();
            }
        });

        // 커밋 후 fire-and-forget 알림톡 — 최종 승인=제출자에게 완료, 전진=다음 계단에게 요청.
        if ($becameFinal) {
            $this->sendPayoutAlimtalk('erp_payout_done', $this->submitterPhones(), [
                '귀속월' => $this->month,
                '건수' => (string) $this->settlement_count,
                '총액' => number_format($this->total_payout).'원',
            ]);
        } else {
            $this->notifyPayoutRequest();
        }
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

        // 커밋 후 fire-and-forget — 제출자에게 반려 통보(사유 포함).
        $this->sendPayoutAlimtalk('erp_payout_rejected', $this->submitterPhones(), [
            '귀속월' => $this->month,
            '건수' => (string) $this->settlement_count,
            '사유' => $reason,
        ]);
    }

    /**
     * 현재 계단(current_level) 승인자에게 '승인 요청 도착' 알림톡 — 승인자별 서명 링크 버튼 포함.
     * 버튼 = 그 승인자·이 배치로 바인딩된 만료 서명 URL(5일). 카톡에서 바로 승인/반려 페이지로.
     */
    public function notifyPayoutRequest(): void
    {
        $svc = BizmAlimtalkService::active();
        $vars = [
            '귀속월' => $this->month,
            '건수' => (string) $this->settlement_count,
            '총액' => number_format($this->total_payout).'원',
            '제출자' => $this->submitter?->name ?? '-',
        ];
        foreach (AlimtalkRecipients::payoutApproverUsers($this->current_level) as $user) {
            $url = $this->approvalLinkFor($user);
            $svc->send('erp_payout_request', (string) $user->phone, $vars, ['user_id' => $user->id], [
                ['name' => '승인/반려 바로가기', 'type' => 'WL', 'url_mobile' => $url, 'url_pc' => $url],
            ]);
        }
    }

    /** 이 배치 × 승인자에 바인딩된 만료 서명 승인 링크(5일). 카톡 버튼 URL 로 주입. */
    public function approvalLinkFor(User $user): string
    {
        return URL::temporarySignedRoute('payout.approve.show', now()->addDays(5), [
            'batch' => $this->id,
            'u' => $user->id,
        ]);
    }

    /** 제출자 전화번호(있으면 1건). */
    private function submitterPhones(): array
    {
        $phone = trim((string) ($this->submitter?->phone ?? ''));

        return $phone !== '' ? [$phone] : [];
    }

    /** 알림톡 발송 — fire-and-forget(BizmAlimtalkService 가 예외 흡수·게이트 off 시 skipped). */
    private function sendPayoutAlimtalk(string $code, array $phones, array $vars): void
    {
        if (empty($phones)) {
            return;
        }
        $svc = BizmAlimtalkService::active();
        foreach ($phones as $phone) {
            $svc->send($code, $phone, $vars);
        }
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
