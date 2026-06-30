<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Models\Vehicle;
use App\Support\SettlementCkBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 정산 재산정 (2026-06-22 Part B-2) — purge([[settlements:purge-import]]) 후 CK='정산' 차량에
 * 올바른 금액(사내직원 차등 tier / 프리랜서 비율)으로 정산을 재생성하고 paid + 2차 closed 까지 마무리.
 *
 * 배경: 일괄적재 오류로 146 정산 전부 paid+closed → 전량 삭제 완료(정산 0). 이제 CK='정산'(진짜 정산됨)
 *       차량만 재생성. 금액은 Settlement computed(total_margin → tier/ratio)로 자동.
 *
 * jin 결정(2026-06-22):
 *   - 대상 = xlsx CK 에 '정산' 포함 AND '미정산' 미포함 (G2 거래완료+정산 + G4 판매완료+정산, 실측 101대).
 *   - 타입 = 차량 담당자 defaultSettlementType (사내직원 per_unit 차등 tier / 프리랜서 ratio).
 *     ratio/per_unit_amount = null → computed(설정 기반). paid 전환 시 동결(materialize).
 *   - 전부 paid + 2차 secondary='closed'. **환차 = 0** (사내직원 회사부담, 1억+ 25% 배율제여도 per_unit 이라 환차 미반영).
 *   - 62두1461 = ERP total_margin 기준(엑셀 환율누락 오류분 미반영) — 자동.
 *
 * 멱등: 이미 정산 있는 차량 skip. artisan(auth 없음) → paid 전환 canApprove 가드 우회.
 * created_at = CK 배치(일한 月)로 백데이트 — 정산 월별 드롭다운 정합 (2026-06-24, [[project_settlement_payroll_batch]]).
 *   confirmed/paid/closed_at 는 실행 시점 유지 (회계 처리 타임스탬프, 드롭다운 무관).
 *
 *   php artisan settlements:recreate-from-ck "경로/1. 헤이맨 수출차량현황표.xlsx"            # dry-run
 *   php artisan settlements:recreate-from-ck "..." --apply                                   # 실제 생성
 */
class RecreateSettlementsFromCk extends Command
{
    protected $signature = 'settlements:recreate-from-ck
        {path : 판별 기준 xlsx 경로}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 생성 (미지정 시 dry-run)}';

    protected $description = '정산 purge 후 CK=정산 차량에 올바른 금액으로 정산 재생성 + paid + 2차 closed(환차0). 기본 dry-run.';

    private const NOTE_MARKER = '재생성 CK정산 2026-06-22';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $year = SettlementCkBatch::yearFromSheet((string) $this->option('sheet'), now()->year);
        $targets = $this->settledCkByVehicleNumber($path);   // [차량번호 => CK]
        $this->info('CK=정산 차량(파일): '.count($targets).'대');

        $plans = [];
        $skipExisting = 0;
        $noVehicle = [];
        $noSalesman = [];

        foreach ($targets as $vno => $ck) {
            $v = Vehicle::where('vehicle_number', $vno)->first();
            if (! $v) {
                $noVehicle[] = $vno;

                continue;
            }
            if ($v->settlements()->exists()) {
                $skipExisting++;

                continue;
            }
            if (! $v->salesman_id || ! $v->salesman) {
                $noSalesman[] = $vno;

                continue;
            }
            $type = $v->salesman->defaultSettlementType();
            $s = new Settlement(['settlement_type' => $type]);
            $s->setRelation('vehicle', $v);
            $plans[] = [
                'vehicle' => $v,
                'salesman' => $v->salesman->name,
                'type' => $type,
                'amount' => $s->settlement_amount,
                // 2026-06-24 — created_at(일한月, 드롭다운) + paid_at(지급일, board)을 CK 배치로 백데이트.
                'created_at' => SettlementCkBatch::workCreatedAt($ck, $year),
                'paid_at' => SettlementCkBatch::payoutDate($ck, $year),
            ];
        }

        // 요약
        $byType = [];
        $sum = 0;
        foreach ($plans as $p) {
            $byType[$p['type']] = ($byType[$p['type']] ?? 0) + 1;
            $sum += $p['amount'];
        }
        $this->newLine();
        $this->info('생성 대상: '.count($plans).'대  ('.collect($byType)->map(fn ($c, $t) => "$t:$c")->implode(' / ').')');
        $this->line('  정산액 합계: '.number_format($sum).'원');
        $this->line("  이미 정산 있어 skip: {$skipExisting}대 / 서버에 없음: ".count($noVehicle).'대 / 담당자 없음: '.count($noSalesman).'대');
        if ($noVehicle) {
            $this->line('   없음: '.implode(', ', array_slice($noVehicle, 0, 20)).(count($noVehicle) > 20 ? ' …' : ''));
        }
        if ($noSalesman) {
            $this->line('   담당자없음: '.implode(', ', $noSalesman));
        }
        $this->newLine();
        $this->line('샘플 15:');
        foreach (array_slice($plans, 0, 15) as $p) {
            $this->line(sprintf('  %-11s %-10s %-9s 정산액=%s', $p['vehicle']->vehicle_number, $p['salesman'], $p['type'], number_format($p['amount'])));
        }
        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn('[DRY-RUN] 쓰기 없음. 실제 생성하려면 --apply.');

            return self::SUCCESS;
        }

        $created = 0;
        DB::transaction(function () use ($plans, &$created) {
            foreach ($plans as $p) {
                $v = $p['vehicle'];
                // 1) confirmed 로 생성
                $s = Settlement::create([
                    'vehicle_id' => $v->id,
                    'salesman_id' => $v->salesman_id,
                    'settlement_type' => $p['type'],
                    'settlement_ratio' => null,   // computed (설정 기반)
                    'per_unit_amount' => null,    // computed (차등 tier)
                    'settlement_status' => 'confirmed',
                    'confirmed_at' => now(),
                    'note' => self::NOTE_MARKER,
                ]);
                // 2) paid 전환 → 동결(materialize) + snapshot + secondary='pending' (saving 훅)
                $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);
                // 3) 2차 closed 마무리 — 환차 0, 이월 0 (사내직원 회사부담)
                $s->update([
                    'secondary_status' => 'closed',
                    'secondary_closed_at' => now(),
                    'exchange_difference_krw' => 0,
                    'carryover_out_krw' => 0,
                ]);
                // 4) created_at(일한月) + paid_at(지급일) 백데이트 — CK 배치. 파싱 불가 시 now() 유지.
                $back = [];
                if ($p['created_at']) {
                    $back['created_at'] = $p['created_at']->format('Y-m-d H:i:s');
                }
                if ($p['paid_at']) {
                    $back['paid_at'] = $p['paid_at']->format('Y-m-d H:i:s');
                }
                if ($back) {
                    DB::table('settlements')->where('id', $s->id)->update($back);
                }
                $created++;
            }
        });

        $this->info("✅ 완료: 정산 {$created}건 재생성 (paid + 2차 closed, 환차 0).");
        $this->line('정산 총 건수: '.Settlement::count());

        return self::SUCCESS;
    }

    /** xlsx CK='정산'(정산 포함 AND 미정산 미포함) → [차량번호 => CK]. */
    private function settledCkByVehicleNumber(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $ws = $reader->load($path)->getSheetByName($this->option('sheet')) ?? null;
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
