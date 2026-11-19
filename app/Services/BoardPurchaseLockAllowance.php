<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🔐 board 발 매입 락 해제 승인 — 「이 바이어로 N대 더 등록」 (jin 2026-11-19).
 *
 * board 가 락 걸린 바이어로 요청을 올리면 ERP 승인큐(관리 이상)가 승인·거절한다. 승인된 대수만큼 board 가
 * 그 바이어로 구매확정할 수 있고, 차가 ERP 에 등록될 때(purchase-sync 새 차량) 1대씩 줄어든다.
 * **단순 카운트** — 락이 걸렸는지·금액이 얼마인지는 따지지 않는다. 승인 후 7일이 지나면 남은 대수는 사라진다.
 * 대수가 남아 있어도 추가 요청을 받을 수 있고, 승인되면 더해진다(승인 건마다 자기 7일).
 *
 * 저장 = `approval_requests`(action_type = board_purchase_lock, target = Buyer). payload:
 *   requested_count · approved_count · used[{listing_id, vehicle_id, at}] · salesman_id · salesman_name ·
 *   requested_by_name · requested_by_email · purchase_listing_id · vehicle_number · vehicle_label
 *
 * 🚫 「살아 있는 승인」 조건을 다른 곳에 옮겨 적지 말 것 — 조회 API · /buyers · 차감이 전부 여기를 지난다(§8 #44).
 * 설계 = docs/design/board-purchase-lock-approval.md · 규격 = docs/integration/board-portal-api.md §17.
 */
class BoardPurchaseLockAllowance
{
    public const TYPE = ApprovalRequest::TYPE_BOARD_PURCHASE_LOCK;

    /** 승인 뒤 쓸 수 있는 기간(일) — jin «7일로 해. 등록 절차니까». */
    public const VALID_DAYS = 7;

    public const MAX_COUNT = 999;

    private static function base()
    {
        return ApprovalRequest::query()->where('action_type', self::TYPE)->where('target_type', Buyer::class);
    }

    public static function approvedCount(ApprovalRequest $r): int
    {
        return (int) ($r->payload['approved_count'] ?? 0);
    }

    public static function usedCount(ApprovalRequest $r): int
    {
        return count($r->payload['used'] ?? []);
    }

    public static function remainingOf(ApprovalRequest $r): int
    {
        return max(0, self::approvedCount($r) - self::usedCount($r));
    }

    public static function expiresAt(ApprovalRequest $r): ?Carbon
    {
        return $r->status === ApprovalRequest::STATUS_APPROVED && $r->decided_at
            ? $r->decided_at->copy()->addDays(self::VALID_DAYS)
            : null;
    }

    /** board·화면이 보는 상태 — pending | approved(쓸 수 있음) | rejected | exhausted(다 씀) | expired(7일 지남) | cancelled. */
    public static function stateOf(ApprovalRequest $r): string
    {
        if ($r->status !== ApprovalRequest::STATUS_APPROVED) {
            return (string) $r->status;
        }
        if (self::remainingOf($r) <= 0) {
            return 'exhausted';
        }

        return self::expiresAt($r)?->isPast() ? 'expired' : 'approved';
    }

    /**
     * 살아 있는 승인(승인됨 · 7일 이내 · 남은 대수 있음) — 먼저 만료되는 것부터.
     *
     * @param  list<int>  $buyerIds
     * @return Collection<int, ApprovalRequest>
     */
    public static function liveGrants(array $buyerIds, bool $lock = false): Collection
    {
        if ($buyerIds === []) {
            return collect();
        }

        return self::base()->whereIn('target_id', $buyerIds)
            ->where('status', ApprovalRequest::STATUS_APPROVED)
            ->whereNull('used_at')
            ->where('decided_at', '>', now()->subDays(self::VALID_DAYS))
            ->orderBy('decided_at')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->filter(fn (ApprovalRequest $r) => self::remainingOf($r) > 0)
            ->values();
    }

    /**
     * 바이어별 남은 대수 요약 — `[buyer_id => {remaining, approved_count, expires_at}]`. 남은 게 없는 바이어는 키가 없다.
     *
     * @param  list<int>  $buyerIds
     * @return array<int, array{remaining:int, approved_count:int, expires_at:string}>
     */
    public static function summaries(array $buyerIds): array
    {
        $out = [];
        foreach (self::liveGrants($buyerIds)->groupBy('target_id') as $buyerId => $grants) {
            $out[(int) $buyerId] = [
                'remaining' => (int) $grants->sum(fn ($r) => self::remainingOf($r)),
                'approved_count' => (int) $grants->sum(fn ($r) => self::approvedCount($r)),
                // 가장 먼저 만료되는 시각 — 그 뒤엔 남은 대수가 줄어든다.
                'expires_at' => self::expiresAt($grants->first())->toIso8601String(),
            ];
        }

        return $out;
    }

    public static function summary(int $buyerId): ?array
    {
        return self::summaries([$buyerId])[$buyerId] ?? null;
    }

    public static function pendingFor(int $buyerId): ?ApprovalRequest
    {
        return self::base()->where('target_id', $buyerId)->where('status', ApprovalRequest::STATUS_PENDING)->latest('id')->first();
    }

    public static function latestFor(int $buyerId): ?ApprovalRequest
    {
        return self::base()->where('target_id', $buyerId)->with('approver:id,name')->latest('id')->first();
    }

    /**
     * 새 차량이 그 바이어로 등록됐다 → 1대 차감(먼저 만료되는 승인부터).
     * 남은 승인이 없어도 등록은 막지 않는다(돈이 나간 뒤다) — 초과로 감사에만 남긴다.
     *
     * @return array{consumed: bool, remaining: int}
     */
    public static function consume(int $buyerId, Vehicle $vehicle, ?int $listingId = null): array
    {
        return DB::transaction(function () use ($buyerId, $vehicle, $listingId) {
            $grants = self::liveGrants([$buyerId], lock: true);
            $grant = $grants->first();
            if (! $grant) {
                // 승인을 한 번이라도 받았던 바이어가 대수 없이 들어온 경우만 남긴다(승인과 무관한 평소 등록은 소음).
                if (self::base()->where('target_id', $buyerId)->where('status', ApprovalRequest::STATUS_APPROVED)
                    ->where('decided_at', '>', now()->subDays(self::VALID_DAYS))->exists()) {
                    AuditLog::create([
                        'user_id' => null, 'auditable_type' => Vehicle::class, 'auditable_id' => $vehicle->id,
                        'action' => 'board_purchase_lock_overshoot', 'column_name' => 'buyer:'.$buyerId,
                        'old_value' => null, 'new_value' => $listingId !== null ? 'listing:'.$listingId : null,
                    ]);
                }

                return ['consumed' => false, 'remaining' => 0];
            }

            $payload = $grant->payload ?? [];
            $payload['used'][] = ['listing_id' => $listingId, 'vehicle_id' => $vehicle->id, 'at' => now()->toIso8601String()];
            $grant->payload = $payload;
            if (self::remainingOf($grant) <= 0) {
                $grant->used_at = now();
            }
            $grant->save();

            AuditLog::create([
                'user_id' => null, 'auditable_type' => Vehicle::class, 'auditable_id' => $vehicle->id,
                'action' => 'board_purchase_lock_used', 'column_name' => 'buyer:'.$buyerId,
                'old_value' => null,
                'new_value' => self::usedCount($grant).'/'.self::approvedCount($grant),
                'approval_request_id' => $grant->id,
            ]);

            $remaining = (int) $grants->sum(fn ($r) => self::remainingOf($r));

            return ['consumed' => true, 'remaining' => $remaining];
        });
    }

    /** board 로 내려가는 한 건의 모양(§17 `latest`). 금액·연락처 없음. */
    public static function present(ApprovalRequest $r): array
    {
        $p = $r->payload ?? [];

        return [
            'id' => $r->id,
            'status' => self::stateOf($r),
            'requested_count' => (int) ($p['requested_count'] ?? 0),
            'approved_count' => $r->status === ApprovalRequest::STATUS_APPROVED ? self::approvedCount($r) : null,
            'used_count' => self::usedCount($r),
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'requested_by_name' => $p['requested_by_name'] ?? null,
            'requested_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decided_by_name' => $r->approver?->name,
            'expires_at' => self::expiresAt($r)?->toIso8601String(),
        ];
    }

    /** 요청을 누른 사람이 이 사용자인가 — 대리로 누른 관리는 자기 건을 승인할 수 없다(jin 2026-11-19). */
    public static function requestedBy(ApprovalRequest $r, ?string $email): bool
    {
        $by = mb_strtolower(trim((string) ($r->payload['requested_by_email'] ?? '')));

        return $by !== '' && $by === mb_strtolower(trim((string) $email));
    }
}
