<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Lang;

class Salesman extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'initials', 'employee_no', 'phone', 'email', 'memo', 'is_active',   // employee_no = 급여대장 사원번호(2026-10-14)
        // 🚪 퇴사일 (jin 2026-10-15) — 있으면 퇴사자. 프리랜서는 정산관리 「퇴사자」에서 즉시 정산 배치, 사내직원은 종전대로 월정산.
        //    미러(사용자 관리 저장)는 퇴사일이 있으면 is_active 를 되살리지 않는다. 마이그레이션 2026_10_15_000001 주석 참조.
        'left_at',
        // 2026-05-20 #2-2+2-4 — type 분기 (employee 건당 / freelance 비율)
        'type',
        // 2026-08-04 jin — 사내직원 차등정산(tier) 담당자별 on/off. OFF=10만원 고정.
        'per_unit_tier_enabled',
        // 2026-09-16 jin — 정산 지급 대상이 아닌 담당자(자매 회사 계정 등). 상세 = 마이그레이션 주석.
        'payout_excluded',
        // 2026-09-18 jin — 예치금(프리랜서) · 기본급(사내직원). **표시 전용**, 지급액 불참.
        //   null = 미입력(화면 「−」) / 0 = 「없음」 명시. 상세 = 마이그레이션 주석.
        'deposit_krw', 'base_salary_krw',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'left_at' => 'date',
        'per_unit_tier_enabled' => 'boolean',
        'payout_excluded' => 'boolean',
        'deposit_krw' => 'integer',
        'base_salary_krw' => 'integer',
    ];

    public const TYPES = [
        'employee' => '사내직원',
        'freelance' => '프리랜서',
        // 월정산 v3 (jin 2026-10-08) — 검차직원: 계정 없이 이름만 등록, 정산·판매 없음, 급여 지급합계만 월정산에.
        //   🚨 DB enum 과 같은 커밋(마이그 2026_10_09_000002) — 가드 SalesmanTypeEnumTest.
        'inspector' => '검차직원',
        // 직원 명부 확장 (jin 2026-10-14) — 영업이 아닌 일반사용자(관리·수출통관·재무)의 미러. 급여만(검차직원과 같은 취급).
        //   라벨은 연결 계정의 role(관리/수출통관/재무)로 보여 준다(`type_label`). 🚨 DB enum 마이그 2026_10_14_000001 과 같은 커밋.
        'staff' => '직원',
    ];

    /** 영업을 하는 유형 — 차량을 맡고 정산을 받는다. */
    public const SALES_TYPES = ['employee', 'freelance'];

    /** 급여만 받는 유형 — 정산·회사 기여 없음, 월정산엔 지급합계만(공통 인건비). 검차직원·직원. */
    public const PAYROLL_ONLY_TYPES = ['inspector', 'staff'];

    /**
     * 영업을 하는 사람만(검차직원·직원 제외) — 차량 담당자 드롭다운·필터·대시보드 랭킹·board 이메일 매칭은 이 스코프를 쓴다.
     * 🔑 부정 조건(`!= inspector`)이 아니라 **포함 목록**이다 — 다음 유형이 또 생겨도 새지 않는다.
     */
    public function scopeSales($query)
    {
        return $query->whereIn('type', self::SALES_TYPES);
    }

    public function isSales(): bool
    {
        return in_array($this->type, self::SALES_TYPES, true);
    }

    public function isInspector(): bool
    {
        return $this->type === 'inspector';
    }

    /** 급여만 받는 사람인가(검차직원·직원) — 월정산 카드·합계 띠·대시보드 공통 인건비가 이걸로 가른다. */
    public function isPayrollOnly(): bool
    {
        return in_array($this->type, self::PAYROLL_ONLY_TYPES, true);
    }

    // ── 🚪 퇴사 (jin 2026-10-15 「퇴사자 사용 씬」) ──────────────────────────────────────────

    /** 퇴사자인가 — 퇴사일이 적혀 있으면. 활성 체크(`is_active`)와 별개의 칸이다(미러가 활성을 되살려도 퇴사는 남는다). */
    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    /**
     * 🔑 **퇴사하면 즉시 정산하는 사람인가 = 프리랜서 퇴사자** (jin 2026-10-15 «사내직원은 똑같이 10일에 지급, 프리랜서만 별도»).
     *
     * 이 사람의 미지급 정산·미청산 이월은 **월정산 후보에서 빠지고** 정산관리 「퇴사자」의 즉시 정산 배치(`kind=leaver`)로만 나간다.
     * 두 배치가 같은 정산을 가져가는 일은 `settlements.payout_batch_id` 가 막지만, 후보에서도 서로 빼야 「월정산에 섞여 10일에 나가는」
     * 반대 순서가 안 생긴다. 판정은 여기 한 곳 — `Settlement::isReservedForLeaverBatch()`·`scopeImmediateLeavers()`·월정산 이월 줄이 같이 쓴다.
     * 사내직원·급여형 퇴사자는 false — 종전대로 월정산(마지막 달 급여 줄 포함).
     */
    public function settlesImmediatelyOnLeave(): bool
    {
        return $this->hasLeft() && $this->type === 'freelance';
    }

    /** SQL 짝 — 즉시 정산 대상 퇴사자(프리랜서 + 퇴사일). `settlesImmediatelyOnLeave()` 와 글자 단위로 같은 조건. */
    public function scopeImmediateLeavers($query)
    {
        return $query->whereNotNull('left_at')->where('type', 'freelance');
    }

    /** 퇴사 알림톡 라벨 — 승인요청 카드 「제출」 칸(20자 자동컷)에 들어간다. 본문은 BizM 등록본 그대로라 변수 값으로만 알린다. */
    public function leaverTag(): string
    {
        return '퇴사정산 '.$this->name;
    }

    /**
     * 계정 → 명부 유형 (단일 출처, 2026-10-14). 사용자 관리 저장과 백필(`salesmen:sync-staff`)이 같은 판정을 쓴다.
     *  - 영업 → 정산 분류(employee/freelance) 그대로(권한 무관 — 종전 동작)
     *  - 검차(ROLES 밖) → inspector
     *  - 일반사용자 ∧ 관리·수출통관·재무 → staff(급여만)
     *  - 그 외(관리자 등급의 비영업) → null = 미러 안 함(기존 행은 비활성화)
     */
    public static function mirrorTypeFor(User $user, ?string $salesType = null): ?string
    {
        return match (true) {
            $user->role === '영업' => $salesType ?: ($user->type ?: 'employee'),
            $user->role === User::INSPECTOR_ROLE => 'inspector',
            $user->permission === 'user' && in_array($user->role, User::STAFF_ROLES, true) => 'staff',
            default => null,
        };
    }

    /** 유형 뱃지 색 — 목록·월정산 카드가 같은 표를 쓴다. */
    public static function typeBadgeClass(?string $type): string
    {
        return ['employee' => 'badge-blue', 'freelance' => 'badge-purple', 'inspector' => 'badge-amber', 'staff' => 'badge-teal'][$type] ?? 'badge-gray';
    }

    /** 정산 type 자동 매핑: employee → per_unit, freelance → ratio. */
    public function defaultSettlementType(): string
    {
        return $this->type === 'freelance' ? 'ratio' : 'per_unit';
    }

    public function getTypeLabelAttribute(): string
    {
        // 직원(staff)은 연결 계정의 역할(관리/수출통관/재무)이 곧 유형이다 — 역할이 바뀌면 따라온다.
        if ($this->type === 'staff') {
            $role = $this->user?->role;

            return $role ? __('domain.role.'.$role) : __('salesman.type.staff');
        }

        $key = 'salesman.type.'.$this->type;

        return Lang::has($key) ? __($key) : (self::TYPES[$this->type] ?? '사내직원');
    }

    /**
     * 💰 **그 달에 이 사람이 실제로 받는 돈** = 기본급 + 그 달 정산액 (jin 2026-09-18).
     *
     * 🚨 이름을 「실지급액」으로 쓰지 말 것 — ERP 에서 **실지급액 = `Settlement::actual_payout`**
     *    (정산액 − 서류비 − 발송비 − 기타공제)으로 정산관리·월정산·엑셀 3곳이 이미 쓴다.
     *    같은 낱말이 화면마다 다른 숫자를 가리키면 «정산관리는 180만인데 월정산은 454만» 이 된다.
     *    ⇒ 이 합계의 이름은 **「월수령액」**이다(jin 확정).
     *
     * 🚫 배치 총액·회사이익에 더하지 않는다 — 급여는 정산이 아니다.
     * 프리랜서는 기본급이 없으므로 정산액 그대로 돌려준다.
     */
    public function monthlyTakeHome(int $settlementPayout): int
    {
        return (int) ($this->base_salary_krw ?? 0) + $settlementPayout;
    }

    /**
     * 💴 **이달 월급이 나가는 사람** — 재직 중이고 지급 대상이며 기본급이 0 보다 큰 담당자 (jin 2026-10-06).
     *
     * jin: *「정산이 0명인 사람은 월급만 나올 수 있게 변경이 되어야 해」* — 그 달 정산 건이 없어도
     * 월정산·승인화면에 「기본급만」 줄로 올라와 「이달 송금 예상」에 들어간다.
     *
     * 🔑 **세 소비자(월정산 드릴다운 · 승인 breakdown · `baseSalaryTotal`)가 전부 이 스코프를 쓴다** —
     *    조건을 옮겨 적으면 «승인화면엔 있는데 월정산엔 없다»가 된다(§8 #44).
     * - `base_salary_krw > 0` — **0 은 「없음」을 명시한 값**이라 줄을 만들지 않는다(`SalesmanDepositBaseSalaryTest`).
     * - `payout_excluded` 는 09-16 의 신분 기준 제외(자매 회사 계정) — 금액이 아니라 신분으로 뺀다(§8 #103).
     * - 퇴사(`is_active=false`)·삭제(SoftDeletes)는 빠진다. 프리랜서 예치금은 **대상 아님**(jin 요청 범위 = 월급).
     */
    public function scopeSalariedForBatch($query)
    {
        return $query->where('is_active', true)
            ->where('payout_excluded', false)
            ->where('base_salary_krw', '>', 0);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    /** 💴 급여 항목(귀속월별) — 월정산 v3. 합계는 PayrollEntry::totalFor(). */
    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function carryoverClearances(): HasMany
    {
        return $this->hasMany(CarryoverClearance::class);
    }

    /**
     * 미청산 이월 잔액 (KRW) — Σ closed 정산의 carryover_out − Σ carryover_in − Σ 청산액.
     * Settlement::creating 흡수 훅(SKILLS §5-5)과 동일 공식 = 단일 출처.
     * 활성 담당자는 다음 정산이 흡수해 보통 0, 마지막/퇴사 건이면 stranded 잔액으로 남음.
     * 퇴사자 청산(CarryoverClearance) 시 Σ청산액 차감으로 0 → 흡수 훅도 같이 차감해 재흡수(이중계상) 방지.
     * 양수 = 담당자에게 지급 대기 / 음수 = 담당자에게 청구 대상.
     */
    public function getUnconsumedCarryoverAttribute(): int
    {
        $out = (float) $this->settlements()
            ->where('secondary_status', 'closed')
            ->whereNotNull('carryover_out_krw')
            ->sum('carryover_out_krw');
        $in = (float) $this->settlements()
            ->whereNotNull('carryover_in_krw')
            ->sum('carryover_in_krw');
        $cleared = (float) $this->carryoverClearances()->sum('amount_krw');

        return (int) round($out - $in - $cleared);
    }
}
