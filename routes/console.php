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

// ETA 영구 알람 일일 스캔 (2026-06-18) — 도착 임박 통관서류 알람 생성/갱신/자동해소.
// Setting('alarm_enabled')=false 면 내부에서 건너뜀(배포 ≠ 작동). 업무 시작 전 06:00.
Schedule::command('alarms:scan')->dailyAt('06:00')->withoutOverlapping();
