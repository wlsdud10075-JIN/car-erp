<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 내수직원 평가수수료 표 한 줄 (jin 2026-11-03) — 「평가수수료정산」 엑셀 딜러수수료 탭의 평가사 행.
 *
 * 월정산 카드는 이 행의 항목(headers × values)을 그리고, 송금액은 `payout`(총 평가사수수료)이다.
 * 송금액은 같은 달 PayrollEntry(라벨 PAYROLL_LABEL)로도 들어가 합계·송금 총액·회사이익은 검차직원과 같은 경로를 탄다.
 */
class DomesticFeeStatement extends Model
{
    /** 월정산 급여 줄 라벨 — 업로드가 그 달 이 사람 급여를 이 한 줄로 바꾼다. */
    public const PAYROLL_LABEL = '총 평가사수수료';

    protected $fillable = ['salesman_id', 'month', 'headers', 'values', 'payout', 'source_file'];

    protected $casts = [
        'headers' => 'array',
        'values' => 'array',
        'payout' => 'integer',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /** 카드에 그릴 [['label' => …, 'value' => …], …] — 헤더와 값 개수가 다르면 짧은 쪽에 맞춘다. */
    public function items(): array
    {
        $out = [];
        foreach (array_values($this->headers ?? []) as $i => $label) {
            $out[] = ['label' => (string) $label, 'value' => $this->values[$i] ?? null];
        }

        return $out;
    }
}
