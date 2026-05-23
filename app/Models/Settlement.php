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
        'secondary_status', 'secondary_closed_at',
        // 회의확장씬 #7 Step C-4 (2026-05-22) — 2차 정산 시 환차 (현재 환율 vs 입금 시점 평균)
        'exchange_difference_krw',
        // 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 시 환율 (수동 입력 또는 자동 fetch 저장, audit trail)
        'exchange_rate_at_close',
        'confirmed_at', 'paid_at', 'confirmed_snapshot', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'secondary_closed_at' => 'datetime',
        'exchange_difference_krw' => 'decimal:2',
        'exchange_rate_at_close' => 'decimal:4',
        'confirmed_snapshot' => 'array',
    ];

    /**
     * 큐 11-4 G7 — 감사 로그 추적 컬럼 (Settlement 기준).
     * 회의확장씬 #8 (2026-05-22) — secondary_status 추가.
     */
    public const AUDITED_COLUMNS = ['settlement_status', 'secondary_status', 'paid_at'];

    /**
     * 회의확장씬 #8 (2026-05-22) — 2차 정산 status enum-like (application 검증).
     * NULL = 1차 진행 중 / pending = 2차 대기 (paid 후 자동) / closed = 최종 마무리 (수동)
     */
    public const SECONDARY_STATUSES = [
        'pending' => '2차 정산 대기',
        'closed' => '최종 마무리',
    ];

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

            // 회의확장씬 #8 (2026-05-22) — paid 전환 시 secondary_status='pending' 자동 set.
            // 한 달 뒤 측정되는 기타비용(말소·면허·탁송·보험·이전비·기타1,2) 수정 대기 상태.
            // 이미 secondary_status set 된 경우(예: 마이그·시드)는 우회.
            $becomingPaid = $s->settlement_status === 'paid'
                && $s->getOriginal('settlement_status') !== 'paid';
            if ($becomingPaid && ! $s->secondary_status) {
                $s->secondary_status = 'pending';
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

    /**
     * 2026-05-21 정산 공식 재구조 — 엑셀(수출차량매입-2026) 실측 기준.
     *
     * 판매금원화 = (sale_price + commission + auto_loading - tax_dc) × exchange_rate
     *   - 면장(export_declaration_amount)은 매출 검증용. 정산 공식엔 안 들어감.
     *   - 운임비(transport_fee)는 sale_total_amount(미수율 분모)엔 들어가지만 정산엔 제외.
     *   - 엑셀 AH 셀 공식 = SUM(AJ + AM + AN - AO) × AL.
     */
    public function getSalesAmountKrwAttribute(): int
    {
        $v = $this->vehicle;
        if (! $v) {
            return 0;
        }

        $base = (float) ($v->sale_price ?? 0)
            + (float) ($v->commission ?? 0)
            + (float) ($v->auto_loading ?? 0)
            - (float) ($v->tax_dc ?? 0);

        return (int) ($base * (float) ($v->exchange_rate ?? 0));
    }

    public function getSettlementSalesKrwAttribute(): int
    {
        return $this->sales_amount_krw - ($this->vehicle?->cost_total ?? 0);
    }

    /**
     * 판매마진 = 정산판매금원화 - (purchase_price + selling_fee)
     *   - 엑셀 CF = CE - CB. CB = V = SUM(T + U) = 구입금액 + 매도비.
     *   - 매도비 포함이 매입합계 의미.
     */
    public function getSalesMarginAttribute(): int
    {
        $v = $this->vehicle;
        $purchaseTotal = (int) ($v?->purchase_price ?? 0) + (int) ($v?->selling_fee ?? 0);

        return $this->settlement_sales_krw - $purchaseTotal;
    }

    /**
     * 부가세마진 = purchase_price × 0.09
     *   - 엑셀 CG = T × 0.09 (구입금액만, 매도비 제외).
     *   - car-erp purchase_price = 구입금액(매도비 selling_fee 별도) → 변경 불필요.
     */
    public function getVatMarginAttribute(): int
    {
        return (int) (($this->vehicle?->purchase_price ?? 0) * 0.09);
    }

    /**
     * 총마진 = (판매마진 + 부가세마진) × 0.9
     *   - 엑셀 CH = (CF + CG) × 0.9. × 0.9 의 의미: 부가세 10% 차감 (계산 전 부가세 제외).
     *   - 사용자 확정 2026-05-21.
     */
    public function getTotalMarginAttribute(): int
    {
        return (int) (($this->sales_margin + $this->vat_margin) * 0.9);
    }

    // ── Phase 2 (2026-05-21) — Salesman.type 별 default fallback ─────────
    // 5만/10만/50% 은 회사 단일 정책 → 코드 상수. 변경 시 코드 수정 (사용자 결정).
    // 컬럼 값(settlement_ratio, per_unit_amount) 명시 입력되면 user override 우선.
    public const FREELANCE_RATIO_DEFAULT = 50;          // 프리랜서 비율 기본 50%

    public const EMPLOYEE_PER_UNIT_DEFAULT = 100_000;   // 사내직원 건당 10만원

    public const FREELANCE_DOCUMENT_FEE = 50_000;       // 프리랜서 서류비 5만원

    /**
     * 효과적 비율 — settlement_ratio 값 있으면 그대로, NULL 이면 freelance default 50.
     * 어제 결정("재무가 채움") + 오늘 결정("자동 default") 양립 — NULL fallback 패턴.
     */
    public function getEffectiveRatioAttribute(): int
    {
        return $this->settlement_ratio !== null
            ? (int) $this->settlement_ratio
            : self::FREELANCE_RATIO_DEFAULT;
    }

    public function getEffectivePerUnitAmountAttribute(): int
    {
        return $this->per_unit_amount !== null
            ? (int) $this->per_unit_amount
            : self::EMPLOYEE_PER_UNIT_DEFAULT;
    }

    /**
     * 서류비 (프리랜서만) — 엑셀 CJ = SUM(CH/2) - 50000 의 -50000.
     * 사내직원은 0 (서류비 차감 없음).
     */
    public function getDocumentFeeAttribute(): int
    {
        return $this->settlement_type === 'ratio' ? self::FREELANCE_DOCUMENT_FEE : 0;
    }

    /**
     * 정산액 = type 별 분기.
     *   ratio (프리랜서)    = 총마진 × (effective_ratio / 100)
     *   per_unit (사내직원) = effective_per_unit_amount (고정)
     */
    public function getSettlementAmountAttribute(): int
    {
        if ($this->settlement_type === 'ratio') {
            return (int) ($this->total_margin * ($this->effective_ratio / 100));
        }

        return $this->effective_per_unit_amount;
    }

    /**
     * 실지급액 = 정산액 − 서류비 − 기타공제 (+ 환차, 2차 정산 closed + 프리랜서일 때).
     *   - 엑셀 CM = CJ − CL. CJ = CH/2 − 50,000 (서류비 박혀있음).
     *   - 사내직원은 서류비 0 → per_unit − other_deduction.
     *   - 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 closed + 프리랜서(ratio) 시 환차 1:1 반영.
     *     사용자 결정: 사내직원(per_unit)은 고정액이라 환차 미반영 (회사가 환차익·환차손 부담).
     */
    public function getActualPayoutAttribute(): int
    {
        $base = $this->settlement_amount - $this->document_fee - (int) ($this->other_deduction ?? 0);

        if ($this->settlement_type === 'ratio'
            && $this->secondary_status === 'closed'
            && $this->exchange_difference_krw !== null) {
            $base += (int) $this->exchange_difference_krw;
        }

        return $base;
    }
}
