<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * 락 필요입금률 해석 — **단일 출처** (jin 2026-08-21).
 *
 * 우선순위:
 *   ① 차량별 승인 우회(`unpaid_export_overrides`) — 여기가 아니라 각 게이트가 따로 본다.
 *      임계를 낮추는 게 아니라 **판정 자체를 건너뛰기** 때문이다.
 *   ② 바이어별 재정의 (`buyers.lock_*_pct`)     ← 이 클래스
 *   ③ 전역 (`Setting::lockRequiredPaidPct`)      ← 이 클래스
 *
 * 🚨 **`Setting::lockThreshold('shipping_entry'|'purchase_registration')` 를 직접 부르지 말 것.**
 *    부르면 바이어 설정을 통째로 무시하고 전역으로 떨어지는데 **예외도 로그도 안 난다**
 *    (화면은 정상 렌더된다). 2026-07-30 에 pivot 단일출처를 정하고도 5일 뒤 만든 cron 만
 *    옛 기준을 써서 독촉이 7대 꺼져 있던 것과 같은 형태다.
 *    가드 = `BuyerLockThresholdTest` 의 정적 검사(이 파일만 예외).
 *
 * ⚠️ `bl_issue`(100% 고정)·`purchase_payment`(범위 밖)는 **바이어별이 아니다.**
 *    그 둘은 `Setting::lockThreshold` 를 그대로 쓴다.
 */
class LockThresholdResolver
{
    /**
     * 바이어별 재정의를 허용하는 락 → 그 컬럼. **목록 밖은 예외**(조용한 전역 폴백 방지).
     */
    public const PER_BUYER_LOCKS = [
        'shipping_entry' => 'lock_shipping_entry_pct',
        'purchase_registration' => 'lock_purchase_registration_pct',
    ];

    /**
     * 필요 입금률(%). 바이어 재정의가 있으면 그 값, 없으면 전역.
     *
     * 🚨 **NULL 만 미설정이다. 0 은 "필요입금 0% = 락 없음"이라는 유효값.**
     *    `?:` 나 `??` 로 0 을 걸러내면 일부러 풀어준 바이어가 조용히 전역으로 돌아간다.
     */
    public static function requiredPaidPct(?Buyer $buyer, string $lock): int
    {
        self::assertPerBuyerLock($lock);

        $override = self::buyerOverride($buyer, $lock);

        return $override !== null
            ? max(0, min(100, $override))
            : Setting::lockRequiredPaidPct($lock);
    }

    /**
     * 미수율 cutoff — 이 값 **초과**면 차단. 필요입금 P% → (100-P)/100.
     * `Setting::lockThreshold` 와 같은 계산식이되 바이어 재정의를 먼저 본다.
     */
    public static function threshold(?Buyer $buyer, string $lock): float
    {
        return max(0.0, min(1.0, (100 - self::requiredPaidPct($buyer, $lock)) / 100));
    }

    /** 바이어가 이 락을 재정의하고 있는가 (화면 표시용 — "전역 60% / 이 바이어 75%"). */
    public static function hasOverride(?Buyer $buyer, string $lock): bool
    {
        self::assertPerBuyerLock($lock);

        return self::buyerOverride($buyer, $lock) !== null;
    }

    /**
     * 여러 바이어의 cutoff 를 한 번에 — `[buyer_id => cutoff]`. 배치 판정용(N+1 방지).
     * 목록에 없는 바이어는 키가 없다 → 호출측은 전역값으로 읽을 것.
     *
     * @param  int[]  $buyerIds
     * @return array<int, float>
     */
    public static function thresholdsFor(array $buyerIds, string $lock): array
    {
        self::assertPerBuyerLock($lock);
        $buyerIds = array_values(array_unique(array_filter($buyerIds)));
        if (empty($buyerIds)) {
            return [];
        }

        $column = self::PER_BUYER_LOCKS[$lock];
        $globalPct = Setting::lockRequiredPaidPct($lock);

        $rows = DB::table('buyers')->whereIn('id', $buyerIds)
            ->select('id', $column)->get();

        $out = [];
        foreach ($rows as $row) {
            $pct = $row->{$column};
            $pct = $pct !== null ? max(0, min(100, (int) $pct)) : $globalPct;
            $out[(int) $row->id] = max(0.0, min(1.0, (100 - $pct) / 100));
        }

        return $out;
    }

    /**
     * 바이어 재정의 값 (없으면 null).
     *
     * ⚠️ **컬럼이 로드되지 않은 Buyer 를 그냥 통과시키면 안 된다.** 배치 경로가 컬럼을 제한해
     *    로드하면(`computeReceivableGaugesFor` 가 실제로 그렇게 한다) 재정의가 있는데도 없는 것처럼
     *    읽혀 **전역으로 조용히 떨어진다**. 그래서 로드 안 됐으면 그 바이어만 다시 조회한다 —
     *    N+1 이 생기더라도 틀린 금액 판정보다 낫다. 배치는 `thresholdsFor()` 를 쓸 것.
     */
    private static function buyerOverride(?Buyer $buyer, string $lock): ?int
    {
        if (! $buyer || ! $buyer->exists) {
            return null;
        }

        $column = self::PER_BUYER_LOCKS[$lock];

        if (! array_key_exists($column, $buyer->getAttributes())) {
            $value = DB::table('buyers')->where('id', $buyer->getKey())->value($column);

            return $value !== null ? (int) $value : null;
        }

        $value = $buyer->getAttribute($column);

        return $value !== null ? (int) $value : null;
    }

    private static function assertPerBuyerLock(string $lock): void
    {
        if (! array_key_exists($lock, self::PER_BUYER_LOCKS)) {
            throw new \InvalidArgumentException(
                "락 '{$lock}' 은 바이어별 재정의 대상이 아닙니다. ".
                '허용: '.implode(', ', array_keys(self::PER_BUYER_LOCKS)).
                ' (bl_issue 는 100% 고정, purchase_payment 는 범위 밖 — Setting::lockThreshold 를 직접 쓸 것.)'
            );
        }
    }
}
