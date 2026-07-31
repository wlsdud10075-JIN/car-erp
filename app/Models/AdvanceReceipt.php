<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 가수금 — 대표·관계사가 회사에 넣은 돈 (jin 2026-07-27, 안건4 1단계).
 * 회수(반제)하면 행을 삭제한다 → 목록 합계 = 현재 잔액. softDelete 라 이력은 DB 에 남는다.
 */
class AdvanceReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'received_date', 'company_name', 'person_name', 'amount', 'nature', 'note', 'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
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

    /** 전체 잔액(원) — 목록 합계용. 성격 무관. */
    public static function totalKrw(): int
    {
        return (int) static::sum('amount');
    }

    /** 갚아야 할 돈만 — **청산가치에서 차감하는 값**. */
    public static function liabilityKrw(): int
    {
        return (int) static::where('nature', self::NATURE_LIABILITY)->sum('amount');
    }

    /** 대표 자산성만 — 참고 표시용(청산가치에서 빼지 않는다). */
    public static function equityKrw(): int
    {
        return (int) static::where('nature', self::NATURE_EQUITY)->sum('amount');
    }
}
