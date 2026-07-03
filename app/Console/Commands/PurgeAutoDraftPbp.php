<?php

namespace App\Console\Commands;

use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 매입 자동 PBP Draft 정리 (jin 2026-07-03).
 *
 * 자동 Draft 생성이 폐기(Vehicle::saved)됐으나, 운영 DB에 과거 생성된 auto-Draft 가 남아
 * 재무처리 매입 잔금 '대기' 큐에 잔존한다. **미확정(confirmed_at=NULL) 자동 Draft 만 삭제** —
 * 매입 미지급 accessor 는 확정 PBP 만 세므로 삭제해도 미지급·정산 수치 무영향(재무 안전).
 *
 * ⚠️ **확정(confirmed_at NOT NULL) 자동 Draft 는 실제 지급 기록 → 절대 삭제 안 함**(보고만).
 * ⚠️ note != AUTO_DRAFT_NOTE 인 PBP(엑셀 import·수기 잔금 등)는 대상 아님(건드리지 않음).
 *
 * dry-run 기본. 실제 삭제는 --apply.
 */
class PurgeAutoDraftPbp extends Command
{
    protected $signature = 'pbp:purge-auto-drafts {--apply : 실제 삭제 (미지정 시 dry-run)}';

    protected $description = '매입 자동 PBP Draft(미확정)를 정리. 확정건(실지급)은 보존.';

    public function handle(): int
    {
        $note = PurchaseBalancePayment::AUTO_DRAFT_NOTE;
        $apply = (bool) $this->option('apply');

        $unconfirmed = PurchaseBalancePayment::where('note', $note)
            ->whereNull('confirmed_at')
            ->with('vehicle:id,vehicle_number')
            ->get();
        $confirmedCount = PurchaseBalancePayment::where('note', $note)
            ->whereNotNull('confirmed_at')
            ->count();

        $this->info("자동 Draft (note='{$note}')");
        $this->line("  · 미확정(삭제 대상): {$unconfirmed->count()}건 — 미지급 accessor 는 확정만 세므로 재무 무영향");
        $this->line("  · 확정(보존): {$confirmedCount}건 — 실지급 기록, 절대 삭제 안 함");

        if ($unconfirmed->isEmpty()) {
            $this->info('삭제 대상 없음.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('삭제 대상 차량별:');
        foreach ($unconfirmed->groupBy('vehicle_id') as $vid => $rows) {
            $number = $rows->first()->vehicle?->vehicle_number ?? "#{$vid}";
            $this->line(sprintf('  - %s : %d건 (합 ₩%s)', $number, $rows->count(), number_format((int) $rows->sum('amount'))));
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — 실제 삭제하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $ids = $unconfirmed->pluck('id');
        $vehicleIds = $unconfirmed->pluck('vehicle_id')->unique()->filter();

        // whereIn->delete() 는 모델 이벤트 미발화 → 진행상태 캐시 명시 갱신 (SKILLS §2).
        PurchaseBalancePayment::whereIn('id', $ids)->delete();
        foreach ($vehicleIds as $vid) {
            Vehicle::find($vid)?->refreshProgressCache();
        }

        $this->newLine();
        $this->info("삭제 완료: {$ids->count()}건 (차량 {$vehicleIds->count()}대). 확정 {$confirmedCount}건 보존.");

        return self::SUCCESS;
    }
}
