<?php

namespace App\Services\Documents;

use App\Models\Vehicle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * system 폴더 xlsx 양식의 "노란 배경 셀"에만 차량 데이터를 자동기입하는 엔진.
 *
 * 설계 (2026-05-24 딥인터뷰 확정):
 * - 노란 배경 = 템플릿의 "기입 위치 표식". 생성 시 값 채우고 노란 fill 은 제거(깔끔한 최종본).
 * - 셀 분기 (advisor 권고):
 *     · 노란 + 수식(=)        → 값 보존, fill 만 제거 (통관 SET 의 =구매리스트! cascade 보존)
 *     · 노란 + 매핑 리터럴     → 매핑값 기입, fill 제거
 *     · 노란 + 매핑 없음       → 공란, fill 제거
 * - 매핑은 type 별 데이터(좌표 → 클로저). 엔진은 generic — 새 문서는 mapping 추가만.
 */
class DocumentFiller
{
    public function __construct(private Vehicle $vehicle) {}

    /**
     * type 의 템플릿을 로드해 노란칸 자동기입 후 Spreadsheet 반환.
     */
    public function spreadsheet(string $type): Spreadsheet
    {
        $config = $this->configFor($type);

        $path = resource_path('templates/system/'.$config['template']);
        $spreadsheet = IOFactory::load($path);

        // 1) 모든 visible 시트의 노란 fill 제거 (수식·공란 포함 전부)
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }
            $this->clearYellowFill($sheet);
            $this->stripHyperlinks($sheet);
        }

        // 2) 매핑된 리터럴 셀에만 값 기입 (수식 셀은 자동 skip)
        $sheet = isset($config['sheet'])
            ? $spreadsheet->getSheetByName($config['sheet'])
            : $spreadsheet->getActiveSheet();

        foreach ($config['cells'] as $coord => $resolver) {
            $this->writeCell($sheet, $coord, $resolver($this->vehicle));
        }

        return $spreadsheet;
    }

    /**
     * 차량별 파일명 (다운로드용).
     */
    public function filename(string $type): string
    {
        $config = $this->configFor($type);
        $label = $config['label'] ?? $type;

        return sprintf('%s_%s_%s.xlsx', $label, $this->vehicle->vehicle_number ?: $this->vehicle->id, now()->format('Ymd'));
    }

    private function clearYellowFill(Worksheet $sheet): void
    {
        $maxRow = $sheet->getHighestRow();
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $coord = Coordinate::stringFromColumnIndex($col).$row;
                $fill = $sheet->getStyle($coord)->getFill();
                if ($fill->getFillType() === Fill::FILL_SOLID && $this->isYellow($fill->getStartColor()->getRGB())) {
                    $fill->setFillType(Fill::FILL_NONE);
                }
            }
        }
    }

    /**
     * 하이퍼링크 제거 — 운영 환경 외부링크(WebDAV file:// 등) 잔재가 Xlsx writer 를
     * "Invalid parameters" 로 깨뜨림. 서류 양식엔 클릭 링크 불필요 → 전부 제거.
     */
    private function stripHyperlinks(Worksheet $sheet): void
    {
        foreach (array_keys($sheet->getHyperlinkCollection()) as $coord) {
            $sheet->setHyperlink($coord, null);
        }
    }

    private function isYellow(?string $rgb): bool
    {
        if (! is_string($rgb) || $rgb === '') {
            return false;
        }
        // 8자리(ARGB)면 알파 제거
        if (strlen($rgb) === 8) {
            $rgb = substr($rgb, 2);
        }
        if (strlen($rgb) !== 6) {
            return false;
        }
        $r = hexdec(substr($rgb, 0, 2));
        $g = hexdec(substr($rgb, 2, 2));
        $b = hexdec(substr($rgb, 4, 2));

        // 노랑 계열: R·G 높고 B 낮음. 흰색(전부 높음) 제외.
        return $r > 180 && $g > 170 && $b < 160 && ! ($r > 235 && $g > 235 && $b > 235);
    }

    /**
     * 셀 1개 기입 — 병합앵커 해석 + 수식 보존 + 공란 skip + 스타일 보존.
     */
    private function writeCell(Worksheet $sheet, string $coord, mixed $value): void
    {
        $coord = $this->mergeAnchor($sheet, $coord);

        // 수식 셀은 절대 덮어쓰지 않음 (cascade 보존)
        $existing = $sheet->getCell($coord)->getValue();
        if (is_string($existing) && str_starts_with($existing, '=')) {
            return;
        }

        // 공란(null/'')은 기입하지 않음 — 빈 칸 유지
        if ($value === null || $value === '') {
            return;
        }

        if ($value instanceof \DateTimeInterface) {
            // 템플릿 셀이 이미 날짜 서식이라 가정 — Excel serial 로 기입
            $sheet->getCell($coord)->setValue(ExcelDate::PHPToExcel($value));

            return;
        }

        if (is_int($value) || is_float($value)) {
            $sheet->getCell($coord)->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return;
        }

        // 문자열 — 숫자처럼 보여도 텍스트로 박제 (차량번호·VIN·주민번호 등 형식 보존)
        $sheet->getCell($coord)->setValueExplicit((string) $value, DataType::TYPE_STRING);
    }

    /**
     * 좌표가 병합 범위에 속하면 그 범위의 좌상단(앵커) 반환. 아니면 그대로.
     */
    private function mergeAnchor(Worksheet $sheet, string $coord): string
    {
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            [$col, $row] = Coordinate::indexesFromString($coord);
            if ($col >= $start[0] && $col <= $end[0] && $row >= $start[1] && $row <= $end[1]) {
                return Coordinate::stringFromColumnIndex($start[0]).$start[1];
            }
        }

        return $coord;
    }

    /**
     * type → Mapping 클래스. 매핑 = 데이터(각 Mapping::config() 가 좌표→클로저 반환).
     * 엔진은 generic — 새 서류는 Mappings/ 에 클래스 추가만.
     *
     * @return array{template:string, sheet?:string, label?:string, cells:array<string, callable>}
     */
    private function configFor(string $type): array
    {
        $mapping = match ($type) {
            // Phase 1 — 매입 3종
            'deregistration' => Mappings\DeregistrationMapping::class,
            'deregistration_contract' => Mappings\DeregistrationContractMapping::class,
            'poa' => Mappings\PowerOfAttorneyMapping::class,
            default => throw new \InvalidArgumentException('미지원 서류 type: '.$type),
        };

        return $mapping::config();
    }
}
