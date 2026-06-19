<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * board 영업 포털 선적요청 — 영업이 board에서 올린 "선적해달라" 지시 레코드.
 *
 * 권위 = docs/integration/board-portal-api.md §5. vehicles 상태 컬럼은 건드리지 않음(게이트 회귀 방지);
 * 관리/수출통관이 차량 편집 패널에서 실무(컨테이너#·B/L·선적일·서류) 진행 시 progress 변경.
 */
class ShippingRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const METHODS = ['RORO', 'CONTAINER'];

    protected $fillable = [
        'batch_id', 'vehicle_id', 'buyer_id', 'consignee_id', 'shipping_method',
        'requested_by_email', 'status', 'requested_at', 'processed_at', 'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Consignee::class);
    }
}
