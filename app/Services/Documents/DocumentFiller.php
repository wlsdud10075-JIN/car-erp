<?php

namespace App\Services\Documents;

use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
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
    /** @var Collection<int, Vehicle> */
    private Collection $vehicles;

    private Vehicle $primary;

    /**
     * 단일 차량 또는 차량 컬렉션(선적 다중차량) 모두 수용.
     * 다중차량은 'multi' 매핑(선적 4종)에서만 의미가 있고, 그 외 type 은 첫 차량만 사용.
     */
    public function __construct(Vehicle|Collection $vehicles)
    {
        $this->vehicles = ($vehicles instanceof Vehicle ? collect([$vehicles]) : $vehicles)->values();
        $this->primary = $this->vehicles->first();
    }

    /**
     * type 의 템플릿을 로드해 노란칸 자동기입 후 Spreadsheet 반환.
     */
    public function spreadsheet(string $type): Spreadsheet
    {
        $config = $this->configFor($type);

        // 테넌트별 양식 세트 (회사별 회사정보 인쇄본). default='system'(ssancar) → .env COMPANY_TEMPLATE_SET 로 분기.
        $set = config('company.template_set', 'system');
        $path = resource_path('templates/'.$set.'/'.$config['template']);
        $spreadsheet = IOFactory::load($path);

        // 1) 모든 visible 시트의 노란 fill 제거 (수식·공란 포함 전부)
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }
            $this->clearYellowFill($sheet);
            $this->stripHyperlinks($sheet);
        }

        $sheet = isset($config['sheet'])
            ? $spreadsheet->getSheetByName($config['sheet'])
            : $spreadsheet->getActiveSheet();

        // 2) 도장/서명 오버레이 — 업로드된 회사 도장이 있으면 fill 前에 얹는다.
        //    fillMulti 의 removeRow 前이어야 선적 문서에서 도장이 트림된 위치로 함께 이동(실측).
        $this->applyStamps($sheet, $config);

        // 3) 매핑 기입 — 'multi'(선적 다중차량) vs 단일('cells')
        if (isset($config['multi'])) {
            $this->fillMulti($sheet, $config);
        } else {
            foreach ($config['cells'] as $coord => $resolver) {
                $this->writeCell($sheet, $coord, $resolver($this->primary));
            }
        }

        // 4) ⑤ 상호 헤더 — 지정 시트/셀 RichText 첫 줄(상호)을 기능설정 브랜드(대문자)로 치환.
        if (isset($config['brandHeader'])) {
            $this->applyBrandHeader($spreadsheet, $config['brandHeader']);
        }

        return $spreadsheet;
    }

    /**
     * 도장/서명 오버레이 — config['stamps'] 의 각 슬롯에 대해, 기능설정에서 업로드된
     * 회사(template_set)별 이미지가 있으면 해당 앵커의 기존 도장을 제거하고 그 자리에 얹는다.
     * 업로드본이 없으면 양식 기본 도장 유지(하위호환).
     *
     * path 기반 Drawing 사용 = 업로드 PNG 의 투명도(빨간 원형 직인)를 GD 재인코딩 없이 보존(실측).
     * fill 前 호출이라 선적 문서의 removeRow 가 이 Drawing 도 함께 트림 위치로 이동시킨다.
     *
     * stamps 슬롯 스키마: ['role' => 'signature', 'anchor' => 'A60', 'width' => 612, 'height' => 179]
     */
    private function applyStamps(Worksheet $sheet, array $config): void
    {
        if (empty($config['stamps'])) {
            return;
        }

        $set = config('company.template_set', 'system');
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));

        foreach ($config['stamps'] as $stamp) {
            $path = Setting::get("stamp_{$set}_{$stamp['role']}");
            if (! $path || ! $disk->exists($path)) {
                continue;   // 업로드본 없음 → 양식 기본 도장 유지
            }
            $this->overlayStamp($sheet, $stamp, $disk->get($path), pathinfo($path, PATHINFO_EXTENSION) ?: 'png');
        }
    }

    /**
     * 앵커의 기존 Drawing 제거 + 업로드 이미지를 같은 앵커·크기로 삽입.
     * 임시파일은 streamDownload 저장(요청 종료 시점) 까지 살아있어야 하므로 shutdown 에서 정리.
     */
    private function overlayStamp(Worksheet $sheet, array $stamp, string $bytes, string $ext): void
    {
        $anchor = $stamp['anchor'];

        // 같은 앵커의 기존 도장 제거 (ArrayObject — 뒤에서부터 unset)
        $drawings = $sheet->getDrawingCollection();
        for ($i = count($drawings) - 1; $i >= 0; $i--) {
            if (isset($drawings[$i]) && $drawings[$i]->getCoordinates() === $anchor) {
                unset($drawings[$i]);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'stamp_').'.'.$ext;
        file_put_contents($tmp, $bytes);
        register_shutdown_function(static fn () => @unlink($tmp));

        // 업로드 원본 비율을 유지하며 슬롯 박스(width×height) 안에 맞춤(contain).
        // 박스 비율로 강제하면 정사각 도장이 가로로 찌그러짐 → 원본 종횡비 보존 후 축소.
        [$boxW, $boxH] = [(int) $stamp['width'], (int) $stamp['height']];
        $info = @getimagesizefromstring($bytes);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $scale = min($boxW / $info[0], $boxH / $info[1]);
            [$w, $h] = [(int) round($info[0] * $scale), (int) round($info[1] * $scale)];
        } else {
            [$w, $h] = [$boxW, $boxH];
        }

        $drawing = new Drawing;
        $drawing->setPath($tmp);
        $drawing->setCoordinates($anchor);
        $drawing->setResizeProportional(false);
        $drawing->setWidth(max(1, $w));
        $drawing->setHeight(max(1, $h));
        $drawing->setWorksheet($sheet);
    }

    /**
     * ⑤ 인보이스 등 상호 셀 — RichText 첫 줄을 기능설정 브랜드(sidebar_brand) 대문자 + ' LTD.,' 로 치환.
     * 첫 run 의 첫 줄만 바꿔 나머지(주소·TEL·FAX·EMAIL)·서식 보존.
     */
    private function applyBrandHeader(Spreadsheet $spreadsheet, array $hdr): void
    {
        $sheet = $spreadsheet->getSheetByName($hdr['sheet']);
        if (! $sheet) {
            return;
        }
        $brand = strtoupper((string) (Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR'));
        $firstLine = $brand.' LTD.,';

        $cell = $sheet->getCell($hdr['cell']);
        $val = $cell->getValue();
        if ($val instanceof RichText) {
            $elements = $val->getRichTextElements();
            if (! empty($elements)) {
                $t = $elements[0]->getText();
                $nl = strpos($t, "\n");
                $elements[0]->setText($nl !== false ? $firstLine.substr($t, $nl) : $firstLine);
            }
        } elseif (is_string($val) && $val !== '') {
            $nl = strpos($val, "\n");
            $cell->setValue($nl !== false ? $firstLine.substr($val, $nl) : $firstLine);
        }
    }

    /**
     * 선적 다중차량 — 30슬롯 확장 양식에 선택 N대를 채우고 미사용 슬롯을 removeRow 로 트림.
     *
     * 순서가 핵심: header·footer 기입(원본 좌표) → 슬롯 N대 → footer 집계를 채운영역 range 로
     * 재기록 → removeRow. 재기록·기입을 removeRow '전'에 끝내야 (참조가 전부 제거구간 위라)
     * 행 삭제가 수식을 깨지 않고 footer 값이 위로 따라 올라간다. (removeRow 는 range 자동축소 X — 실측)
     */
    private function fillMulti(Worksheet $sheet, array $config): void
    {
        foreach ($config['header'] as $coord => $resolver) {
            $this->writeCell($sheet, $coord, $resolver($this->primary));
        }

        $m = $config['multi'];
        $first = $m['first'];
        $stride = $m['stride'];
        $capacity = $m['count'];
        $n = min($this->vehicles->count(), $capacity);

        for ($i = 0; $i < $n; $i++) {
            $v = $this->vehicles[$i];
            $base = $first + $i * $stride;
            foreach ($m['slotCells'] as $offset => $cols) {
                foreach ($cols as $col => $resolver) {
                    $this->writeCell($sheet, $col.($base + $offset), $resolver($v));
                }
            }
        }

        $regionStart = $first;
        $regionEnd = $first + $n * $stride - 1;
        foreach ($m['footerAggregates'] as $agg) {
            $sheet->getCell($agg['cell'])->setValueExplicit(
                sprintf($agg['fmt'], $regionStart, $regionEnd),
                DataType::TYPE_FORMULA,
            );
        }

        if ($n < $capacity) {
            $sheet->removeRow($first + $n * $stride, ($capacity - $n) * $stride);
            $sheet->garbageCollect();   // 트림 후 시트 dimension 정정 (꼬리 빈 행 제거)
        }
    }

    /**
     * 파일명 (다운로드용). 다중차량이면 "{라벨}_{N}대_{날짜}".
     */
    public function filename(string $type): string
    {
        $config = $this->configFor($type);
        $label = $config['label'] ?? $type;

        if ($this->vehicles->count() > 1) {
            return sprintf('%s_%d대_%s.xlsx', $label, $this->vehicles->count(), now()->format('Ymd'));
        }

        return sprintf('%s_%s_%s.xlsx', $label, $this->primary->vehicle_number ?: $this->primary->id, now()->format('Ymd'));
    }

    /**
     * 노란 셀 정리 — fill 제거 + 샘플값 비움(수식은 보존).
     * 노란색 = "기입 위치 표식". 매핑 안 된 노란 셀의 템플릿 샘플(예: 매매업번호,
     * 선적 2~4번째 차량행)을 비워야 생성본에 샘플이 안 남는다. 매핑된 셀은 이후 writeCell 이 덮어씀.
     */
    private function clearYellowFill(Worksheet $sheet): void
    {
        $maxRow = $sheet->getHighestRow();
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $blanked = [];

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $coord = Coordinate::stringFromColumnIndex($col).$row;
                $fill = $sheet->getStyle($coord)->getFill();
                if ($fill->getFillType() !== Fill::FILL_SOLID || ! $this->isYellow($fill->getStartColor()->getRGB())) {
                    continue;
                }
                $fill->setFillType(Fill::FILL_NONE);

                // 샘플값 제거 (병합앵커 기준 1회, 수식은 보존)
                $anchor = $this->mergeAnchor($sheet, $coord);
                if (isset($blanked[$anchor])) {
                    continue;
                }
                $blanked[$anchor] = true;
                $val = $sheet->getCell($anchor)->getValue();
                if (! (is_string($val) && str_starts_with($val, '='))) {
                    $sheet->getCell($anchor)->setValueExplicit(null, DataType::TYPE_NULL);
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
            // Phase 2 — 판매 인보이스
            'invoice' => Mappings\SalesInvoiceMapping::class,
            // Phase 3 — 통관 SET (구매리스트 마스터 → 6시트 수식 자동연동)
            'clearance' => Mappings\ClearanceSetMapping::class,
            // Phase 4 — 선적 4종 (우선 1대=1행)
            'container_invoice_packing' => Mappings\ContainerInvoicePackingMapping::class,
            'container_contract' => Mappings\ContainerContractMapping::class,
            'roro_invoice_packing' => Mappings\RoroInvoicePackingMapping::class,
            'roro_contract' => Mappings\RoroContractMapping::class,
            default => throw new \InvalidArgumentException('미지원 서류 type: '.$type),
        };

        return $mapping::config();
    }
}
