<?php

namespace App\Services;

use App\Console\Commands\ImportVehicles;
use App\Models\Salesman;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 차량 일괄적재 표준 양식(xlsx) 빌더 — vehicles:export-template 커맨드와 UI 다운로드 버튼의 단일 출처.
 *
 * 데이터 없는 빈 양식(PII·회계 0). 컬럼 위치·헤더 = ImportVehicles::MAP 를 그대로 재사용 →
 * 생성한 양식을 vehicles:import 가 무수정으로 읽는다(heyman 수출차량현황표와 동일 레이아웃).
 * Excel 데이터 유효성 검사로 입력 단계에서 서식 강제(날짜형/통화·담당자 드롭다운/금액 숫자만).
 */
class VehicleTemplateExporter
{
    public const HEADER_ROW = 2;

    public const DATA_START = 3;

    public function build(int $rows = 3000): Spreadsheet
    {
        $maxRow = max(self::DATA_START, $rows + self::DATA_START - 1);

        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('수출차량매입');

        $map = ImportVehicles::MAP;

        // 1) 헤더(2행) + 형식 힌트(1행) + 컬럼별 서식/유효성.
        $maxColIndex = 1;
        foreach ($map as $field => $def) {
            $col = $def['col'];
            $maxColIndex = max($maxColIndex, Coordinate::columnIndexFromString($col));
            $sheet->setCellValue($col.self::HEADER_ROW, $def['label']);
            $sheet->setCellValue($col.'1', $this->hint($field, $def['type']));
            $this->applyColumn($sheet, $field, $def['type'], $col, $maxRow);
        }

        // 2) 헤더(노란 강조) + 힌트 행 스타일.
        $lastCol = Coordinate::stringFromColumnIndex($maxColIndex);
        $sheet->getStyle('A'.self::HEADER_ROW.':'.$lastCol.self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0B85C']]],
        ]);
        $sheet->getStyle('A1:'.$lastCol.'1')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '8A8A8A']],
        ]);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);

        // 3) gap 컬럼(MAP 에 없는) 숨김.
        $mappedCols = array_map(fn ($d) => $d['col'], $map);
        for ($i = 1; $i <= $maxColIndex; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            if (in_array($letter, $mappedCols, true)) {
                $sheet->getColumnDimension($letter)->setWidth($this->width($letter, $map));
            } else {
                $sheet->getColumnDimension($letter)->setVisible(false);
            }
        }

        $sheet->freezePane('A'.self::DATA_START);

        $this->buildGuideSheet($ss);

        $salesmen = Salesman::orderBy('name')->pluck('name')->filter()->values()->all();
        if ($salesmen !== []) {
            $this->buildListSheet($ss, $salesmen);
            $this->applySalesmanList($sheet, $map, count($salesmen), $maxRow);
        }

        $ss->setActiveSheetIndexByName('수출차량매입');

        return $ss;
    }

    /** 컬럼별 number format + 데이터 유효성 검사. */
    private function applyColumn(Worksheet $sheet, string $field, string $type, string $col, int $maxRow): void
    {
        $range = $col.self::DATA_START.':'.$col.$maxRow;

        if ($field === 'currency') {
            $this->listValidation($sheet, $range, '"'.implode(',', ImportVehicles::VALID_CURRENCIES).'"', '통화', 'USD/JPY/EUR/GBP/CNY/KRW 중 선택');

            return;
        }

        switch ($type) {
            case 'date':
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                $dv = $this->newValidation($sheet, $range, DataValidation::TYPE_DATE, DataValidation::STYLE_STOP);
                $dv->setOperator(DataValidation::OPERATOR_BETWEEN);
                $dv->setFormula1('DATE(2000,1,1)');
                $dv->setFormula2('DATE(2100,12,31)');
                $dv->setErrorTitle('날짜 형식');
                $dv->setError("날짜는 YYYY-MM-DD 로 입력하세요. ('예정'·'미정' 등 텍스트 금지 — 미발생이면 빈칸)");
                $dv->setPromptTitle('날짜');
                $dv->setPrompt('YYYY-MM-DD (미발생/예정은 빈칸으로)');
                break;
            case 'num':
            case 'int':
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0');
                $dv = $this->newValidation($sheet, $range, DataValidation::TYPE_DECIMAL, DataValidation::STYLE_STOP);
                $dv->setOperator(DataValidation::OPERATOR_BETWEEN);
                $dv->setFormula1('-999999999999');
                $dv->setFormula2('999999999999');
                $dv->setErrorTitle('숫자만');
                $dv->setError('숫자만 입력하세요. (상태 텍스트·단위 금지)');
                break;
            case 'rrn':
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('@');
                break;
            default: // str — 차량번호/VIN/B/L 등 텍스트 보존(자동 숫자·날짜 변환 방지).
                if (in_array($field, ['vehicle_number', 'nice_reg_vin', 'bl_number'], true)) {
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('@');
                }
        }
    }

    private function newValidation(Worksheet $sheet, string $range, string $type, string $style): DataValidation
    {
        // 새 객체로 생성해 range 에만 등록. getCell()->getDataValidation() 은 단일셀에도
        // 중복 등록돼 Excel '복구' 경고(overlapping dataValidation)를 유발하므로 쓰지 않는다.
        $dv = new DataValidation;
        $dv->setType($type);
        $dv->setErrorStyle($style);
        $dv->setAllowBlank(true);
        $dv->setShowInputMessage(true);
        $dv->setShowErrorMessage(true);
        $dv->setShowDropDown(true);
        $sheet->setDataValidation($range, $dv);

        return $dv;
    }

    private function listValidation(Worksheet $sheet, string $range, string $formula1, string $title, string $msg): void
    {
        $dv = $this->newValidation($sheet, $range, DataValidation::TYPE_LIST, DataValidation::STYLE_STOP);
        $dv->setFormula1($formula1);
        $dv->setErrorTitle($title);
        $dv->setError($msg);
        $dv->setPromptTitle($title);
        $dv->setPrompt($msg);
    }

    /** 담당자(J) 드롭다운 — 숨김 시트 범위 참조(목록 길이 무관). */
    private function applySalesmanList(Worksheet $sheet, array $map, int $count, int $maxRow): void
    {
        if (! isset($map['salesman'])) {
            return;
        }
        $col = $map['salesman']['col'];
        $range = $col.self::DATA_START.':'.$col.$maxRow;
        $this->listValidation($sheet, $range, '_담당자!$A$1:$A$'.$count, '담당자', '등록된 담당자명을 선택하세요. (미등록은 import 시 차단)');
    }

    private function buildListSheet(Spreadsheet $ss, array $salesmen): void
    {
        $list = $ss->createSheet();
        $list->setTitle('_담당자');
        foreach ($salesmen as $i => $name) {
            $list->setCellValue('A'.($i + 1), $name);
        }
        $list->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    }

    private function buildGuideSheet(Spreadsheet $ss): void
    {
        $g = $ss->createSheet();
        $g->setTitle('작성안내');
        $lines = [
            ['차량 일괄적재 양식 — 작성 안내', true],
            ['', false],
            ['1. [수출차량매입] 시트의 노란 헤더 칸 아래(3행부터) 한 줄에 차량 한 대씩 입력합니다.', false],
            ['2. 날짜는 반드시 YYYY-MM-DD (예: 2026-05-10). "5월 예정"·"05-10"·"선적중" 같은 텍스트는 넣지 마세요.', false],
            ['   - 아직 발생하지 않은 일자(예정/미정)는 그냥 빈칸으로 두세요.', false],
            ['3. 진행상태(매입중·선적중·통관완료 등)는 입력하지 않습니다. 시스템이 입력값으로 자동 계산합니다.', false],
            ['4. 통화는 드롭다운에서 USD/JPY/EUR/GBP/CNY/KRW 중 선택합니다.', false],
            ['5. 담당자는 드롭다운(등록된 담당자)에서 선택합니다. 미등록 담당자는 적재가 차단되니 먼저 등록 요청하세요.', false],
            ['6. 금액 칸에는 숫자만 입력합니다. 콤마/원 표시는 자동 처리되며, 없으면 빈칸 또는 0.', false],
            ['7. 판매금액을 입력하면 통화·환율·바이어가 함께 있어야 판매가 반영됩니다. (없으면 매입만 적재)', false],
            ['8. 차대번호(VIN)가 있으면 우선 식별키로 쓰입니다. 같은 차량을 재업로드해도 중복 생성되지 않습니다.', false],
            ['', false],
            ['※ 계산 항목(마진·미수·정산 등)은 양식에 없습니다. 입력값으로 시스템이 자동 산출합니다.', false],
        ];
        foreach ($lines as $i => [$text, $bold]) {
            $cell = 'A'.($i + 1);
            $g->setCellValue($cell, $text);
            if ($bold) {
                $g->getStyle($cell)->getFont()->setBold(true)->setSize(13);
            }
        }
        $g->getColumnDimension('A')->setWidth(110);
    }

    private function hint(string $field, string $type): string
    {
        return match (true) {
            $field === 'currency' => 'USD/JPY/EUR/GBP/CNY/KRW',
            $field === 'salesman' => '담당자명(등록필수)',
            $field === 'exchange_rate' => '숫자 (원/외화)',
            $type === 'date' => 'YYYY-MM-DD',
            $type === 'num', $type === 'int' => '숫자만',
            $type === 'rrn' => '######-#######',
            default => '',
        };
    }

    private function width(string $letter, array $map): float
    {
        foreach ($map as $def) {
            if ($def['col'] === $letter) {
                return match ($def['type']) {
                    'date' => 12,
                    'num', 'int' => 13,
                    'rrn' => 16,
                    default => 16,
                };
            }
        }

        return 12;
    }
}
