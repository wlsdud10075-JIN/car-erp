<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 엑셀 CK("비고") 정산 배치 → 정산 귀속월(일한 月) created_at 변환.
 *
 * 급여 모델(jin 2026-06-24): 1일~말일 일한 것 → 다음달 10일 지급.
 *   따라서 일한 月 = 지급 月 − 1. 정산 월별 드롭다운(created_at 기준)이 일한 月로 묶이게 한다.
 *
 * CK 형식 2종 (실측):
 *   "26.05.10정산" → 2026-05-10 지급  → 일한 月 2026-04 → created_at 2026-04-15
 *   "6월 정산"     → {연도}-06-10 지급 → 일한 月 {연도}-05 → created_at {연도}-05-15
 *
 * @see project_settlement_payroll_batch 메모리 / RecreateSettlementsFromCk
 */
class SettlementCkBatch
{
    /** CK 문자열이 '정산 배치'인지 (엑셀 CG = IF(COUNTA(CK)) 와 동일 판정). */
    public static function isSettled(string $ck): bool
    {
        $ck = trim($ck);

        return $ck !== '' && str_contains($ck, '정산') && ! str_contains($ck, '미정산');
    }

    /** CK 배치 → 정산 귀속월(일한 月) 15일 created_at. 파싱 불가 시 null. */
    public static function workCreatedAt(string $ck, int $fileYear): ?Carbon
    {
        if (! self::isSettled($ck)) {
            return null;
        }

        // "26.05.10정산" — 2자리연도.월.일
        if (preg_match('/(\d{2})\.(\d{1,2})\.(\d{1,2})/', $ck, $m)) {
            $paid = Carbon::create(2000 + (int) $m[1], (int) $m[2], (int) $m[3], 10, 0);
            // "6월 정산" — 지급月만 (연도=파일연도, 지급일=10 가정)
        } elseif (preg_match('/(\d{1,2})\s*월/u', $ck, $m)) {
            $paid = Carbon::create($fileYear, (int) $m[1], 10, 10, 0);
        } else {
            return null;
        }

        if (! $paid) {
            return null;
        }

        // 일한 月 = 지급 月 − 1, 그 달 15일 10시.
        return $paid->copy()->subMonthNoOverflow()->day(15)->setTime(10, 0);
    }

    /** 시트명에서 4자리 연도 추출 (예: '수출차량매입-2026' → 2026). 없으면 fallback. */
    public static function yearFromSheet(string $sheet, int $fallback): int
    {
        return preg_match('/(\d{4})/', $sheet, $m) ? (int) $m[1] : $fallback;
    }
}
