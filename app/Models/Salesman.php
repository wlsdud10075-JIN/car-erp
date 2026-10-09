<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Salesman extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'initials', 'phone', 'email', 'memo', 'is_active',
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
    ];

    /** 영업을 하는 사람만(검차직원 제외) — 차량 담당자 드롭다운·필터·대시보드 랭킹은 이 스코프를 쓴다. */
    public function scopeSales($query)
    {
        return $query->where('type', '!=', 'inspector');
    }

    public function isInspector(): bool
    {
        return $this->type === 'inspector';
    }

    /** 정산 type 자동 매핑: employee → per_unit, freelance → ratio. */
    public function defaultSettlementType(): string
    {
        return $this->type === 'freelance' ? 'ratio' : 'per_unit';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? '사내직원';
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
