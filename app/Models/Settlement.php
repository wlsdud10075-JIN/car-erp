<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Settlement extends Model
{
    protected $fillable = [
        'vehicle_id', 'salesman_id', 'settlement_type', 'settlement_ratio',
        'per_unit_amount', 'other_deduction', 'settlement_status',
        'confirmed_at', 'paid_at', 'confirmed_snapshot', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'confirmed_snapshot' => 'array',
    ];

    /**
     * 큐 11-4 G7 — 감사 로그 추적 컬럼 (Settlement 기준).
     */
    public const AUDITED_COLUMNS = ['settlement_status', 'paid_at'];

    /**
     * 큐 10 H3·H4 — 정산 saving 시 검증 + snapshot 캡처.
     * 큐 11-4 — settlement_status / paid_at 변경 audit_logs 기록.
     */
    protected static function booted(): void
    {
        static::updated(function (Settlement $s) {
            foreach (self::AUDITED_COLUMNS as $col) {
                if ($s->wasChanged($col)) {
                    AuditLog::recordChange(
                        $s,
                        $col,
                        $s->getOriginal($col),
                        $s->getAttribute($col),
                    );
                }
            }
        });

        static::saving(function (Settlement $s) {
            // 큐 14-4-2 — paid 전환은 canApprove user(관리/admin/super)만 직접 가능.
            // 비-canApprove는 ApprovalRequest 흐름(인라인 [승인 요청] 버튼)으로 진행.
            // auth 미존재 시(시드·artisan)는 우회.
            $becamePaid = $s->settlement_status === 'paid'
                && $s->getOriginal('settlement_status') !== 'paid';
            if ($becamePaid && auth()->check() && ! auth()->user()->canApprove()) {
                throw ValidationException::withMessages([
                    'settlement_status' => '정산 지급(paid) 전환은 승인 권한자만 직접 가능합니다. 정산 목록의 [지급 승인 요청] 버튼을 사용하세요.',
                ]);
            }

            // H3 — status ∈ {confirmed, paid}이면 settlement_type별 값 > 0 강제.
            if (in_array($s->settlement_status, ['confirmed', 'paid'], true)) {
                $hasRatio = $s->settlement_type === 'ratio' && (float) ($s->settlement_ratio ?? 0) > 0;
                $hasPerUnit = $s->settlement_type === 'per_unit' && (float) ($s->per_unit_amount ?? 0) > 0;
                if (! $hasRatio && ! $hasPerUnit) {
                    throw ValidationException::withMessages([
                        'settlement_ratio' => '정산 확정·지급 시 정산비율(ratio) 또는 건당 정산액(per_unit) 중 하나가 0보다 커야 합니다.',
                    ]);
                }
            }

            // H4 — status가 paid로 전환되는 시점에 vehicle 회계 컬럼 + 마진을 snapshot 캡처.
            $becamePaid = $s->settlement_status === 'paid'
                && $s->getOriginal('settlement_status') !== 'paid';
            if ($becamePaid && empty($s->confirmed_snapshot)) {
                $v = $s->vehicle;
                // 큐 20-D — Gemini Lock 지적 — 잔금 confirmed 상태도 함께 캡처.
                // paid 시점 ledger 의 무엇이 confirmed였는지 회계감사 추적 가능.
                $confirmedFinalPayments = $v
                    ? $v->finalPayments->whereNotNull('confirmed_at')->map(fn ($p) => [
                        'id' => $p->id,
                        'amount' => (float) $p->amount,
                        'confirmed_at' => $p->confirmed_at?->toIso8601String(),
                        'transfer_id' => $p->transfer_id,
                    ])->values()->all()
                    : [];
                $confirmedPurchasePayments = $v
                    ? $v->purchaseBalancePayments->whereNotNull('confirmed_at')->map(fn ($p) => [
                        'id' => $p->id,
                        'amount' => (float) $p->amount,
                        'confirmed_at' => $p->confirmed_at?->toIso8601String(),
                    ])->values()->all()
                    : [];

                $s->confirmed_snapshot = [
                    'captured_at' => now()->toIso8601String(),
                    'exchange_rate' => $v?->exchange_rate,
                    'export_declaration_amount' => $v?->export_declaration_amount,
                    'transport_fee' => $v?->transport_fee,
                    'purchase_price' => $v?->purchase_price,
                    'cost_total' => $v?->cost_total,
                    'sales_amount_krw' => $s->sales_amount_krw,
                    'settlement_sales_krw' => $s->settlement_sales_krw,
                    'sales_margin' => $s->sales_margin,
                    'vat_margin' => $s->vat_margin,
                    'total_margin' => $s->total_margin,
                    'settlement_amount' => $s->settlement_amount,
                    'actual_payout' => $s->actual_payout,
                    // 큐 20-D — 잔금 confirmed 스냅샷 (Gemini Lock)
                    'confirmed_final_payments' => $confirmedFinalPayments,
                    'confirmed_purchase_payments' => $confirmedPurchasePayments,
                ];
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /**
     * 큐 14-4-2 — 이 정산에 대한 지급 승인 요청 (가장 최근).
     * 정산 목록 행에 "승인 대기" / "거부됨" 인라인 표시 + 요청자가 거부 사유 확인용.
     * morphOne + latestOfMany로 settlements/index에서 eager load 가능.
     */
    public function latestPayApproval()
    {
        return $this->morphOne(ApprovalRequest::class, 'target')
            ->where('action_type', ApprovalRequest::TYPE_SETTLEMENT_PAY)
            ->latestOfMany();
    }

    // ── 마진 computed (CLAUDE.md §정산마진공식) ─────────────────────

    public function getSalesAmountKrwAttribute(): int
    {
        $v = $this->vehicle;
        if (! $v) {
            return 0;
        }

        return (int) (((float) ($v->export_declaration_amount ?? 0) - (float) ($v->transport_fee ?? 0)) * (float) ($v->exchange_rate ?? 0));
    }

    public function getSettlementSalesKrwAttribute(): int
    {
        return $this->sales_amount_krw - ($this->vehicle?->cost_total ?? 0);
    }

    public function getSalesMarginAttribute(): int
    {
        return $this->settlement_sales_krw - (int) ($this->vehicle?->purchase_price ?? 0);
    }

    public function getVatMarginAttribute(): int
    {
        return (int) (($this->vehicle?->purchase_price ?? 0) * 0.09);
    }

    public function getTotalMarginAttribute(): int
    {
        return $this->sales_margin + $this->vat_margin;
    }

    public function getSettlementAmountAttribute(): int
    {
        if ($this->settlement_type === 'ratio') {
            return (int) ($this->total_margin * (($this->settlement_ratio ?? 0) / 100));
        }

        return (int) ($this->per_unit_amount ?? 0);
    }

    public function getActualPayoutAttribute(): int
    {
        return $this->settlement_amount - (int) ($this->other_deduction ?? 0);
    }
}
