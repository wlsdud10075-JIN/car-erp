<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 가수금 — 대표·관계사가 회사에 넣은 돈 (jin 2026-07-27, 안건4 1단계).
 *
 * 🔁 **상환은 삭제가 아니라 상태다** (jin 2026-08-12). 종전엔 반제 = 행 삭제였는데,
 *    숫자는 맞아도 **갚은 이력이 화면에서 사라져** "얼마를 갚아왔나" 를 볼 수 없었다.
 *    이제 `repaid_at` 을 찍어 목록에 남기고 **집계에서만 뺀다**(`outstanding` 스코프).
 *    [삭제]는 이제 "잘못 입력한 행 지우기" 전용이다.
 */
class AdvanceReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'received_date', 'company_name', 'person_name', 'amount', 'nature', 'note', 'created_by',
        'repaid_at', 'repaid_by',
    ];

    protected $casts = [
        'received_date' => 'date',
        'repaid_at' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * 성격 구분 (jin 2026-07-31) — 청산가치에서 뺄 돈인지 아닌지.
     *   liability = 갚아야 할 돈 (예: 김진숙차입) → 청산가치에서 차감
     *   equity    = 대표 본인 돈 (예: 대표이사 가수금·싼카대여) → 차감하지 않음
     * ⚠️ 기본값 liability — 현행 계산과 같아야 배포 시 값이 안 흔들린다.
     */
    public const NATURE_LIABILITY = 'liability';

    public const NATURE_EQUITY = 'equity';

    public const NATURES = [
        self::NATURE_LIABILITY => '갚아야 할 돈',
        self::NATURE_EQUITY => '대표 자산',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaid_by');
    }

    /**
     * 🔑 **아직 안 갚은 것만** — 모든 금액 집계의 단일 출처.
     *
     * 상환분을 세면 갚았는데도 청산가치에서 계속 차감되거나(liability) 원금이 부풀려진다(equity).
     * 새 집계를 만들 때 **`where('nature', …)` 만 쓰지 말고 반드시 이 스코프를 거칠 것.**
     */
    public function scopeOutstanding($query)
    {
        return $query->whereNull('repaid_at');
    }

    public function scopeRepaid($query)
    {
        return $query->whereNotNull('repaid_at');
    }

    /** 상환 가능한가 — **「갚아야 할 돈」만**(jin 2026-08-12). 대표 자산 회수는 종전대로 삭제로 정리. */
    public function canRepay(): bool
    {
        return $this->nature === self::NATURE_LIABILITY && $this->repaid_at === null;
    }

    /** 미상환 잔액(원) — 목록 합계용. 성격 무관. */
    public static function totalKrw(): int
    {
        return (int) static::outstanding()->sum('amount');
    }

    /** 갚아야 할 돈만 — **청산가치에서 차감하는 값**. 상환분 제외. */
    public static function liabilityKrw(): int
    {
        return (int) static::outstanding()->where('nature', self::NATURE_LIABILITY)->sum('amount');
    }

    /** 대표 자산성만 — 원금에 가산되는 값. 상환분 제외. */
    public static function equityKrw(): int
    {
        return (int) static::outstanding()->where('nature', self::NATURE_EQUITY)->sum('amount');
    }

    /** 지금까지 갚은 누계 — **표시 전용**(청산가치·원금 어디에도 안 들어간다). */
    public static function repaidKrw(): int
    {
        return (int) static::repaid()->sum('amount');
    }
}
