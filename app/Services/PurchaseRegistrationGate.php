<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\Setting;

/**
 * 매입 등록 락 판정 — **단일 출처**.
 *
 * 같은 판정이 두 곳에서 필요하다:
 *   ① 차량관리 매입 등록·바이어 교체 저장 (`vehicles/index` `save()`) — 실제 차단
 *   ② board 읽기 API (`/internal/board/buyers`)                       — 낙찰 **전** 예방
 *
 * ⚠️ 조건을 각자 옮겨 적지 말 것. 갈리는 순간 "board 는 된다는데 ERP 는 막는다"가 되고,
 *    영업은 이미 돈을 쓴 뒤에 그걸 안다. (SKILLS §8 #44 — 화면을 API 로 미러할 때 조건을
 *    옮겨 적어 어긋난 그 형태. 그때도 사람 눈으로는 못 잡았다.)
 *
 * 🚪 락이 걸리는 지점은 여전히 **ERP 저장**이다. board 는 이 판정을 **미리 보여줄** 뿐,
 *    board 가 통과시켰다고 ERP 가 통과하는 게 아니다(연동 B 수신경로는 별개 — 아래 주석).
 */
class PurchaseRegistrationGate
{
    /** 무담보 한도가 설정된 바이어 — **금액**(무담보 잔액)으로 판정. */
    public const MODE_UNSECURED = 'unsecured';

    /** 무담보 미설정 — 기존 **미수율** 판정. */
    public const MODE_RATIO = 'ratio';

    /** 락 기능 OFF(시스템관리자 토글) — 항상 통과. */
    public const MODE_OFF = 'off';

    /** 매입 등록 락이 켜져 있는가 (회사 단위 토글). */
    public static function enabled(): bool
    {
        return Setting::lockEnabled('purchase_registration');
    }

    /**
     * 이미 계산된 게이지로 판정 — **실제 락 판정식은 여기 한 곳뿐이다**.
     *
     * @param  array|null  $gauge  `Buyer::computeReceivableGauge()` 결과. null = 판정 근거 없음 = 통과.
     * @param  bool  $useUnsecured  `Buyer::hasUnsecuredLimit()`
     * @return array{locked: bool, mode: string, threshold: float, gauge: ?array}
     */
    public static function decide(?array $gauge, bool $useUnsecured): array
    {
        $threshold = Setting::lockThreshold('purchase_registration');

        if (! self::enabled()) {
            return ['locked' => false, 'mode' => self::MODE_OFF, 'threshold' => $threshold, 'gauge' => $gauge];
        }

        $mode = $useUnsecured ? self::MODE_UNSECURED : self::MODE_RATIO;

        // 무담보를 설정한 바이어는 **미수율이 아니라 금액**으로 판정한다 — 보증금(입금액×비율) →
        // 무담보 순으로 차감되고, 무담보까지 0이면 진짜 락이다. 판매 잔금이 들어오면 되살아난다.
        $locked = $gauge !== null && ($useUnsecured
            ? $gauge['unsecured_available_krw'] <= 0
            : $gauge['ratio'] > $threshold);

        return ['locked' => $locked, 'mode' => $mode, 'threshold' => $threshold, 'gauge' => $gauge];
    }

    /**
     * 바이어 1명 판정 (게이지를 직접 로딩).
     * 락이 꺼져 있으면 게이지 쿼리도 돌리지 않는다.
     */
    public static function forBuyer(?Buyer $buyer): array
    {
        if (! $buyer || ! self::enabled()) {
            return self::decide(null, false);
        }

        return self::decide($buyer->receivableGauge(), $buyer->hasUnsecuredLimit());
    }

    /**
     * 여러 바이어 일괄 판정 — `[buyer_id => 판정]`. 드롭다운처럼 N명을 한 번에 볼 때.
     * 바이어당 쿼리를 돌리지 않는다(차량·한도 각 1쿼리).
     *
     * ⚠️ 게이지가 없는(= 판정 근거가 없는) 바이어는 키가 없다 — 호출측은 "없으면 통과"로 읽을 것.
     *
     * @param  int[]  $buyerIds
     * @return array<int, array{locked: bool, mode: string, threshold: float, gauge: ?array}>
     */
    public static function forBuyerIds(array $buyerIds): array
    {
        if (empty($buyerIds) || ! self::enabled()) {
            return [];
        }

        $gauges = Buyer::computeReceivableGaugesFor($buyerIds);
        $out = [];
        foreach ($gauges as $bid => $gauge) {
            // 무담보 모드 여부 = 그 바이어에게 실효 한도가 잡혔는가. 게이지가 이미 그 값을 들고
            // 있으므로 (기능 토글까지 반영된 값) 여기서 Buyer 를 다시 읽지 않는다 — 읽으면 N+1 이고,
            // 컬럼을 제한한 재조회로 한도가 0 이 되는 사고 경로가 하나 더 생긴다.
            $out[(int) $bid] = self::decide($gauge, (int) ($gauge['unsecured_limit_krw'] ?? 0) > 0);
        }

        return $out;
    }
}
