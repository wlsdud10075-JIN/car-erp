<?php

namespace Tests\Feature;

use App\Support\AlimtalkSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * 🔁 09:00 일간 알림톡 따라잡기 (2026-10-13).
 *
 * 실측: heymanerp 09-23 09:00 서버 정지 → 일간 5종 그날 결손 · ssancarerp 10-01 09:00 BizM 타임아웃 17건.
 * `dailyAt('09:00')` 은 그 분에 서버가 없으면 당일 따라잡기가 없다 → 같은 커맨드를 09:10 에 한 번 더 돌리고,
 * 발송 자체는 `once_per_day` 가 코드·번호·차량 단위로 오늘 보낸 건을 건너뛴다(명령 단위 when 금지 — 한 명이라도
 * 보냈으면 전원 생략이 된다).
 */
class AlimtalkDailyCatchupScheduleTest extends TestCase
{
    private const DAILY = [
        'alimtalk:pickup', 'alimtalk:deregistration', 'alimtalk:purchase-unpaid', 'alimtalk:sale-unpaid',
        'alimtalk:eta-balance', 'alimtalk:shipping-due', 'alimtalk:deposit-cash', 'alimtalk:daily-summary',
        'alimtalk:receivable-status',
    ];

    public function test_every_daily_alimtalk_command_runs_at_its_time_and_again_ten_minutes_later_on_weekdays(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertEqualsCanonicalizing(array_map(fn ($c) => substr($c, 9), self::DAILY), array_keys(AlimtalkSchedule::DAILY), '시각표 = 일일 9종');

        foreach (self::DAILY as $cmd) {
            $mine = $events->filter(fn ($e) => str_ends_with($e->command ?? '', $cmd));
            $exprs = $mine->map(fn ($e) => $e->expression)->values()->all();
            $at = AlimtalkSchedule::DAILY[substr($cmd, 9)];
            $cron = fn (string $hm) => (int) substr($hm, 3, 2).' '.(int) substr($hm, 0, 2).' * * 1-5';

            $this->assertContains($cron($at), $exprs, "{$cmd}: {$at} 평일 스케줄이 없다");
            $this->assertContains($cron(AlimtalkSchedule::catchupAt($at)), $exprs, "{$cmd}: +10분 따라잡기 스케줄이 없다");
            $this->assertTrue($mine->every(fn ($e) => $e->withoutOverlapping), "{$cmd}: withoutOverlapping 이 빠졌다");
        }
    }

    /** 🕘 11-16 (jin) — 관리 담당이 받는 것은 한 시각에 몰리지 않는다(대표용 2종만 09:00 같이). */
    public function test_manager_alerts_are_spread_across_the_morning(): void
    {
        $manager = ['sale-unpaid', 'shipping-due', 'eta-balance', 'purchase-unpaid', 'deposit-cash'];
        $times = array_map(fn ($c) => AlimtalkSchedule::DAILY[$c], $manager);
        $this->assertSame(count($times), count(array_unique($times)), '관리가 받는 일일 알림은 시각이 겹치면 안 된다');
        $this->assertTrue(max(array_merge($times, [AlimtalkSchedule::ARRIVED_UNPAID_WEEKLY])) <= '11:50', '오전 안에 끝낸다');
    }

    /** 따라잡기는 명령 단위 조건(when)이 아니라 발송 단위(`once_per_day`)로 걸러야 한다. */
    public function test_every_daily_command_passes_once_per_day_to_send(): void
    {
        $map = [
            'AlimtalkPickup', 'AlimtalkDeregistration', 'AlimtalkPurchaseUnpaid', 'AlimtalkSaleUnpaid', 'AlimtalkEtaBalance',
            'AlimtalkShippingDue', 'AlimtalkDepositCash', 'AlimtalkDailySummary', 'AlimtalkReceivableStatus',
        ];
        foreach ($map as $class) {
            $src = file_get_contents(base_path("app/Console/Commands/{$class}.php"));
            $sends = preg_match_all('/->send\(/', $src);
            $flagged = preg_match_all("/'once_per_day' => true/", $src);
            $this->assertGreaterThan(0, $sends, "{$class}: send() 호출이 없다");
            $this->assertSame($sends, $flagged, "{$class}: send() {$sends}곳 중 once_per_day 가 {$flagged}곳 — 따라잡기에서 중복 발송된다");
        }
    }
}
