<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ssancarerp — 운임비(USD) 기록칸 소급 기입 (jin 2026-08-29).
 *
 * 2026-08-28 에 적재한 정산완료 3,839 대는 `transport_fee_usd` 가 비어 있다. 그때 쓴
 * `ssancarerp:import-settled` 의 열 매핑에 AQ 가 아예 없었기 때문이다(08-29 에 추가했지만
 * 이미 들어간 행에는 소급되지 않는다). 그 한 칸만 채운다.
 *
 * 🔑 `transport_fee_usd` 는 순수 메모다 — 총판매가·미수·정산·면장 어디에도 안 들어간다
 *    (`Vehicle::fillable` 주석 · jin 2026-08-05). 그래서 이 명령은 회계를 1 원도 안 건드린다.
 *
 * 왜 모델이 아니라 query-builder update 인가:
 *   대상이 2 차 정산까지 마감된 차량이라 모델 훅을 태우면 관계 없는 게이트·캐시 재계산이
 *   3,204 행에 대해 전부 돈다. 그중 `Vehicle::saving` 의 출고일 자동채움처럼 값을 바꾸는 훅도 있다.
 *   메모 한 칸 때문에 그걸 깨울 이유가 없다. 대신 감사 기록은 직접 남긴다
 *   (`transport_fee_usd` 는 `AUDITED_COLUMNS` 소속이라 원래 추적 대상이다).
 *
 *   php artisan ssancarerp:import-freight-usd "경로.xlsx"           # dry-run (기본)
 *   php artisan ssancarerp:import-freight-usd "경로.xlsx" --apply
 */
class ImportFreightUsd extends Command
{
    protected $signature = 'ssancarerp:import-freight-usd
        {path : USD 운임 xlsx (A 번호 · B 구입일자 · C 차량번호 · D 차대번호 · E 운임 USD)}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 기입 (미지정 시 dry-run)}';

    protected $description = 'ssancarerp 운임비(USD) 기록칸 소급 기입 (회계 무영향, 기본 dry-run)';

    private const DATA_START = 3;

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error("파일을 읽을 수 없다: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheetByName((string) $this->option('sheet'));
        if (! $sheet) {
            $this->error('시트를 찾을 수 없다: '.$this->option('sheet'));

            return self::FAILURE;
        }

        // 수식 칸이 있다(`=1370+120` 류 405 행). 계산값을 쓴다.
        // 계산값이 없으면 0 으로 눕히지 말고 읽기 실패로 센다 — 조용한 0 은 메모 칸에서 아무도 못 본다.
        $rows = [];
        $stat = ['blank' => 0, 'unreadable' => [], 'nonpositive' => []];
        $last = $sheet->getHighestDataRow();
        for ($r = self::DATA_START; $r <= $last; $r++) {
            $plate = trim((string) $sheet->getCell('C'.$r)->getValue());
            $vin = trim((string) $sheet->getCell('D'.$r)->getValue());
            if ($plate === '' && $vin === '') {
                continue;
            }
            $raw = $sheet->getCell('E'.$r)->getValue();
            if ($raw === null || $raw === '') {
                $stat['blank']++;

                continue;
            }
            $val = is_string($raw) && str_starts_with($raw, '=')
                ? $sheet->getCell('E'.$r)->getCalculatedValue()
                : $raw;
            if (! is_numeric($val)) {
                $stat['unreadable'][] = "행{$r} {$plate}";

                continue;
            }
            if ((float) $val <= 0) {
                $stat['nonpositive'][] = $plate.'='.$val;

                continue;
            }
            $rows[] = ['plate' => $plate, 'vin' => $vin, 'usd' => (int) round((float) $val)];
        }

        // 차대번호 우선 매칭 — 운영에 같은 번호판이 여러 행 존재할 수 있다.
        $byVin = Vehicle::withTrashed()->whereNotNull('nice_reg_vin')->where('nice_reg_vin', '<>', '')
            ->pluck('id', 'nice_reg_vin')->all();
        $byPlate = Vehicle::withTrashed()->pluck('id', 'vehicle_number')->all();

        $plan = [];
        $miss = [];
        foreach ($rows as $row) {
            $id = ($row['vin'] !== '' ? ($byVin[$row['vin']] ?? null) : null) ?? ($byPlate[$row['plate']] ?? null);
            if (! $id) {
                $miss[] = $row['plate'].' / '.$row['vin'];

                continue;
            }
            $plan[$id] = $row['usd'];
        }

        $current = DB::table('vehicles')->whereIn('id', array_keys($plan))->pluck('transport_fee_usd', 'id')->all();
        $changes = [];
        $same = 0;
        foreach ($plan as $id => $usd) {
            if ((int) ($current[$id] ?? 0) === $usd) {
                $same++;

                continue;
            }
            $changes[$id] = ['old' => $current[$id] ?? null, 'new' => $usd];
        }

        $this->newLine();
        $this->info('── 운임비(USD) 소급 기입 ──');
        $this->line(sprintf('  파일 행 %d  ·  값 있음 %d  ·  공란 %d',
            max(0, $last - self::DATA_START + 1), count($rows), $stat['blank']));
        $this->line(sprintf('  차량 매칭 %d  ·  못 찾음 %d', count($plan), count($miss)));
        $this->line(sprintf('  기입 대상 %d  ·  이미 같음 %d  ·  합계 %s USD',
            count($changes), $same, number_format(array_sum(array_column($changes, 'new')))));

        $overwrite = array_filter($changes, fn ($c) => $c['old'] !== null);
        if ($overwrite) {
            $ids = array_slice(array_keys($overwrite), 0, 10);
            $this->warn(sprintf('  ⚠️ 기존 값을 덮는 행 %d — 첫 10: %s', count($overwrite),
                implode(' · ', array_map(fn ($id) => "id{$id} {$overwrite[$id]['old']}→{$overwrite[$id]['new']}", $ids))));
        }
        if ($stat['unreadable']) {
            $this->warn('  ⚠️ 읽기 실패(수식 계산값 없음) '.count($stat['unreadable']).': '
                .implode(' · ', array_slice($stat['unreadable'], 0, 10)));
        }
        if ($stat['nonpositive']) {
            $this->warn('  ⚠️ 0 이하 → 건너뜀 '.count($stat['nonpositive']).': '
                .implode(' · ', array_slice($stat['nonpositive'], 0, 10)));
        }
        if ($miss) {
            $this->warn('  ⚠️ 차량 못 찾음 '.count($miss).': '.implode(' · ', array_slice($miss, 0, 10)));
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('dry-run 이다 — 아무것도 쓰지 않았다. 실제 기입은 --apply.');

            return self::SUCCESS;
        }

        if (! $changes) {
            $this->info('바꿀 것이 없다.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($changes));
        DB::transaction(function () use ($changes, $bar) {
            foreach ($changes as $id => $c) {
                DB::table('vehicles')->where('id', $id)->update(['transport_fee_usd' => $c['new']]);
                AuditLog::create([
                    'user_id' => null,               // 소급 적재 — 사람 행위자가 없다
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $id,
                    'action' => 'freight_usd_import',
                    'column_name' => 'transport_fee_usd',
                    'old_value' => $c['old'],
                    'new_value' => $c['new'],
                ]);
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine(2);
        $this->info('✅ 기입 완료 '.count($changes).'건 (회계 무영향 — 총판매가·미수·정산·면장 불변)');

        return self::SUCCESS;
    }
}
