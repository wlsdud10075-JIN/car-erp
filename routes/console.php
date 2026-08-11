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
// 공휴일 자동 수집 (2026-08-11) — 임시·대체공휴일은 연중에 갑자기 지정되므로 매일 받는다.
//   알림톡 수신자 시각 규칙이 이 값을 쓴다(발송 경로는 저장분만 읽는다 — API 를 직접 안 부른다).
Schedule::command('holidays:sync')->dailyAt('04:10')->withoutOverlapping();

Schedule::command('alarms:scan')->dailyAt('06:00')->withoutOverlapping();

// 카카오 알림톡 자동발송 (2026-07-06) — 캐시 재계산(05:00) 후 최신 grace/미수 기준.
//   전부 BizmAlimtalkService 게이트 내장 = Setting alimtalk_enabled off 면 자동 skip(배포 ≠ 작동, inert).
//   일일 알림 전부 09:00(jin 2026-07-08) · 주간 금 18:00 · 월결산 = 익월 첫 영업일 09:00.
//   ⚠️ 주말 발송 금지(jin 2026-07-10): 정기 자동발송은 평일(월~금)만. weekly 는 금요일이라 무관.
//      (이벤트 발동 알림 — 정산승인·말소증 등 — 은 사용자 액션 시점이라 스케줄 무관, 별도.)
Schedule::command('alimtalk:pickup')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:purchase-unpaid')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:sale-unpaid')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:eta-balance')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:shipping-due')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:deposit-cash')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:daily-summary')->dailyAt('09:00')->weekdays()->withoutOverlapping();
Schedule::command('alimtalk:weekly-summary')->weeklyOn(5, '18:00')->withoutOverlapping();
// 대표 주간 자금/손익 보고 (2026-07-23) — 월요일 09:00. 최신 통장 스냅샷 기준. 미설정/미입력 시 내부 skip(inert).
Schedule::command('alimtalk:capital-weekly')->weeklyOn(1, '09:00')->withoutOverlapping();
// 월말 마감 보고 (jin 2026-07-27) — 재무가 16~17시에 마무리하므로 18:00 발송. 템플릿은 주간과 동일
//   (erp_capital_weekly 공용, 링크 버튼으로 상세 열람).
//   ⚠️ monthlyOn(31) 은 31일 없는 달(2·4·6·9·11월)을 통째로 건너뛴다 → 매일 18:00 에 돌리되 말일에만 실행.
Schedule::command('alimtalk:capital-weekly')->dailyAt('18:00')->withoutOverlapping()
    ->when(fn () => now()->isLastOfMonth());
// 월결산 = **월배치 정산이 최종 승인된 때** 발송한다 (jin 2026-07-31).
//   구: 익월 첫 영업일 무조건 발송 → 그때는 전월 정산이 아직 확정 전이라 마진·지급이 통째로 과소보고됐다.
//   실제 발송 트리거는 SettlementPayoutBatch::approveBy 이고, 이 스케줄은 **재시도**다 —
//   승인 시점에 알림톡이 실패했거나(미설정·게이트 off) 마감이 늦어진 달을 매일 따라잡는다.
//   커맨드 자체가 "마감됐나 + 이미 보냈나"를 검사하므로 매일 돌아도 중복 발송되지 않는다.
Schedule::command('alimtalk:monthly-closing')->dailyAt('09:00')->withoutOverlapping();

// 알림톡 전송결과 폴링 (2026-07-13) — 발송된 msgid 의 실제 도달/미도달을 BizM /v2/sender/report 로 조회.
//   read-only 조회라 매시간(주말 포함 — 금요일 발송분이 주말에 도달 확정될 수 있음). 미설정 시 내부 skip(inert).
Schedule::command('alimtalk:poll-report')->hourly()->withoutOverlapping();

// 일별 마감환율 스냅샷 (2026-07-13) — 매일 09:00 네이버 현재값을 "전날 마감"으로 daily_exchange_rates 저장.
//   ⚠️ 매일(주말 포함) — 금요일 마감이 토요일 09:00 에 정확히 잡히게. 잔금 날짜별 환율 자동기입 소스.
Schedule::command('exchange:snapshot-daily')->dailyAt('09:00')->withoutOverlapping();

// 챗봇 호스트 감시 (2026-07-30, 색인 세션 요청) — 사내 GPU PC 는 자기가 죽었다고 알릴 통로가 없다.
//   죽어도 챗봇은 에러 없이 옛 답변만 계속 내므로 티가 안 난다. Ollama 응답 + 색인 mtime 두 지표를
//   캐시에 적어두고 사이드바(시스템관리자 전용)가 읽는다. 캐시 TTL 30분 → 스케줄러가 죽으면 화면이
//   "감시 미작동" 으로 분기한다(그래서 forever 를 쓰지 않는다).
Schedule::command('assistant:health-check')->everyFiveMinutes()->withoutOverlapping();
