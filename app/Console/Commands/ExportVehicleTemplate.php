<?php

namespace App\Console\Commands;

use App\Models\Salesman;
use App\Services\VehicleTemplateExporter;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 차량 일괄적재 표준 양식(xlsx) export — CLI 진입점.
 *
 * 양식 빌드는 App\Services\VehicleTemplateExporter 단일 출처. UI 다운로드 버튼
 * (VehicleTemplateController)도 같은 서비스를 쓴다.
 *
 *   php artisan vehicles:export-template
 *   php artisan vehicles:export-template "C:/Users/User/Desktop/ssancar-차량적재양식.xlsx" --rows=5000
 */
class ExportVehicleTemplate extends Command
{
    protected $signature = 'vehicles:export-template
        {path? : 저장 경로 (기본=storage/app/차량적재양식.xlsx)}
        {--rows=3000 : 유효성검사·서식 적용 데이터 행 수}';

    protected $description = '차량 일괄적재 표준 양식(xlsx) 생성 — 데이터 유효성 검사 + 작성안내 포함';

    public function handle(): int
    {
        $path = $this->argument('path') ?: storage_path('app/차량적재양식.xlsx');
        $rows = (int) $this->option('rows');

        $ss = (new VehicleTemplateExporter)->build($rows);

        @mkdir(dirname($path), 0775, true);
        (new Xlsx($ss))->save($path);

        $this->info('✅ 표준 양식 생성: '.$path);
        $this->line('  데이터 컬럼 '.count(ImportVehicles::MAP).'개 · 입력행 '.max(1, $rows).' · 담당자 드롭다운 '.Salesman::count().'명');
        $this->line('  채운 뒤: php artisan vehicles:import "'.$path.'" --dry-run');

        return self::SUCCESS;
    }
}
