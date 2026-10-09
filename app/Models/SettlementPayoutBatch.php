<?php

namespace App\Models;

use App\Services\BizmAlimtalkService;
use App\Services\Payout\BatchPayoutBreakdown;
use App\Support\AlimtalkRecipients;
use App\Support\SettlementCkBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        'month', 'submitter_id', 'submitter_rank', 'current_level', 'current_step', 'status',
        'breakdown_snapshot',
        'total_payout', 'settlement_count', 'submitted_at', 'decided_at', 'reject_reason',
        // 2026-10-07 jin — 승인요청 마지막 발송 시각(재전송 연타 방지 · 발송 결과 표시). 마이그레이션 주석 참조.
        'request_notified_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'request_notified_at' => 'datetime',
        'breakdown_snapshot' => 'array',
    ];

    /**
     * 해당 월(Y-m)이 마감됐나 — 승인(지급)된 배치가 1건이라도 있으면 닫힘 (jin 2026-07-18).
     * "6월 마감되면 그 순간 끝. 늦게 완성된 건은 완성된 달(현재 열린 달)에 포함" 규칙의 기준.
     * pending/rejected/cancelled 배치는 마감 아님 (approved = execute()로 실지급 상태만).
     */
    public static function isMonthClosed(string $ym): bool
    {
        return self::where('month', $ym)->where('status', self::STATUS_APPROVED)->exists();
    }

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

    // ── 월정산 v3 결재선 (jin 2026-10-08) ──────────────────────────────────────
    //   행이 있으면 **steps 모드**(상신 때 고른 부장 → 전무 → 대표, 건너뛴 직급은 행 없음), 없으면 종전 사다리(current_level).
    //   모드는 **배치 단위**다 — 직급을 아무도 안 넣은 회사(또는 배포 전에 올라간 배치)는 행이 0개라 종전과 똑같이 돈다.

    public function steps(): HasMany
    {
        return $this->hasMany(SettlementPayoutBatchStep::class, 'batch_id')->orderBy('seq');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SettlementPayoutBatchChange::class, 'batch_id')->orderBy('id');
    }

    public function isStepsMode(): bool
    {
        return $this->relationLoaded('steps') ? $this->steps->isNotEmpty() : $this->steps()->exists();
    }

    /** 지금 결재 차례인 칸(steps 모드). */
    public function currentStep(): ?SettlementPayoutBatchStep
    {
        if ($this->current_step === null) {
            return null;
        }
        $steps = $this->relationLoaded('steps') ? $this->steps : $this->steps()->get();

        return $steps->firstWhere('seq', (int) $this->current_step);
    }

    /**
     * 지금 결재할 사람들 — steps 모드 = 그 칸의 한 사람 / 종전 = 그 계단 전원(permission). 알림톡 수신자·화면 라벨이 이것만 본다.
     *
     * @return Collection<int, User>
     */
    public function currentApprovers(): Collection
    {
        if ($this->isStepsMode()) {
            $u = $this->currentStep()?->approver;

            return $u ? collect([$u]) : collect();
        }

        return AlimtalkRecipients::payoutApproverUsers((int) $this->current_level);
    }

    /** 화면 라벨 — steps 모드 「부장 박부장」 / 종전은 호출부가 levelLabel(current_level). */
    public function currentStepLabel(): ?string
    {
        $step = $this->currentStep();

        return $step ? $step->title.' '.($step->approver?->name ?? '-') : null;
    }

    /** 승인 대기 중이고 이 사람 차례인 배치 — 사이드바 뱃지·목록이 같은 판정을 쓴다. */
    public function scopeAwaitingDecisionBy($q, User $u)
    {
        $q->where('status', self::STATUS_PENDING);
        if ($u->isSuperAdmin()) {
            return $q;
        }

        return $q->where(function ($w) use ($u) {
            $w->where(fn ($legacy) => $legacy->whereDoesntHave('steps')->where('current_level', $u->approvalRank()))
                ->orWhereHas('steps', fn ($st) => $st->whereColumn('seq', 'settlement_payout_batches.current_step')
                    ->where('approver_user_id', $u->id)->where('status', 'pending'));
        });
    }

    /**
     * 결재선 선택지 — 직급(부장/전무/대표)별 최고관리자. 비어 있으면 그 회사는 종전 사다리로만 돈다.
     *
     * @return array<string, Collection<int, User>> title => users
     */
    public static function approvalLineOptions(): array
    {
        $users = User::query()->where('permission', 'admin')->whereIn('approval_title', User::APPROVAL_TITLES)->orderBy('name')->get();
        $out = [];
        foreach (User::APPROVAL_TITLES as $title) {
            $picked = $users->where('approval_title', $title)->values();
            if ($picked->isNotEmpty()) {
                $out[$title] = $picked;
            }
        }

        return $out;
    }

    /** 지난번 steps 모드 배치의 결재선(title => user_id) — 제출 모달 기본값. */
    public static function lastStepsLine(): array
    {
        $last = self::query()->whereHas('steps')->orderByDesc('id')->with('steps')->first();
        if (! $last) {
            return [];
        }

        return $last->steps->mapWithKeys(fn ($st) => [$st->title => (int) $st->approver_user_id])->all();
    }

    /** 상신 뒤 바뀐 칸 — salesman_id => [field, …]. 카드가 노란 표시에 쓴다. */
    public function changedFieldsBySalesman(): array
    {
        $changes = $this->relationLoaded('changes') ? $this->changes : $this->changes()->get();

        return $changes->whereNotNull('salesman_id')->groupBy('salesman_id')
            ->map(fn ($g) => $g->pluck('field')->unique()->values()->all())->all();
    }

    public function recordChange(User $by, ?int $salesmanId, string $field, ?int $before, ?int $after, ?string $note = null): SettlementPayoutBatchChange
    {
        return $this->changes()->create([
            'user_id' => $by->id, 'salesman_id' => $salesmanId, 'field' => $field,
            'before' => $before, 'after' => $after, 'note' => $note !== null ? mb_substr($note, 0, 200) : null, 'created_at' => now(),
        ]);
    }

    /** 조정·인센티브를 손댈 수 있는 사람 — 제출 권한자 + 최고관리자(결재자 전원, jin «기존 최고관리자와 동일 권한 + 금액 고칠 수 있게»). */
    public static function canEditAdjustments(User $u): bool
    {
        return $u->canSubmitPayoutBatch() || $u->isAdmin();
    }

    /**
     * 결재 중 추가 인센티브(사람당 N건). 결재는 **멈춘 단계부터 이어서** — step 포인터를 건드리지 않고 변경 이력만 남긴다.
     * ⚠️ 이미 나간 알림톡의 「총액」은 그 시점 값이다(재발송 안 함 — 카드가 노란 표시로 알린다).
     */
    public function addIncentive(User $by, int $salesmanId, int $amount, string $reason): SettlementPayoutAdjustment
    {
        $before = (int) $this->adjustments()->where('salesman_id', $salesmanId)->where('kind', SettlementPayoutAdjustment::KIND_INCENTIVE)->sum('amount');
        $adj = $this->addAdjustment($by, $salesmanId, $amount, $reason, null, SettlementPayoutAdjustment::KIND_INCENTIVE);
        $this->recordChange($by, $salesmanId, SettlementPayoutBatchChange::FIELD_INCENTIVE, $before, $before + $amount, $reason);

        return $adj;
    }

    public function removeIncentive(User $by, int $adjustmentId): void
    {
        $adj = $this->adjustments()->find($adjustmentId);
        if (! $adj) {
            return;
        }
        $before = (int) $this->adjustments()->where('salesman_id', $adj->salesman_id)->where('kind', $adj->kind)->sum('amount');
        $this->removeAdjustment($by, $adjustmentId);
        $field = $adj->kind === SettlementPayoutAdjustment::KIND_INCENTIVE ? SettlementPayoutBatchChange::FIELD_INCENTIVE : SettlementPayoutBatchChange::FIELD_ADJUSTMENT;
        $this->recordChange($by, (int) $adj->salesman_id, $field, $before, $before - (int) $adj->amount, '삭제: '.$adj->reason);
    }

    /** 카드 데이터 — 끝난 배치(승인·반려)는 박제, 진행 중은 지금 값. 반려돼도 내용이 그대로 보인다(jin). */
    public function breakdownForDisplay(): array
    {
        if ($this->status !== self::STATUS_PENDING && is_array($this->breakdown_snapshot) && isset($this->breakdown_snapshot['people'])) {
            return $this->breakdown_snapshot;
        }

        return BatchPayoutBreakdown::forBatch($this);
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

    /**
     * 조정 추가 — pending 배치 + 관리 권한. 사유 필수. 총액 재계산 + 감사로그.
     *
     * 2026-08-06 (jin) — 입력 경로는 **정산관리 제출 모달 하나**다(월배치 화면의 조정 UI 제거).
     * `$cancelVehicleIds` 가 있으면 매입취소 손실 차감이라는 뜻이고, 배치 최종 승인 시
     * 그 차량들의 `cancel_loss_settled_at` 이 자동으로 찍힌다.
     *
     * @param  array<int, int>|null  $cancelVehicleIds
     */
    public function addAdjustment(User $by, int $salesmanId, int $amount, string $reason, ?array $cancelVehicleIds = null, ?string $kind = null): SettlementPayoutAdjustment
    {
        // 종류(v3) — 손실 차감은 cancel_vehicle_ids 로 자명하므로 생략 가능. 그 외 기본 manual.
        $kind ??= $cancelVehicleIds ? SettlementPayoutAdjustment::KIND_LOSS : SettlementPayoutAdjustment::KIND_MANUAL;
        if (! in_array($kind, SettlementPayoutAdjustment::KINDS, true)) {
            throw new \DomainException('알 수 없는 조정 종류입니다.');
        }
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('승인 대기 중인 배치에만 조정을 추가할 수 있습니다.');
        }
        if (! self::canEditAdjustments($by)) {
            throw new \DomainException('조정 입력 권한이 없습니다.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('조정 사유는 필수입니다.');
        }
        if ($amount === 0) {
            throw new \DomainException('조정 금액은 0이 될 수 없습니다.');
        }

        return DB::transaction(function () use ($by, $salesmanId, $amount, $reason, $cancelVehicleIds, $kind) {
            $adj = $this->adjustments()->create([
                'salesman_id' => $salesmanId,
                'amount' => $amount,
                'kind' => $kind,
                'reason' => $reason,
                'cancel_vehicle_ids' => $cancelVehicleIds ?: null,
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
        if (! self::canEditAdjustments($by)) {
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

    /**
     * 그 달에 배치로 나갈 수 있는 확정 정산 id — **제출 미리보기와 실제 제출의 단일 출처**.
     *
     * 정산관리 제출 모달이 "무엇이 얼마나 나가나"를 보여주고, submitForMonth 가 같은 목록으로
     * 배치를 만든다. 두 곳이 각자 조건을 들고 있으면 미리보기와 실제가 조용히 갈린다.
     *
     * @return Collection<int, int>
     */
    public static function eligibleSettlementIds(string $month): Collection
    {
        // A-3 (2026-07-08) — 귀속월 앵커 = attributed_month(완납월, 달력 1일~말일). NULL(백필 전/누락)은 기존 앵커 fallback.
        [$start, $end] = SettlementCkBatch::monthRange($month);
        $monthStart = $month.'-01';

        $ids = Settlement::query()
            ->where('settlement_status', 'confirmed')
            ->whereNull('payout_batch_id')
            ->where(function ($q) use ($monthStart, $start, $end) {
                // 성능(jin 2026-07-23): attributed_month 인덱스 유지 위해 whereDate→시간경계 범위(DB tier 불일치 대응).
                $q->whereBetween('attributed_month', [$monthStart.' 00:00:00', $monthStart.' 23:59:59'])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('attributed_month')
                            ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$start])
                            ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$end]);
                    });
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return $ids;
        }

        // 지급 게이트 (jin 2026-07-08) — 미수 있는 차량의 정산은 배치에서 제외(지급보류).
        //   근거: 받을 돈(미수)을 다 못 받았는데 영업 정산을 지급하면 회사 리스크 + 수금 동기 약화.
        //   완납 기준 A-3로 생성돼도, 운임비 후입력 등으로 완납 후 미수가 재발하면 지급 시점에 재차단.
        //   비파괴적 — 정산은 유지(귀속월·스냅샷 보존), 지급만 보류. 완납되면 다음 배치에 자동 재진입.
        //   🚪 예외(게이트 오버라이드)가 걸린 정산은 통과 — 판정은 `isPayoutHeldByUnpaid()` 단일 출처
        //      (조건을 여기 옮겨 적으면 「뱃지는 없는데 배치에서 빠지는」 형태가 된다 — §8 #44).
        // 🚪 지급 대상이 아닌 담당자(자매 회사 계정 등)의 정산은 배치에 안 넣는다 (jin 2026-09-16).
        //    판정은 `isPayoutExcludedBySalesman()` 단일 출처 — 조건을 여기 옮겨 적으면
        //    「목록엔 빠졌다고 뜨는데 배치엔 들어가는」 형태가 된다(§8 #44).
        return Settlement::whereIn('id', $ids)->with(['vehicle', 'salesman'])->get()
            ->reject(fn (Settlement $s) => $s->isPayoutExcludedBySalesman())
            ->reject(fn (Settlement $s) => $s->isPayoutHeldByUnpaid())
            ->pluck('id');
    }

    /**
     * 월배치 제출 — 배치 + 조정을 **한 트랜잭션**으로 만들고, 그 합계로 알림톡을 보낸다.
     *
     * 🔀 2026-08-06 (jin) — 조정을 제출 시점으로 앞당겼다.
     *   구: 제출 → 카톡 발송 → 그제서야 월배치 화면에서 조정. 조정은 pending 동안만 가능한데
     *       카톡은 이미 나간 뒤라 **승인자가 본 총액과 실제 지급액이 어긋났다**. 승인자가 바로
     *       승인해버리면 조정 기회 자체가 사라졌고, 매입취소 손실은 사람이 기억해서 넣어야 했다.
     *   신: 정산관리의 제출 확인 모달에서 차감을 확정하고 넘긴다 → 카톡 총액이 정확하다.
     *
     * @param  array<int, array{salesman_id:int, amount:int, reason:string, cancel_vehicle_ids?:array<int,int>|null}>  $adjustments
     */
    /**
     * 💸 **미청산 이월 → 이번 배치의 자동 조정 줄** (jin 2026-10-06 「너 추천으로 하자」).
     *
     * 담당자별 `Salesman::unconsumed_carryover`(= Σ마감 이월 − Σ흡수 − Σ청산)를 조정 한 줄로 가져온다.
     * 제출 모달 미리보기와 `submitForMonth` 가 **같은 이 함수**를 부른다 — 갈리면 「모달엔 있는데 배치엔 없다」(§8 #44).
     *
     * - **+ 이월(담당자가 받을 돈)은 전액** — 이번 배치에 그 담당자 정산이 없어도 조정만 있는 행으로 들어간다.
     * - **− 이월(회사가 돌려받을 돈)은 그 담당자의 이번 배치 지급액까지만** 차감하고, 넘치는 몫은 미청산으로 남겨
     *   다음 배치에서 또 가져온다. 지급이 0 인 사람에게서 돈을 걷는 줄은 못 만든다(매입취소 손실도 같은 규칙).
     * - 담당자 `payout_excluded`(지급 대상 아님)는 건너뛴다.
     *
     * @param  Collection<int, Settlement>  $settlements  이번 배치에 들어갈 정산(제출 전 미리 읽은 것)
     * @return array<int, array{salesman_id:int, name:string, amount:int, unconsumed:int, partial:bool, reason:string}>
     */
    public static function carryoverLinesFor($settlements): array
    {
        $payoutBySalesman = $settlements->groupBy('salesman_id')->map(fn ($g) => (int) $g->sum(fn ($s) => $s->actual_payout));

        $lines = [];
        foreach (Salesman::orderBy('name')->get() as $sm) {
            // 지급 대상 아닌 담당자 — `Settlement::isPayoutExcludedBySalesman()` 과 같은 칸(salesmen.payout_excluded)을 본다.
            if ((bool) $sm->payout_excluded) {
                continue;
            }
            $unconsumed = (int) $sm->unconsumed_carryover;
            if ($unconsumed === 0) {
                continue;
            }
            $amount = $unconsumed > 0
                ? $unconsumed
                : -min(abs($unconsumed), max(0, (int) ($payoutBySalesman[$sm->id] ?? 0)));
            if ($amount === 0) {
                continue;   // 음수 이월인데 이번 달 지급이 없다 — 다음 배치로
            }
            $lines[] = [
                'salesman_id' => (int) $sm->id,
                'name' => (string) $sm->name,
                'amount' => $amount,
                'unconsumed' => $unconsumed,
                'partial' => $amount !== $unconsumed,
                'reason' => __('settlement.batch.carryover_reason', ['amount' => number_format(abs($unconsumed))])
                    .($amount !== $unconsumed ? ' '.__('settlement.batch.carryover_partial', ['rest' => number_format(abs($unconsumed - $amount))]) : ''),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, int|null>  $line  결재선 title => user_id (steps 모드). 비면 종전 사다리.
     *                                         ⚠️ 하나라도 고르면 **대표가 있어야** 한다 — 최종 승인자가 없는 결재선은 영영 안 끝난다.
     */
    public static function submitForMonth(User $submitter, string $month, array $adjustments = [], array $line = []): self
    {
        if (! $submitter->canSubmitPayoutBatch()) {
            throw new \DomainException('월배치 제출 권한이 없습니다.');
        }
        // 월당 진행중(pending) 배치 1개 — 동시 제출로 정산이 재지목돼 phantom 배치가 되는 것 방지.
        if (self::where('month', $month)->where('status', self::STATUS_PENDING)->exists()) {
            throw new \DomainException('해당 월에 이미 승인 대기 중인 배치가 있습니다.');
        }

        $ids = self::eligibleSettlementIds($month);
        if ($ids->isEmpty()) {
            throw new \DomainException('지급 가능한 정산이 없습니다 (해당 월 확정 정산이 없거나, 전부 미수 있는 차량이라 지급보류됨. 완납 후 지급).');
        }

        $rank = $submitter->approvalRank();
        $steps = self::normalizeLine($line);

        $batch = DB::transaction(function () use ($submitter, $month, $rank, $ids, $adjustments, $steps) {
            $settlements = Settlement::whereIn('id', $ids)->get();
            $batch = self::create([
                'month' => $month,
                'submitter_id' => $submitter->id,
                'submitter_rank' => $rank,
                // steps 모드면 current_level 은 TOP_RANK 로 둔다 — 종전 소비자(board API·뱃지·라벨)가 null 을 안 보게.
                'current_level' => $steps === [] ? $rank + 1 : self::TOP_RANK,
                'current_step' => $steps === [] ? null : 1,
                'status' => self::STATUS_PENDING,
                'total_payout' => (int) $settlements->sum(fn ($s) => $s->actual_payout),
                'settlement_count' => $settlements->count(),
                'submitted_at' => now(),
            ]);
            Settlement::whereIn('id', $ids)->update(['payout_batch_id' => $batch->id]);
            foreach ($steps as $i => $st) {
                $batch->steps()->create(['seq' => $i + 1, 'title' => $st['title'], 'approver_user_id' => $st['user_id'], 'status' => 'pending']);
            }

            // 조정은 감사 경로(addAdjustment)를 그대로 탄다 — 총액 재계산·AuditLog 포함. kind(수기/인센티브)는 그대로 넘긴다.
            foreach ($adjustments as $a) {
                $batch->addAdjustment(
                    $submitter,
                    (int) $a['salesman_id'],
                    (int) $a['amount'],
                    (string) $a['reason'],
                    $a['cancel_vehicle_ids'] ?? null,
                    $a['kind'] ?? null,
                );
            }

            // 💸 미청산 이월 자동 조정 줄 (jin 2026-10-06) — 제출 모달 미리보기와 **같은 함수**로 뽑는다.
            foreach (self::carryoverLinesFor($settlements) as $line) {
                $batch->addAdjustment($submitter, $line['salesman_id'], $line['amount'], $line['reason'], null, SettlementPayoutAdjustment::KIND_CARRYOVER);
                CarryoverClearance::create([
                    'salesman_id' => $line['salesman_id'], 'payout_batch_id' => $batch->id,
                    'amount_krw' => $line['amount'], 'direction' => $line['amount'] > 0 ? 'pay' : 'collect',
                    'cleared_by' => $submitter->id, 'note' => '월배치 #'.$batch->id.' 자동 흡수',
                ]);
            }

            return $batch;
        });

        // 커밋 후 fire-and-forget — 첫 승인 계단에게 '승인 요청 도착' 알림톡(조정 반영된 총액).
        $batch->notifyPayoutRequest();

        return $batch;
    }

    /** 현재 단계에서 이 사용자가 승인/반려할 수 있나 — rank 정확 일치 또는 super override. */
    /**
     * 결재선 정리 — APPROVAL_TITLES 순서로, 고른 사람이 실제 그 직급의 최고관리자일 때만 칸이 된다.
     * 비어 있으면 [] (종전 사다리). 하나라도 있는데 대표가 없으면 예외.
     *
     * @return array<int, array{title: string, user_id: int}>
     */
    public static function normalizeLine(array $line): array
    {
        $out = [];
        foreach (User::APPROVAL_TITLES as $title) {
            $id = (int) ($line[$title] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $u = User::find($id);
            if (! $u || $u->permission !== 'admin' || $u->approval_title !== $title) {
                throw new \DomainException("{$title} 결재권자가 올바르지 않습니다.");
            }
            $out[] = ['title' => $title, 'user_id' => $id];
        }
        if ($out !== [] && ! collect($out)->contains('title', '대표')) {
            throw new \DomainException('결재선에 대표(최종 승인자)가 없습니다.');
        }

        return $out;
    }

    public function canDecide(User $u): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        if ($u->isSuperAdmin()) {
            return true;
        }
        if ($this->isStepsMode()) {
            return (int) ($this->currentStep()?->approver_user_id ?? 0) === (int) $u->id;
        }

        return $u->approvalRank() === $this->current_level;
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

            if ($this->isStepsMode()) {
                // steps 모드 — 이 칸에 서명하고 다음 pending 칸으로. 다음이 없으면 최종(대표 또는 super override).
                $step = $this->currentStep();
                if ($step && ! $u->isSuperAdmin()) {
                    $step->update(['status' => 'approved', 'acted_at' => now(), 'note' => $note ?: null]);
                } elseif ($step) {
                    $step->update(['status' => 'approved', 'acted_at' => now(), 'note' => ($note ?: '').' (시스템관리자 대행)']);
                }
                $this->unsetRelation('steps');
                $next = $u->isSuperAdmin() ? null : $this->steps()->where('status', 'pending')->orderBy('seq')->first();
                $final = $next === null;
            } else {
                // 대표(TOP) 서명 또는 super override → 완료 + 일괄 paid. 아니면 다음 계단으로.
                $final = $u->isSuperAdmin() || $this->current_level >= self::TOP_RANK;
                $next = null;
            }

            if ($final) {
                // 🧾 박제 — 최종 승인 시점의 사람별 카드(급여·2차 마감이 뒤에 바뀌어도 역사는 그대로). execute 전에 찍는다.
                $this->breakdown_snapshot = BatchPayoutBreakdown::forBatch($this);
                $this->status = self::STATUS_APPROVED;
                $this->decided_at = now();
                $this->current_step = null;
                $this->save();
                $this->execute();
                $this->markCancelLossesSettled();
                $becameFinal = true;
            } elseif ($next !== null) {
                $this->current_step = (int) $next->seq;
                $this->save();
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

            // 🚨 최종 승인 = "그 달 정산이 끝났다"는 확정 신호 → 대표 월 결산 보고를 이때 보낸다(jin 2026-07-31).
            //    종전엔 익월 첫 영업일에 무조건 나가서, 아직 확정 전인 정산이 통째로 빠진 채 보고됐다.
            //    fire-and-forget — 결산 알림 실패가 지급 승인을 깨면 안 된다(스케줄이 다음 날 재시도).
            try {
                Artisan::call('alimtalk:monthly-closing', ['month' => $this->month]);
            } catch (\Throwable $e) {
                Log::warning('월 결산 알림톡 트리거 실패', ['month' => $this->month, 'error' => $e->getMessage()]);
            }
        } else {
            $this->notifyPayoutRequest();
        }
    }

    /**
     * 최종 승인 시 — 이 배치의 매입취소 손실 조정이 덮는 차량에 반영 도장을 찍는다 (jin 2026-08-06).
     *
     * 구: 월배치 화면의 「반영 표시」 버튼을 사람이 눌렀다. **반려된 배치에 잘못 누르면 차감하지도
     *     않은 손실이 반영됨으로 사라져 영영 청구가 안 됐고**, 안 누르면 다음 달에 또 청구됐다.
     * 신: 최종 승인(=실제로 그 금액이 나간 시점)에만 자동으로 찍는다. 반려되면 안 찍힌다.
     *
     * ⚠️ 승인 시점에 "그 담당자의 미반영 손실 전부"로 다시 계산하면 안 된다 — 제출과 승인 사이에
     *    생긴 새 취소건까지 반영됨으로 찍혀 조용히 누락된다. 그래서 조정 행에 차량 id 를 박아뒀다.
     * ⚠️ bulk update 라 모델 이벤트가 안 뜬다(SKILLS §2). cancel_loss_settled_at 은 어떤 캐시에도
     *    안 물려 있어 안전하다 — 다른 컬럼을 여기 얹지 말 것.
     */
    private function markCancelLossesSettled(): void
    {
        $vehicleIds = $this->adjustments()
            ->whereNotNull('cancel_vehicle_ids')
            ->get()
            ->flatMap(fn (SettlementPayoutAdjustment $a) => $a->cancel_vehicle_ids ?? [])
            ->unique()
            ->values();

        if ($vehicleIds->isEmpty()) {
            return;
        }

        Vehicle::whereIn('id', $vehicleIds)
            ->whereNull('cancel_loss_settled_at')
            ->update(['cancel_loss_settled_at' => now()]);
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
            // 🧾 박제 — 정산을 풀기 **전에** 찍는다. 안 찍으면 반려된 배치는 정산이 0건이라 카드가 비어 혼동을 부른다(jin 2026-10-07).
            $this->breakdown_snapshot = BatchPayoutBreakdown::forBatch($this);
            $this->currentStep()?->update(['status' => 'rejected', 'acted_at' => now(), 'note' => $reason]);
            $this->status = self::STATUS_REJECTED;
            $this->decided_at = now();
            $this->reject_reason = $reason;
            $this->current_step = null;
            $this->save();

            // 멤버 정산 배치 해제 → 재배치 가능 (settlement_status=confirmed 유지)
            $this->settlements()->update(['payout_batch_id' => null]);
            // 💸 이 배치가 가져갔던 미청산 이월을 되돌린다 — 안 되돌리면 반려된 배치가 돈을 「처리한 것」이 되어
            //    다음 제출에서 그 이월이 안 나온다(조정 줄은 반려 배치의 역사로 남는다). (jin 2026-10-06)
            CarryoverClearance::where('payout_batch_id', $this->id)->delete();
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
    /**
     * 회사이익 요약 (승인 화면·알림톡 공용 단일 출처) — jin 2026-07-09.
     * 공식 = 총마진(Σ total_margin) − 지급총액(배치 total_payout, 조정 포함).
     * 관리자 대시보드 companyProfit / 월결산 알림톡과 동일 공식. 손실이면 음수.
     *
     * 🚨 2026-08-06 (jin) — **`+ 환차` 항 제거.** 그 항은 구 모델에서 actual_payout 에
     *   1:1 로 더해지던 환차를 상쇄하려던 것이다. 이제 환차는 총마진의 환율(실효 입금환율)로
     *   들어오고 payout 엔 안 더해지므로, 그대로 두면 회사이익이 환차만큼 부풀려진다.
     *   'fx' 는 실현 환차 총액의 **정보 표시용**으로만 남긴다(company_profit 에 이미 반영됨).
     */
    public function profitStats(): array
    {
        // 💡 관계를 미리 얹는다 — `total_margin` → `sales_amount_krw` → `settlement_exchange_rate`
        //    → `sale_unpaid_amount` 가 차량마다 잔금·회수이력을 읽는다. 560건 배치면 그대로 N+1 이다.
        $settlements = $this->settlements()
            ->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])
            ->get();
        $totalMargin = (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin);
        $fx = (int) $settlements->sum(fn (Settlement $s) => (int) ($s->exchange_difference_krw ?? 0));
        $payout = (int) $this->total_payout;
        // 🚨 2026-08-31 — 발송비(EMS·DHL)는 회사가 먼저 치른 돈이라 회사이익에서 빼야 한다.
        //   여기만 `company_net` 을 그대로 못 쓴다 — payout 이 정산 합이 아니라 **조정 포함 배치 총액**이라
        //   실지급액을 두 번 빼게 된다. 발송비 항만 같은 accessor 로 더한다.
        $shipping = (int) $settlements->sum(fn (Settlement $s) => (int) $s->shipping_fee);

        return [
            'total_margin' => $totalMargin,
            'payout' => $payout,
            'fx' => $fx,
            'shipping' => $shipping,
            'company_profit' => $totalMargin - $payout - $shipping,
            // 📊 배치 전체 마진율 (jin 2026-09-18) — Σ총마진 / Σ판매금원화. 내수는 양쪽에서 빠진다.
            'margin_rate' => Settlement::marginRateOf($settlements),
            // 💰 기본급 합계 — **표시 전용**. 회사이익·지급총액 어디에도 안 들어간다.
            'base_salary' => $this->baseSalaryTotal($settlements),
        ];
    }

    /**
     * 💰 **이 배치 사람들 + 정산이 없어도 월급이 나가는 직원의 기본급 합** (jin 2026-09-18 → 2026-10-06 확대).
     *
     * 화면의 「+ 기본급 합계 = 이달 송금 예상」이 이 값을 쓴다 — 통장에서 나갈 돈을 한 번에 보려는 것이다.
     *
     * 🚫 **배치 총액에 더하지 않는다** — `total_payout` 은 정산만이고, 회사이익
     *    (`총마진 − 지급 − 발송비`)도 그대로다. 급여를 섞으면 그 지표의 뜻이 바뀐다(§8 #72).
     * 🔀 **10-06 (jin) — 그 달에 정산 건이 없는 사내직원도 들어간다.** 구: 「배치에 이름이 있는 사람만」이라
     *    정산 0건인 직원의 월급이 송금 예상에서 조용히 빠졌다. 신: 배치 사람들(정산·조정) ∪
     *    `Salesman::salariedForBatch()`(재직·지급대상·기본급>0). 두 집합을 **id 로** 합쳐 한 번만 센다.
     * 🔑 조정만 있는 사람도 배치의 일원이라 함께 센다(승인 화면이 그렇게 그린다).
     *
     * @param  Collection<int, Settlement>|null  $settlements  이미 읽어둔 정산(재조회 방지)
     * @param  Collection<int, Salesman>|null  $salaryOnly  이미 구한 「기본급만」 명부(재조회 방지 — 화면이 줄도 그린다)
     */
    public function baseSalaryTotal($settlements = null, $salaryOnly = null): int
    {
        $settlements ??= $this->relationLoaded('settlements')
            ? $this->settlements
            : $this->settlements()->with('salesman')->get();
        // 이미 읽어둔 관계가 있으면 그걸 쓴다 — 월배치 화면은 배치 60개를 한 번에 그린다.
        $adjustments = $this->relationLoaded('adjustments')
            ? $this->adjustments
            : $this->adjustments()->with('salesman')->get();

        $inBatch = $settlements->pluck('salesman')
            ->merge($adjustments->pluck('salesman'))
            ->filter()
            ->unique('id');

        return (int) $inBatch
            ->merge($salaryOnly ?? $this->salaryOnlyPeople($settlements, $adjustments))
            ->sum(fn (Salesman $sm) => (int) ($sm->base_salary_krw ?? 0));
    }

    /**
     * 💴 **정산도 조정도 없는데 이달 월급은 나가는 직원** (jin 2026-10-06
     * *「정산이 0명인 사람은 월급만 나올 수 있게 변경이 되어야 해」*).
     *
     * 월배치 드릴다운·승인 breakdown 이 이 목록으로 「기본급만」 줄을 그리고, `baseSalaryTotal` 이 같은
     * 목록을 합한다 — **세 곳이 같은 메서드를 부르므로** 줄의 합과 합계가 어긋나지 않는다.
     * 대상 조건은 `Salesman::salariedForBatch()` 한 곳. 배치에 이미 있는 사람은 **id 로** 뺀다
     * (이름 키로 빼면 동명이인이 사라진다).
     *
     * ⚠️ **지금의 명부다** — 옛 배치를 오늘 열면 그 뒤 입사한 직원도 보인다. `base_salary_krw` 자체가
     *    박제 없이 실시간으로 읽히므로(수정하면 과거 화면도 바뀐다) 같은 결로 둔다.
     *
     * @param  Collection<int, Settlement>  $settlements
     * @param  Collection<int, SettlementPayoutAdjustment>  $adjustments
     * @return Collection<int, Salesman>
     */
    public function salaryOnlyPeople($settlements, $adjustments): Collection
    {
        $ids = $settlements->pluck('salesman_id')
            ->merge($adjustments->pluck('salesman_id'))
            ->filter()
            ->unique()
            ->values();

        return Salesman::query()
            ->salariedForBatch()
            ->whereNotIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    public function notifyPayoutRequest(): void
    {
        $svc = BizmAlimtalkService::active();
        $vars = [
            '귀속월' => $this->month,
            '건수' => (string) $this->settlement_count,
            '총액' => number_format($this->total_payout).'원',
            '회사이익' => number_format($this->profitStats()['company_profit']).'원',
            '제출자' => $this->submitter?->name ?? '-',
        ];
        // 지금 차례인 사람에게만 — steps 모드 = 그 칸의 한 사람(부장·전무·대표 각자 차례에 1회, jin (가)) / 종전 = 그 계단 전원.
        foreach ($this->currentApprovers()->filter(fn (User $u) => trim((string) $u->phone) !== '') as $user) {
            $url = $this->approvalLinkFor($user);
            $svc->send('erp_payout_request', (string) $user->phone, $vars, ['user_id' => $user->id], [
                ['name' => '승인/반려 바로가기', 'url' => $url],
            ]);
        }
        // 📨 마지막 발송 시각 — 재전송 연타 방지·발송 결과 표시의 기준(2026-10-07). 상태 컬럼이 아니라 조용히 저장.
        $this->forceFill(['request_notified_at' => now()])->saveQuietly();
    }

    /**
     * 재전송할 수 있는 사람 — **관리 · 업무관리자만**(= 월배치 제출 권한 `canSubmitPayoutBatch()`, rank 1~2).
     * jin 2026-10-07: 「제출한 쪽이 승인 쪽을 재촉하는 버튼」. 최고관리자는 받는 사람(자기에게 보내는 버튼이 되고,
     * 폰 승인 화면에서 [승인] 위치가 밀린다), 시스템관리자는 서버에서 직접 보낼 수 있어 뺀다.
     * ⚠️ 「관리 이상 = 넷 전부」 기본값의 **명시적 예외**다(메모리 feedback_manager_and_above).
     */
    public static function canResendRequest(?User $u): bool
    {
        return $u !== null && $u->canSubmitPayoutBatch();
    }

    /** 재전송 대기 — 마지막 발송 뒤 이 시간 안에는 다시 못 보낸다(대표 카톡 도배 방지). */
    public const RESEND_COOLDOWN_MINUTES = 10;

    /** 지금 재전송까지 남은 분(0 = 가능). 발송 기록이 없으면 0. */
    public function resendWaitMinutes(): int
    {
        if (! $this->request_notified_at) {
            return 0;
        }
        $elapsed = $this->request_notified_at->diffInSeconds(now(), false);
        $left = self::RESEND_COOLDOWN_MINUTES * 60 - (int) $elapsed;

        return $left > 0 ? (int) ceil($left / 60) : 0;
    }

    /**
     * 📨 마지막 승인요청 발송 결과 — 이 배치의 `request_notified_at` 이후 `erp_payout_request` 로그(배치 id 가 로그에 없어서 시각으로 맺는다).
     * 'delivered'(전달 확인) · 'sent'(발송·전달 확인 전) · 'failed'(실패·미전달) · 'skipped'(게이트 차단) · null(기록 없음).
     * 같은 시각대에 다른 배치 요청이 겹칠 수 있으나 월당 진행 배치는 1개라 사실상 이 배치 것이다.
     */
    public function lastRequestDelivery(): ?string
    {
        if (! $this->request_notified_at) {
            return null;
        }
        $logs = AlimtalkLog::query()
            ->where('template_code', 'like', 'erp_payout_request%')
            ->where('created_at', '>=', $this->request_notified_at->copy()->subMinute())
            ->get(['status', 'report_status']);
        if ($logs->isEmpty()) {
            return null;
        }
        if ($logs->contains(fn ($l) => $l->status === 'failed' || $l->report_status === 'undelivered')) {
            return 'failed';
        }
        if ($logs->every(fn ($l) => $l->report_status === 'delivered')) {
            return 'delivered';
        }
        if ($logs->every(fn ($l) => $l->status === 'skipped')) {
            return 'skipped';
        }

        return 'sent';
    }

    /**
     * 화면 뱃지용 두 갈래 (jin 2026-10-07 「발송성공·실패 이 둘만」) — true 초록 / false 빨강 / null 기록 없음(안 그림).
     * 성공 = 발송 접수(sent)·전달(delivered). 실패 = 실패·미전달·설정 차단(skipped — 대표에게 안 갔으니 실패로 본다).
     */
    public function requestSendOk(): ?bool
    {
        $d = $this->lastRequestDelivery();

        return $d === null ? null : in_array($d, ['delivered', 'sent'], true);
    }

    /**
     * 📨 **승인요청 재전송** (jin 2026-10-07) — 대표가 카톡을 놓쳤을 때 제출 권한자가 월배치 화면에서 다시 보낸다.
     * 현재 승인 계단의 사람에게만, 서명 링크를 새로 만들어 보낸다(배치 내용 불변). 연타 방지 10분 · 감사로그.
     */
    public function resendPayoutRequest(User $by): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(__('payout_batch.resend.not_pending'));
        }
        if (! self::canResendRequest($by)) {
            throw new \DomainException(__('payout_batch.resend.forbidden'));
        }
        if (($wait = $this->resendWaitMinutes()) > 0) {
            throw new \DomainException(__('payout_batch.resend.wait', ['min' => $wait]));
        }
        $this->notifyPayoutRequest();
        AuditLog::recordEvent($this, 'payout_request_resent');
    }

    /**
     * 이 배치 × 승인자에 바인딩된 만료 서명 승인 링크(5일). 카톡 버튼 URL 로 주입.
     *
     * 🚨 **도메인을 APP_URL 로 고정한다** (2026-09-04) — 카카오는 발송 버튼의 링크를 승인본
     *    (`https://heysellcar.com/a/payout/#{url}`)과 대조해서, 다르면 발송을 통째로 거부한다
     *    (`K108:NoMatchedTemplateButtonException` = 「등록된 버튼과 다름」).
     *
     *    이 서버는 nginx `server_name` 이 **세 개**다 — `heysellcar.com` · `www.heysellcar.com` · IP.
     *    그런데 이 링크는 제출 화면(HTTP 요청) 안에서 만들어지고, 요청 컨텍스트의 서명 URL 은
     *    **접속한 호스트를 그대로** 쓴다. 제출자가 www 나 IP 로 들어와 있으면 버튼 링크가 달라져
     *    그 배치의 승인 요청이 아무에게도 안 간다(조용히 실패 — 화면엔 제출 성공으로 보인다).
     *
     *    실측 근거(heymanerp): K108 5건이 **전부 이 템플릿**이고, 같은 버튼 코드를 쓰지만
     *    cron 에서 만드는 `erp_capital_weekly` 는 0건이다 — 그쪽은 요청 호스트가 없어 APP_URL 을 쓴다.
     *
     * ⚠️ **서명은 호스트까지 포함해 계산된다** — 만든 뒤에 도메인만 바꿔치면 링크가 깨진다.
     *    반드시 만들기 **전에** 고정하고, 끝나면 되돌린다(다른 URL 생성에 영향이 없게).
     */
    public function approvalLinkFor(User $user): string
    {
        URL::forceRootUrl(config('app.url'));

        try {
            return URL::temporarySignedRoute('payout.approve.show', now()->addDays(5), [
                'batch' => $this->id,
                'u' => $user->id,
            ]);
        } finally {
            URL::forceRootUrl(null);
        }
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
