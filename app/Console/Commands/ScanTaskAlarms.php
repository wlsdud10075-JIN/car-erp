<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ETA 영구 알람 매일 스캔 (v1 = type 'eta_clearance').
 *
 * 단일출처: 생성·자동해소 모두 Vehicle::scopeAction('eta_clearance_reminder') 로 판정
 *           (raw SQL 재정의 금지 — 대시보드/사이드바 카운트와 100% 일치).
 * 멱등: 같은 차량 open row 있으면 due_date·meta 만 갱신(ETA 변경 자동 반영), 중복 생성 X.
 * 자동해소(보정): 더 이상 조건에 안 맞는 open 알람(서류 업로드/거래완료 등) → resolved.
 * 게이트: Setting('alarm_enabled') 가 false 면 생성 안 함 (배포 ≠ 작동). --dry-run 은 게이트 무시하고 카운트만.
 */
class ScanTaskAlarms extends Command
{
    protected $signature = 'alarms:scan {--dry-run : 생성/변경 없이 대상 건수만 출력 (배포 전 폭주 사전 점검)}';

    protected $description = 'ETA 도착 임박 통관서류 알람 + 선적 서류마감 임박 알람을 생성/갱신/자동해소한다.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $matched = Vehicle::query()->action('eta_clearance_reminder')->get();
            $docMatched = Vehicle::query()->action('document_deadline_reminder')->get();

            if ($dryRun) {
                $existingOpen = TaskAlarm::where('type', 'eta_clearance')->open()->count();
                $docOpen = TaskAlarm::where('type', 'document_deadline')->open()->count();
                $this->info("[dry-run] ETA 통관서류 알람 대상 = {$matched->count()}대 (현재 미해소 알람 {$existingOpen}건)");
                $this->info("[dry-run] 서류마감 알람 대상 = {$docMatched->count()}대 (현재 미해소 알람 {$docOpen}건)");

                return self::SUCCESS;
            }

            if (! (bool) Setting::get('alarm_enabled', false)) {
                $this->warn('alarm_enabled=false — 알람 생성 건너뜀 (기능설정에서 켜야 동작).');

                return self::SUCCESS;
            }

            $created = 0;
            $updated = 0;
            foreach ($matched as $v) {
                $alarm = TaskAlarm::firstOrNew([
                    'type' => 'eta_clearance',
                    'vehicle_id' => $v->id,
                    'resolved_at' => null,
                ]);
                $alarm->target_role = '수출통관';
                $alarm->due_date = $v->eta_date;   // ETA 변경 시 매 스캔마다 갱신
                $alarm->message_meta = TaskAlarm::sanitizeMeta([
                    'vehicle_number' => $v->vehicle_number,
                    'eta_date' => optional($v->eta_date)->toDateString(),
                    'unpaid_amount_krw' => $v->sale_unpaid_amount_krw_cache,
                ]);
                $alarm->exists ? $updated++ : $created++;
                $alarm->save();
            }

            // 보정(reconcile): 더 이상 조건에 안 맞는 open 알람은 자동 해소.
            //   (서류 업로드·거래완료·ETA 미래로 이동 등 — Vehicle::saved 즉시 해소를 놓친 케이스 포함)
            $resolved = TaskAlarm::where('type', 'eta_clearance')
                ->open()
                ->whereNotIn('vehicle_id', $matched->pluck('id'))
                ->update(['resolved_at' => now(), 'resolved_reason' => 'auto_resolved']);

            $this->info("ETA 통관서류 알람 — 신규 {$created} · 갱신 {$updated} · 자동해소 {$resolved}");

            // item 6 (2026-07-07) 선적 서류마감 임박 알람 (type 'document_deadline', target_role '관리').
            //   단일출처 = document_deadline_reminder 스코프. due_date = document_deadline_date (마감 5일전부터 판정).
            $docCreated = 0;
            $docUpdated = 0;
            foreach ($docMatched as $v) {
                $alarm = TaskAlarm::firstOrNew([
                    'type' => 'document_deadline',
                    'vehicle_id' => $v->id,
                    'resolved_at' => null,
                ]);
                $alarm->target_role = '관리';
                $alarm->due_date = $v->document_deadline_date;   // 마감일 변경 시 매 스캔 갱신
                $alarm->message_meta = TaskAlarm::sanitizeMeta([
                    'vehicle_number' => $v->vehicle_number,
                ]);
                $alarm->exists ? $docUpdated++ : $docCreated++;
                $alarm->save();
            }
            $docResolved = TaskAlarm::where('type', 'document_deadline')
                ->open()
                ->whereNotIn('vehicle_id', $docMatched->pluck('id'))
                ->update(['resolved_at' => now(), 'resolved_reason' => 'auto_resolved']);

            $this->info("서류마감 알람 — 신규 {$docCreated} · 갱신 {$docUpdated} · 자동해소 {$docResolved}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // cron stdout 은 /dev/null 로 버려짐 → 반드시 로그 기록 (무음 실패 방지, Ops 조건).
            Log::error('alarms:scan 실패', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('alarms:scan 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
