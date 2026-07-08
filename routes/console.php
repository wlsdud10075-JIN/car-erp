<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// DB 일일 백업 (큐 13 배포) — 매일 03:00 mysqldump → storage/backups/db/ (+ DB_BACKUP_DISK 설정 시 S3 업로드).
// 서버에서 cron 1줄 필요: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping();

// 전 차량 캐시 야간 재계산 (2026-07-06) — progress_status_cache·receivable_risk·sale_unpaid_amount_krw_cache.
// 시간기반 조건(잔금 payment_date 도래, 판매 후 경과일 등)은 저장 이벤트 없이 넘어가 캐시가 drift 하므로
// 매일 재계산으로 보정. alarms:scan(06:00) 전인 05:00 에 돌려 알람·대시보드가 최신 캐시를 보게 함.
Schedule::command('vehicles:rebuild-caches')->dailyAt('05:00')->withoutOverlapping();

// ETA 영구 알람 일일 스캔 (2026-06-18) — 도착 임박 통관서류 알람 생성/갱신/자동해소.
// Setting('alarm_enabled')=false 면 내부에서 건너뜀(배포 ≠ 작동). 업무 시작 전 06:00.
Schedule::command('alarms:scan')->dailyAt('06:00')->withoutOverlapping();

// 카카오 알림톡 자동발송 (2026-07-06) — 캐시 재계산(05:00) 후 최신 grace/미수 기준.
//   전부 BizmAlimtalkService 게이트 내장 = Setting alimtalk_enabled off 면 자동 skip(배포 ≠ 작동, inert).
//   일일 알림 전부 09:00(jin 2026-07-08) · 주간 금 18:00 · 월결산 익월 1일 09:00(정산 마감=말일이라 전월 결산을 다음달 1일 발송).
Schedule::command('alimtalk:pickup')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:purchase-unpaid')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:sale-unpaid')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:eta-balance')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:shipping-due')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:daily-summary')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('alimtalk:weekly-summary')->weeklyOn(5, '18:00')->withoutOverlapping();
Schedule::command('alimtalk:monthly-closing')->monthlyOn(1, '09:00')->withoutOverlapping();
