<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Support\SettlementCkBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 기존 정산의 created_at 을 엑셀 CK 배치(일한 月)로 백데이트 (2026-06-24 jin).
 *
 * 문제: RecreateSettlementsFromCk 가 정산 created_at 을 실행시각(2026-06)으로 박아
 *   정산 월별 드롭다운이 전부 한 달(6월)로 뭉침. 백업 복구해도 동일.
 * 해결: CK("비고") 배치를 다시 읽어 정산 귀속월(지급月−1)로 created_at 보정.
 *   "26.05.10정산"→2026-04 / "6월 정산"→2026-05. ([[project_settlement_payroll_batch]])
 *
 * 멱등(결정적 날짜). 차량번호로 매칭, CK 배치 없는 차량의 정산은 건드리지 않음.
 *
 *   php artisan settlements:backdate-from-ck "경로/1. 헤이맨 수출차량현황표.xlsx"          # dry-run
 *   php artisan settlements:backdate-from-ck "..." --apply                                  # 실제 보정
 */
class BackdateSettlementsFromCk extends Command
{
    protected $signature = 'settlements:backdate-from-ck
        {path : 판별 기준 xlsx 경로}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 보정 (미지정 시 dry-run)}';

    protected $description = '정산 created_at 을 엑셀 CK 배치(일한 月)로 백데이트 — 월별 드롭다운 정합. 기본 dry-run.';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $sheet = (string) $this->option('sheet');
        $year = SettlementCkBatch::yearFromSheet($sheet, now()->year);
        $ckByVehicle = $this->ckByVehicleNumber($path, $sheet);   // [차량번호 => CK]
        $this->info('엑셀 정산 배치 차량: '.count($ckByVehicle).'대 (시트 '.$sheet.', 연도 '.$year.')');

        // 정산 + 차량번호 로딩.
        $settlements = Settlement::with('vehicle:id,vehicle_number')->get();

        $plan = [];           // [settlement_id => Carbon]
        $monthPreview = [];    // [YYYY-MM => count]
        $noMatch = 0;
        $noVehicle = 0;

        foreach ($settlements as $s) {
            $vno = $s->vehicle?->vehicle_number;
            if (! $vno) {
                $noVehicle++;

                continue;
            }
            $ck = $ckByVehicle[$vno] ?? null;
            if ($ck === null) {
                $noMatch++;

                continue;
            }
            $date = SettlementCkBatch::workCreatedAt($ck, $year);
            if (! $date) {
                $noMatch++;

                continue;
            }
            $plan[$s->id] = $date;
            $ym = $date->format('Y-m');
            $monthPreview[$ym] = ($monthPreview[$ym] ?? 0) + 1;
        }

        ksort($monthPreview);
        $this->newLine();
        $this->info('보정 대상: '.count($plan).'건');
        foreach ($monthPreview as $ym => $cnt) {
            $payYm = Carbon::parse($ym.'-01')->addMonthNoOverflow()->format('Y-m');
            $this->line("  {$ym} (일한 月) → {$payYm}-10 지급 : {$cnt}건");
        }
        $this->line("  CK 배치 없어 유지(미변경): {$noMatch}건 / 차량 없음: {$noVehicle}건");

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('[DRY-RUN] 쓰기 없음. 실제 보정하려면 --apply.');

            return self::SUCCESS;
        }

        $updated = 0;
        DB::transaction(function () use ($plan, &$updated) {
            foreach ($plan as $sid => $date) {
                // created_at 직접 보정 (Eloquent 는 created_at 갱신 안 함 — raw update).
                DB::table('settlements')->where('id', $sid)->update(['created_at' => $date->format('Y-m-d H:i:s')]);
                $updated++;
            }
        });

        $this->newLine();
        $this->info("✅ 완료: 정산 {$updated}건 created_at 백데이트.");

        return self::SUCCESS;
    }

    /** xlsx 정산 배치 행 → [차량번호 => CK 문자열]. */
    private function ckByVehicleNumber(string $path, string $sheet): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $ws = $reader->load($path)->getSheetByName($sheet);
        if (! $ws) {
            return [];
        }
        $high = $ws->getHighestRow();
        $out = [];
        for ($r = 3; $r <= $high; $r++) {
            $vno = trim((string) $ws->getCell('D'.$r)->getCalculatedValue());
            if ($vno === '') {
                continue;
            }
            $ck = (string) $ws->getCell('CK'.$r)->getCalculatedValue();
            if (SettlementCkBatch::isSettled($ck)) {
                $out[$vno] = trim($ck);
            }
        }

        return $out;
    }
}
