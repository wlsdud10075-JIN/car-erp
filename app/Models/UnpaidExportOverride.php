<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 큐 2.6 — admin 미입금 우회 승인 감사 (append-only).
 *
 * 미수금 잔존 차량에 대해 admin이 통관·선적·DHL 단계 진입을 승인할 때 1행 기록.
 * 단계별(per-stage) 승인 — 통관 OK ≠ 선적 OK ≠ DHL OK.
 *
 * append-only 보장: updating·deleting 이벤트에서 예외 throw.
 * 정정·취소가 필요하면 별도 보상 레코드를 추가하는 방식 (예: 같은 단계에 새 reason으로 재승인).
 */
class UnpaidExportOverride extends Model
{
    // clearance=통관 진입(C5 50%) / shipping=선적 진입(C5 50%) / bl=B/L 발행(G1 100%) 우회.
    // 'dhl'(폐기, 2026-06-23)은 enum 에만 남고 신규 미사용 — 선적·B/L 우회 분리로 대체.
    public const STAGES = ['clearance', 'shipping', 'bl'];

    protected $fillable = [
        'vehicle_id', 'stage', 'approved_by',
        'reason', 'approved_at', 'ip_address',
        'sale_unpaid_amount_snapshot',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'sale_unpaid_amount_snapshot' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Security 요구사항 — append-only. 사후 UPDATE / DELETE 차단.
        static::updating(function () {
            throw new \RuntimeException('unpaid_export_overrides는 append-only입니다. 보상 레코드를 추가하세요.');
        });
        static::deleting(function () {
            throw new \RuntimeException('unpaid_export_overrides는 삭제할 수 없습니다 (audit 기록 보존).');
        });

        // 승인 발생 시 vehicle.is_override_active = true (UI/대시보드 표시용)
        static::created(function (UnpaidExportOverride $o) {
            Vehicle::where('id', $o->vehicle_id)->update(['is_override_active' => true]);
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
