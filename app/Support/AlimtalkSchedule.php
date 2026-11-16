<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * 🕘 정기 알림톡 발송 시각 — 단일 출처 (jin 2026-11-16 «09시~11시50분까지 나눠서 보내면 한 번에 보는 것보단 낫지 않을까»).
 *
 * 구: 일일 알림 전부 평일 09:00 → 관리 담당이 09:00대에 4~6통을 한꺼번에 받아(11-13·11-16 실측) 앞의 것이 위로 밀려 안 읽혔다.
 * 지금: 관리가 받는 것은 급한 순으로 30분 간격, 대표용(일일 요약·채권 현황)은 2~3통이라 09:00 그대로.
 * 따라잡기(서버 정지·BizM 순간 장애) = 각자 시각 +10분에 한 번 더(발송은 once_per_day 가 오늘 보낸 건을 건너뛴다).
 * 쓰는 곳 = routes/console.php · 관제 루틴 표(OpsRoutines) · 알림톡 안내 설명 · 테스트.
 */
class AlimtalkSchedule
{
    /** 평일 일일 알림 — 커맨드(alimtalk: 뒤) => 시각 */
    public const DAILY = [
        'daily-summary' => '09:00',       // 대표
        'receivable-status' => '09:00',   // 대표
        'sale-unpaid' => '09:00',         // 관리 — 받을 돈이 제일 급하다
        'shipping-due' => '09:30',        // 관리 — 선적 전에 막아야
        'eta-balance' => '10:00',         // 관리 — 도착 전 잔금
        'purchase-unpaid' => '10:30',     // 관리 — 줄 돈
        'deposit-cash' => '11:00',        // 관리·영업 — 보증금 입금 재촉
        'deregistration' => '11:30',      // 영업(늦으면 관리)
        'pickup' => '11:30',              // 영업
    ];

    /** 주간 — 월요일 */
    public const ARRIVED_UNPAID_WEEKLY = '11:50';   // 관리 — 도착 후 미수 요약(구: 월 09:00)

    public const CAPITAL_WEEKLY = '09:00';          // 대표 — 자금 보고

    public const CATCHUP_MINUTES = 10;

    public static function catchupAt(string $at): string
    {
        return Carbon::createFromFormat('H:i', $at)->addMinutes(self::CATCHUP_MINUTES)->format('H:i');
    }

    /** 「평일 10:30」 — 알림톡 안내 화면 설명용 */
    public static function label(string $command): string
    {
        return '평일 '.(self::DAILY[$command] ?? '09:00');
    }
}
