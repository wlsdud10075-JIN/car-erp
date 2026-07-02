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

    /**
     * CK 배치 → 실제 지급일(paid_at). board 는 이 月 기준으로 묶음(5/10·6/10 → 5월·6월).
     *   "26.05.10정산" → 2026-05-10 / "6월 정산" → {연도}-06-10(지급일 10일 가정). 파싱 불가 시 null.
     */
    public static function payoutDate(string $ck, int $fileYear): ?Carbon
    {
        if (! self::isSettled($ck)) {
            return null;
        }

        // "26.05.10정산" — 2자리연도.월.일
        if (preg_match('/(\d{2})\.(\d{1,2})\.(\d{1,2})/', $ck, $m)) {
            return Carbon::create(2000 + (int) $m[1], (int) $m[2], (int) $m[3], 10, 0);
        }
        // "6월 정산" — 지급月만 (연도=파일연도, 지급일=10 가정)
        if (preg_match('/(\d{1,2})\s*월/u', $ck, $m)) {
            return Carbon::create($fileYear, (int) $m[1], 10, 10, 0);
        }

        return null;
    }

    /**
     * CK 배치 → 정산 귀속월(일한 月) 15일 created_at. 내부 드롭다운용(일한月 4/5월).
     * 일한 月 = 지급 月 − 1. 파싱 불가 시 null.
     */
    public static function workCreatedAt(string $ck, int $fileYear): ?Carbon
    {
        return self::payoutDate($ck, $fileYear)?->copy()->subMonthNoOverflow()->day(15)->setTime(10, 0);
    }

    /** 시트명에서 4자리 연도 추출 (예: '수출차량매입-2026' → 2026). 없으면 fallback. */
    public static function yearFromSheet(string $sheet, int $fallback): int
    {
        return preg_match('/(\d{4})/', $sheet, $m) ? (int) $m[1] : $fallback;
    }

    /**
     * created_at → 정산 귀속월('YYYY-MM'). 급여 10일 cutoff (jin 2026-07-02, 서버 실사용 발견):
     *   1~9일 마무리분 = 전달 귀속(이달 10일 지급) / 10일 이후 = 당월 귀속(다음달 10일 지급).
     * 예: 거래완료 2026-07-02 → 2026-06(7/10 지급) / 2026-07-15 → 2026-07(8/10 지급).
     * import 백데이트(일한月 15일, 15≥10)는 당월 유지 → 일한月 보존(무영향).
     */
    public static function payrollMonthOf(Carbon $date): string
    {
        return $date->day < 10
            ? $date->copy()->subMonthNoOverflow()->format('Y-m')
            : $date->format('Y-m');
    }

    /**
     * 귀속월('YYYY-MM') → created_at 필터 범위 [start, end).
     * 귀속월 M ⟺ created_at ∈ [M월 10일, (M+1)월 10일). payrollMonthOf 의 역함수.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(string $ym): array
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $start = Carbon::create($y, $m, 10, 0, 0, 0);

        return [$start, $start->copy()->addMonthNoOverflow()];
    }
}
