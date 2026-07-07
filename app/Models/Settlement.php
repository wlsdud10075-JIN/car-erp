<?php

namespace App\Models;

use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Settlement extends Model
{
    // Review2 항목 B (2026-06-09) — soft delete (복구 가능). deleting 가드는 confirmed/paid/closed 차단,
    // 데모·임포트 정리는 forceDelete() 사용 → 영향 없음.
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'salesman_id', 'settlement_type', 'settlement_ratio',
        'per_unit_amount', 'other_deduction', 'settlement_status',
        'secondary_status', 'secondary_closed_at',
        // 회의확장씬 #7 Step C-4 (2026-05-22) — 2차 정산 시 환차 (현재 환율 vs 입금 시점 평균)
        'exchange_difference_krw',
        // 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 시 환율 (수동 입력 또는 자동 fetch 저장, audit trail)
        'exchange_rate_at_close',
        // 새회의 #8 보강 (2026-05-23) — 정산 캐리오버 (영업담당자별 이월)
        'carryover_in_krw', 'carryover_out_krw',
        'confirmed_at', 'paid_at', 'confirmed_snapshot', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'secondary_closed_at' => 'datetime',
        'exchange_difference_krw' => 'decimal:2',
        'exchange_rate_at_close' => 'decimal:4',
        'carryover_in_krw' => 'decimal:2',
        'carryover_out_krw' => 'decimal:2',
        'confirmed_snapshot' => 'array',
    ];

    /**
     * 큐 11-4 G7 — 감사 로그 추적 컬럼 (Settlement 기준).
     * 회의확장씬 #8 (2026-05-22) — secondary_status 추가.
     * Review2 항목 A (2026-06-09) — 정산 금액 파라미터 변경 추적.
     *   사내직원 차등정산(10/20/25%)을 수동 운용하는 게 의도된 정책이라 변경 자체는 허용(잠그지 않음).
     *   대신 paid 이후 ratio/per_unit/other_deduction 수동 조정을 감사로그에 남겨 추적성 확보.
     */
    public const AUDITED_COLUMNS = [
        'settlement_status', 'secondary_status', 'paid_at',
        'settlement_ratio', 'per_unit_amount', 'other_deduction',
    ];

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
        // 새회의 #8 보강 (2026-05-23) — 신규 정산 creating 시 영업담당자 미적용 이월 흡수.
        // 사용자 정책: 영업담당자별 이월 / 2차 closed 시점 트리거 / 음수 이월 허용 (차감).
        // unconsumed = Σ(영업담당자 closed settlement.carryover_out_krw)
        //            - Σ(영업담당자 settlement.carryover_in_krw)  (자기 자신 제외)
        // 명시적으로 set 된 경우 (테스트·migrate 등) 우회.
        static::creating(function (Settlement $s) {
            if ($s->carryover_in_krw !== null || ! $s->salesman_id) {
                return;
            }
            $totalOut = (float) self::where('salesman_id', $s->salesman_id)
                ->where('secondary_status', 'closed')
                ->whereNotNull('carryover_out_krw')
                ->sum('carryover_out_krw');
            $totalIn = (float) self::where('salesman_id', $s->salesman_id)
                ->whereNotNull('carryover_in_krw')
                ->sum('carryover_in_krw');
            // 퇴사자 청산분(CarryoverClearance)도 차감 — 청산 후 새 정산이 재흡수(이중계상)하지 않도록.
            // Salesman::unconsumed_carryover accessor 와 동일 공식 유지(단일 출처).
            $totalCleared = (float) CarryoverClearance::where('salesman_id', $s->salesman_id)->sum('amount_krw');
            $unconsumed = $totalOut - $totalIn - $totalCleared;
            if (abs($unconsumed) >= 0.01) {
                $s->carryover_in_krw = $unconsumed;
            }
        });

        // 정산 확정 대기 알림톡 (erp_settle_pending) — 신규 pending 정산 생성 시 관리에게 "확정 대기 N건".
        //   거래완료 Vehicle::saved 훅이 자동 생성 → afterCommit(롤백 시 미발송) + fire-and-forget(서비스가 예외 흡수).
        static::created(function (Settlement $s) {
            if ($s->settlement_status !== 'pending') {
                return;
            }
            DB::afterCommit(function () {
                try {
                    $count = self::where('settlement_status', 'pending')->count();
                    $svc = BizmAlimtalkService::active();
                    foreach (AlimtalkRecipients::managers() as $phone) {
                        $svc->send('erp_settle_pending', $phone, ['건수' => number_format($count)]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('alimtalk settle_pending 실패', ['error' => $e->getMessage()]);
                }
            });
        });

        // Review.md #1 (2026-06-09) — 회계 잠금 정산의 무가드 삭제 차단.
        // confirmed/paid/closed 정산을 삭제하면 FP·PBP 의 소급 잠금이 풀리고
        // confirmed_snapshot·감사추적이 영구 소멸 → 삭제는 pending/calculating 만 허용.
        // (2026-05-21 사용자 결정 "삭제 등 파괴적 액션만 별도 차단" 의 미반영분 보강.)
        // 시드·artisan(auth 없음)은 데이터 정리 위해 우회.
        static::deleting(function (Settlement $s) {
            if (! auth()->check()) {
                return;
            }
            if (in_array($s->settlement_status, ['confirmed', 'paid'], true)
                || $s->secondary_status === 'closed') {
                throw new \DomainException(__('settlement.notify.delete_locked'));
            }
        });

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
                // ratio = 비율>0 필요. per_unit = 차등 tier 자동 산정이라 항상 값 결정됨
                // (총마진 음수 직원 → 0 도 유효한 정산). per_unit_amount 명시 override 시도 그대로 통과.
                $hasRatio = $s->settlement_type === 'ratio'
                    && ($s->settlement_ratio !== null ? (float) $s->settlement_ratio > 0 : self::param('settlement_freelance_ratio') > 0);
                $hasPerUnit = $s->settlement_type === 'per_unit';
                if (! $hasRatio && ! $hasPerUnit) {
                    throw ValidationException::withMessages([
                        'settlement_ratio' => '정산 확정·지급 시 정산비율(ratio) 또는 건당 정산액(per_unit) 중 하나가 설정되어야 합니다.',
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

            // paid 전환 시 정산 파라미터 동결(materialize, 2026-06-22) — 이후 Setting 변경·tier 재계산으로부터
            // 확정 정산 금액을 보호. 특히 사내직원 per_unit 을 고정해 carry_out=0 불변식(SKILLS §5-5) 유지
            //   (tier 는 total_margin 의존이라, 미고정 시 2차 비용보정으로 closed actual_payout ≠ paid snapshot).
            $becamePaid = $s->settlement_status === 'paid'
                && $s->getOriginal('settlement_status') !== 'paid';
            if ($becamePaid) {
                if ($s->settlement_type === 'ratio' && $s->settlement_ratio === null) {
                    $s->settlement_ratio = $s->effective_ratio;
                }
                if ($s->settlement_type === 'per_unit' && $s->per_unit_amount === null) {
                    $s->per_unit_amount = $s->effective_per_unit_amount;
                }
            }

            // H4 — status가 paid로 전환되는 시점에 vehicle 회계 컬럼 + 마진을 snapshot 캡처.
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

    // ── 정산 파라미터 (2026-06-22) — super admin 기능설정에서 Setting override 가능 ─────────
    // 상수 = 기본값(Setting row 없으면 사용). 컬럼 값(settlement_ratio, per_unit_amount) 명시 시 user override 우선.
    public const FREELANCE_RATIO_DEFAULT = 50;          // 프리랜서 비율 기본 50%

    public const EMPLOYEE_PER_UNIT_DEFAULT = 100_000;   // 사내직원 건당(총마진 기준 미만) 10만원

    public const FREELANCE_DOCUMENT_FEE = 50_000;       // 프리랜서 서류비 5만원

    public const EMPLOYEE_HIGH_THRESHOLD = 100_000_000; // 사내직원 고율 트리거 = 매입합계(구입금액+매도비) ≥ 1억 (엑셀 BX)

    public const EMPLOYEE_HIGH_RATE = 25;               // 사내직원 고율 % (총마진 × 25%)

    public const EMPLOYEE_MARGIN_THRESHOLD = 1_000_000; // 사내직원 건당 분기 = 총마진 100만

    public const EMPLOYEE_AMOUNT_HIGH = 200_000;        // 사내직원 건당(총마진 기준 이상) 20만원

    /** Setting key ↔ 기본 상수 매핑 (super admin 기능설정 입력 대상). */
    public const PARAM_DEFAULTS = [
        'settlement_freelance_ratio' => self::FREELANCE_RATIO_DEFAULT,
        'settlement_freelance_document_fee' => self::FREELANCE_DOCUMENT_FEE,
        'settlement_employee_high_threshold' => self::EMPLOYEE_HIGH_THRESHOLD,
        'settlement_employee_high_rate' => self::EMPLOYEE_HIGH_RATE,
        'settlement_employee_margin_threshold' => self::EMPLOYEE_MARGIN_THRESHOLD,
        'settlement_employee_amount_low' => self::EMPLOYEE_PER_UNIT_DEFAULT,
        'settlement_employee_amount_high' => self::EMPLOYEE_AMOUNT_HIGH,
    ];

    /** @var array<string,int> 요청 단위 메모 (정산 목록에서 per-row Setting 쿼리 폭주 방지) */
    private static array $paramMemo = [];

    /** 정산 파라미터 읽기 — Setting override 있으면 그 값, 없으면 기본 상수. 요청 단위 캐시. */
    public static function param(string $key): int
    {
        if (! array_key_exists($key, self::$paramMemo)) {
            self::$paramMemo[$key] = (int) Setting::get($key, self::PARAM_DEFAULTS[$key] ?? 0);
        }

        return self::$paramMemo[$key];
    }

    /** 설정 변경 후(또는 테스트) 메모 초기화. */
    public static function flushParamMemo(): void
    {
        self::$paramMemo = [];
    }

    /**
     * 효과적 비율 — settlement_ratio 값 있으면 그대로, NULL 이면 freelance default 50.
     * 어제 결정("재무가 채움") + 오늘 결정("자동 default") 양립 — NULL fallback 패턴.
     */
    public function getEffectiveRatioAttribute(): int
    {
        return $this->settlement_ratio !== null
            ? (int) $this->settlement_ratio
            : self::param('settlement_freelance_ratio');
    }

    public function getEffectivePerUnitAmountAttribute(): int
    {
        // 재무가 per_unit_amount 명시 입력 시 그 값 override 우선.
        if ($this->per_unit_amount !== null) {
            return (int) $this->per_unit_amount;
        }

        // NULL = 자동 차등 tier (2026-06-22 jin 확정). 매입합계(구입금액+매도비)·총마진 기준 (엑셀 BX=R열).
        return self::employeePerUnitTier(
            $this->total_margin,
            (int) ($this->vehicle->purchase_price ?? 0) + (int) ($this->vehicle->selling_fee ?? 0)
        );
    }

    /**
     * 사내직원(per_unit) 차등 정산액 — 2026-06-22 jin 확정 (엑셀 CF).
     *
     *   매입합계(구입금액+매도비) ≥ 1억 → 총마진 × 25%   (1억 트리거 최우선, 단 음수면 0 바닥 — jin 2026-06-22)
     *   총마진 < 0                       → 0
     *   총마진 < 100만                   → 100,000
     *   그 외(총마진 ≥ 100만)            → 200,000        (상한 없음, 100만 정확히=20만)
     *
     * 엑셀 CF = IF(BX>=1억, CD*0.25, IF(CD<0,0, IF(CD<100만,10만, 20만))). BX=매입합계(R열=구입금액+매도비, jin 2026-07-07).
     * 우리 보정: 1억+ 손해차량은 0 바닥(jin), CD≥1000만(엑셀 else 누락)은 20만.
     */
    public static function employeePerUnitTier(int $totalMargin, int $purchaseTotal): int
    {
        if ($purchaseTotal >= self::param('settlement_employee_high_threshold')) {
            return max(0, (int) ($totalMargin * self::param('settlement_employee_high_rate') / 100));
        }
        if ($totalMargin < 0) {
            return 0;
        }
        if ($totalMargin < self::param('settlement_employee_margin_threshold')) {
            return self::param('settlement_employee_amount_low');
        }

        return self::param('settlement_employee_amount_high');
    }

    /**
     * 서류비 (프리랜서만) — 엑셀 CJ = SUM(CH/2) - 50000 의 -50000.
     * 사내직원은 0 (서류비 차감 없음).
     */
    public function getDocumentFeeAttribute(): int
    {
        return $this->settlement_type === 'ratio' ? self::param('settlement_freelance_document_fee') : 0;
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
     * 실지급액 = 정산액 − 서류비 − 기타공제 (+ 환차, 2차 정산 closed + 프리랜서일 때) + 전월 이월액.
     *   - 엑셀 CM = CJ − CL. CJ = CH/2 − 50,000 (서류비 박혀있음).
     *   - 사내직원은 서류비 0 → per_unit − other_deduction.
     *   - 회의확장씬 #6+7 보강 (2026-05-23) — 2차 정산 closed + 프리랜서(ratio) 시 환차 1:1 반영.
     *     사용자 결정: 사내직원(per_unit)은 고정액이라 환차 미반영 (회사가 환차익·환차손 부담).
     *   - 새회의 #8 보강 (2026-05-23) — 영업담당자별 캐리오버. creating 훅이 이전 정산의 미적용
     *     carryover_out_krw 합산 → carryover_in_krw set. 본 accessor 가 +로 반영 (음수면 차감).
     */
    public function getActualPayoutAttribute(): int
    {
        $base = $this->settlement_amount - $this->document_fee - (int) ($this->other_deduction ?? 0);

        if ($this->settlement_type === 'ratio'
            && $this->secondary_status === 'closed'
            && $this->exchange_difference_krw !== null) {
            $base += (int) $this->exchange_difference_krw;
        }

        // 캐리오버 — 전월 이월액 가산 (양수 환차익 누적 / 음수 환차손 차감)
        if ($this->carryover_in_krw !== null) {
            $base += (int) $this->carryover_in_krw;
        }

        return $base;
    }
}
