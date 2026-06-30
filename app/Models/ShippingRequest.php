<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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

    public const STATUS_CANCELLED = 'cancelled';   // car-erp 에서 무름 — open 집계서 제외, 차 재요청 가능

    public const METHODS = ['RORO', 'CONTAINER'];

    // 묶음 단위 B/L 방식 (오리지널/써랜더) — 영업 요청값, 이중가드의 한쪽 (vehicles.bl_type 가 관리 확인값)
    public const BL_TYPE_ORIGINAL = 'original';

    public const BL_TYPE_SURRENDER = 'surrender';

    public const BL_TYPES = ['original', 'surrender'];

    // B/L 단계 상태 — 선적단계(status)와 별개
    public const BL_STATUS_NONE = 'none';

    public const BL_STATUS_REQUESTED = 'requested';   // 영업이 B/L요청 (오리지널/써랜더 확정)

    public const BL_STATUS_ISSUED = 'issued';         // 관리가 B/L 발급 (멤버 차량 일괄 기입)

    /** 선적단계 open(관리 미완료) — sync diff·shippable 제외 판정 단일출처. */
    public const OPEN_STATUSES = ['requested', 'in_progress'];

    protected $fillable = [
        'batch_id', 'vehicle_id', 'buyer_id', 'consignee_id', 'shipping_method',
        'bl_type', 'bl_status', 'requested_by_email', 'status',
        'requested_at', 'processed_at', 'change_requested_at', 'change_request_meta', 'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'change_requested_at' => 'datetime',
        'change_request_meta' => 'array',
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

    /**
     * 묶음 재무 집계 — 단일출처(SKILLS §13). 컨트롤러(/bundles)·관리 화면 공용(drift 방지).
     * NULL(환율 미입력) 제외(?? 0 금지, cash_audit). fully_paid = unpaid<=0 AND fx_missing=0.
     *
     * @param  Collection|iterable  $vehicles  Vehicle 컬렉션
     */
    public static function financeForVehicles($vehicles): array
    {
        $salesByCurrency = [];
        $unpaidTotal = 0;
        $fxMissing = 0;
        $denomKrw = 0;

        foreach ($vehicles as $v) {
            if ($v === null || (int) $v->sale_price <= 0) {
                continue;
            }
            $cur = $v->currency ?: 'KRW';
            $salesByCurrency[$cur] = ($salesByCurrency[$cur] ?? 0) + (float) $v->sale_price;

            $cache = $v->sale_unpaid_amount_krw_cache;
            if ($cache === null) {
                $fxMissing++;   // 환율 미입력 — 합산 제외(완납판정 불가)

                continue;
            }
            $unpaidTotal += (int) $cache;
            $denomKrw += (int) round(((float) $v->sale_total_amount) * (float) ($v->exchange_rate ?? 0));
        }

        return [
            'sales_by_currency' => $salesByCurrency ?: (object) [],
            'unpaid_total_krw' => $unpaidTotal,
            'fx_missing_count' => $fxMissing,
            'fully_paid' => $unpaidTotal <= 0 && $fxMissing === 0,
            'unpaid_ratio' => $denomKrw > 0 ? round(max(0, $unpaidTotal) / $denomKrw, 4) : null,
        ];
    }
}
