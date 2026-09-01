<?php

namespace App\Models;

use App\Services\BizmAlimtalkService;
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
        'month', 'submitter_id', 'submitter_rank', 'current_level', 'status',
        'total_payout', 'settlement_count', 'submitted_at', 'decided_at', 'reject_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
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
    public function addAdjustment(User $by, int $salesmanId, int $amount, string $reason, ?array $cancelVehicleIds = null): SettlementPayoutAdjustment
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

        return DB::transaction(function () use ($by, $salesmanId, $amount, $reason, $cancelVehicleIds) {
            $adj = $this->adjustments()->create([
                'salesman_id' => $salesmanId,
                'amount' => $amount,
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
        return Settlement::whereIn('id', $ids)->with('vehicle')->get()
            ->reject(fn ($s) => (int) ($s->vehicle?->sale_unpaid_amount ?? 0) > 0)
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
    public static function submitForMonth(User $submitter, string $month, array $adjustments = []): self
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

        $batch = DB::transaction(function () use ($submitter, $month, $rank, $ids, $adjustments) {
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

            // 조정은 감사 경로(addAdjustment)를 그대로 탄다 — 총액 재계산·AuditLog 포함.
            foreach ($adjustments as $a) {
                $batch->addAdjustment(
                    $submitter,
                    (int) $a['salesman_id'],
                    (int) $a['amount'],
                    (string) $a['reason'],
                    $a['cancel_vehicle_ids'] ?? null,
                );
            }

            return $batch;
        });

        // 커밋 후 fire-and-forget — 첫 승인 계단에게 '승인 요청 도착' 알림톡(조정 반영된 총액).
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
                $this->markCancelLossesSettled();
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
        $settlements = $this->settlements()->get();
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
        ];
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
        foreach (AlimtalkRecipients::payoutApproverUsers($this->current_level) as $user) {
            $url = $this->approvalLinkFor($user);
            $svc->send('erp_payout_request', (string) $user->phone, $vars, ['user_id' => $user->id], [
                ['name' => '승인/반려 바로가기', 'url' => $url],
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
