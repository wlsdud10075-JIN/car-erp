<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * 💴 급여 항목 — 사내직원·검차직원, **귀속월별** (월정산 v3, jin 2026-10-08).
 *
 * 고정 18 항목(ITEMS, 순서 고정) + 직접 추가 행(is_custom). 매달 빈칸에서 시작하고 재무가 기입한다.
 * - 행 없음 = 미입력 · amount 0 = 「없음」 명시. 금액은 부호 있음(전월소급 등).
 * - 지급합계 = 그 달 행의 Σamount. **실지급액 = 지급합계 + 정산금 + 추가 인센티브**(사내직원) / 검차직원은 지급합계만.
 * - 저장은 `replaceFor()` 한 곳 — (salesman, month) 를 지우고 다시 넣는다(한 트랜잭션).
 * 🚫 `salesmen.base_salary_krw` 는 v3 배포 뒤 비운다(10/10 월정산 「기본급만」 줄이 아직 읽는다).
 */
class PayrollEntry extends Model
{
    protected $fillable = ['salesman_id', 'month', 'label', 'amount', 'sort', 'is_custom'];

    protected $casts = [
        'amount' => 'integer',
        'sort' => 'integer',
        'is_custom' => 'boolean',
    ];

    /** 고정 항목 — jin 2026-10-08 전달 순서 그대로. 화면·저장·월정산 펼침이 전부 이 순서를 쓴다. */
    public const ITEMS = [
        '기본급', '상여', '식대', '자가운전', '육아수당', '연장근로', '복리', '기타수당', '전월소급',
        '특별상여', '연차수당', '업무분담지원금', '경력수당', '판매수당', '직책수당', '영업지원', '헤이딜러', 'ERP이용수당',
    ];

    /** 직접 추가 행의 sort 시작값 — 고정 항목 뒤에 온다. */
    public const CUSTOM_SORT_BASE = 100;

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function scopeForMonth($query, string $month)
    {
        return $query->where('month', $month);
    }

    /** 'YYYY-MM' 형식 검사 — 화면·명령이 같은 판정을 쓴다. */
    public static function isMonth(string $month): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }

    /**
     * 그 달 급여를 통째로 교체한다.
     *
     * @param  array<int, array{label:string, amount:int|null, is_custom?:bool}>  $rows  순서 = 화면 순서.
     *                                                                                   amount null·'' = 미입력 → 행을 만들지 않는다.
     * @return int 지급합계
     */
    public static function replaceFor(int $salesmanId, string $month, array $rows): int
    {
        if (! self::isMonth($month)) {
            throw new \InvalidArgumentException("귀속월 형식이 아닙니다: {$month}");
        }

        return DB::transaction(function () use ($salesmanId, $month, $rows) {
            self::where('salesman_id', $salesmanId)->where('month', $month)->delete();
            $total = 0;
            $customSort = self::CUSTOM_SORT_BASE;
            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $amount = $row['amount'] ?? null;
                if ($label === '' || $amount === null || $amount === '') {
                    continue;
                }
                $isCustom = (bool) ($row['is_custom'] ?? ! in_array($label, self::ITEMS, true));
                $sort = $isCustom ? $customSort++ : (int) array_search($label, self::ITEMS, true);
                self::create([
                    'salesman_id' => $salesmanId, 'month' => $month, 'label' => mb_substr($label, 0, 40),
                    'amount' => (int) $amount, 'sort' => $sort, 'is_custom' => $isCustom,
                ]);
                $total += (int) $amount;
            }

            return $total;
        });
    }

    /** 그 달 지급합계 — 행이 하나도 없으면 null(미입력). 0 은 「없음」을 적은 것. */
    public static function totalFor(int $salesmanId, string $month): ?int
    {
        $q = self::where('salesman_id', $salesmanId)->where('month', $month);

        return $q->exists() ? (int) $q->sum('amount') : null;
    }
}
