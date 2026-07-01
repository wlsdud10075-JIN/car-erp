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

        // 테넌트별 양식 세트 (회사별 회사정보 인쇄본). 기능설정 토글(company_template_set) 우선, .env fallback.
        $set = Setting::companyTemplateSet();
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

        // 2) 도장/서명/로고 오버레이 — 업로드된 회사 이미지가 있으면 fill 前에 얹는다.
        //    fillMulti 의 removeRow 前이어야 선적 문서에서 도장이 트림된 위치로 함께 이동(실측).
        $this->applyStamps($spreadsheet, $type);

        // 3) 매핑 기입 — 'multi'(선적 다중차량) vs 단일('cells')
        if (isset($config['multi'])) {
            $this->fillMulti($sheet, $config);
        } else {
            foreach ($config['cells'] as $coord => $resolver) {
                // 좌표에 'Sheet!Cell' 형태면 해당 시트로 기입 (없으면 기본 $config['sheet']).
                //   통관 SET 처럼 한 매핑이 마스터 외 다른 시트(Travel 인보이스 등)도 채울 때 사용.
                $target = $sheet;
                $cell = $coord;
                if (str_contains($coord, '!')) {
                    [$sheetName, $cell] = explode('!', $coord, 2);
                    $target = $spreadsheet->getSheetByName($sheetName);
                    if (! $target) {
                        continue;   // 지정 시트 없으면 skip — 마스터 시트 오기입 방지
                    }
                }
                $this->writeCell($target, $cell, $resolver($this->primary));
            }
        }

        // 3-1) clearCells — 비노란 라벨 등 강제 공란 (예: DEPOSIT 라벨 제거).
        foreach ($config['clearCells'] ?? [] as $coord) {
            $sheet->getCell($coord)->setValueExplicit(null, DataType::TYPE_NULL);
        }

        // 3-2) 통화 적응 — 판매통화에 맞춰 $ 서식·"Dollar" 라벨을 전 visible 시트에서 치환.
        //   (통관 SET 처럼 금액이 여러 시트에 흩어진 경우까지 커버. $ 서식 셀만 건드려 안전.)
        if ($config['currencyAware'] ?? false) {
            foreach ($spreadsheet->getWorksheetIterator() as $cs) {
                if ($cs->getSheetState() === Worksheet::SHEETSTATE_VISIBLE) {
                    $this->applyCurrency($cs, $this->primary);
                }
            }
        }

        // 4) ⑤ 상호 헤더 — 지정 시트/셀 RichText 첫 줄(상호)을 기능설정 브랜드(대문자)로 치환.
        if (isset($config['brandHeader'])) {
            $this->applyBrandHeader($spreadsheet, $config['brandHeader']);
        }

        return $spreadsheet;
    }

    /**
     * 도장/서명/로고 오버레이 — StampSlots::for(type) 의 각 슬롯에 대해, 기능설정에서 업로드된
     * 회사(template_set)별 role 이미지가 있으면 슬롯 시트의 앵커 기존 도장을 제거하고 그 자리에 얹는다.
     * 위치/크기는 슬롯 기본값 + `stamp_pos_{set}_{type}_{key}`(dx,dy,w,h) override. 업로드본 없으면 양식 기본 유지.
     *
     * path 기반 Drawing = 업로드 PNG 투명도(빨간 직인) GD 재인코딩 없이 보존(실측).
     * fill(fillMulti removeRow) 前 호출이라 선적 문서에서 도장이 트림된 위치로 함께 이동.
     */
    private function applyStamps(Spreadsheet $spreadsheet, string $type): void
    {
        $slots = StampSlots::for($type);
        if (! $slots) {
            return;
        }

        $set = Setting::companyTemplateSet();
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));

        foreach ($slots as $slot) {
            $path = Setting::get("stamp_{$set}_{$slot['role']}");
            if (! $path || ! $disk->exists($path)) {
                continue;   // 해당 role 업로드본 없음 → 양식 기본 도장 유지
            }
            $sheet = $spreadsheet->getSheetByName($slot['sheet']);
            if (! $sheet) {
                continue;
            }
            // 양식에 박힌 기본 도장이 슬롯 앵커와 다른 위치(예: 서명 baked A60 ↔ 슬롯 A62)에 있으면
            // 업로드본 추가 전에 그 위치도 제거 — 안 그러면 이중 도장(jin 2026-06-25 A62 이동분 보정).
            foreach ($slot['clearAnchors'] ?? [] as $clearAnchor) {
                $this->removeDrawingsAt($sheet, $clearAnchor);
            }
            $pos = StampSlots::position($set, $type, $slot);
            $this->overlayStamp($sheet, $slot['anchor'], $disk->get($path), pathinfo($path, PATHINFO_EXTENSION) ?: 'png', $pos, (bool) ($slot['exact'] ?? false));
        }
    }

    /**
     * 앵커의 기존 Drawing 제거 + 업로드 이미지를 앵커+오프셋(dx,dy) 위치, 박스(w,h) 안 비율맞춤으로 삽입.
     * $exact=true 면 비율맞춤 없이 박스(w,h) 크기 그대로(서명을 지정 cm 로 정확히 — jin 2026-06-25).
     * 임시파일은 streamDownload 저장(요청 종료 시점) 까지 살아있어야 하므로 shutdown 에서 정리.
     */
    private function overlayStamp(Worksheet $sheet, string $anchor, string $bytes, string $ext, array $pos, bool $exact = false): void
    {
        // 같은 앵커의 기존 도장 제거
        $this->removeDrawingsAt($sheet, $anchor);

        $tmp = tempnam(sys_get_temp_dir(), 'stamp_').'.'.$ext;
        file_put_contents($tmp, $bytes);
        register_shutdown_function(static fn () => @unlink($tmp));

        // 박스(w×h) 안에 원본 비율 유지 맞춤(contain) — 정사각 직인이 가로로 안 찌그러지게.
        // exact=true(서명 지정 cm)면 비율맞춤 없이 박스 크기 그대로 사용.
        [$boxW, $boxH] = [max(1, (int) $pos['w']), max(1, (int) $pos['h'])];
        $info = @getimagesizefromstring($bytes);
        if ($exact) {
            [$w, $h] = [$boxW, $boxH];
        } elseif ($info && $info[0] > 0 && $info[1] > 0) {
            $scale = min($boxW / $info[0], $boxH / $info[1]);
            [$w, $h] = [(int) round($info[0] * $scale), (int) round($info[1] * $scale)];
        } else {
            [$w, $h] = [$boxW, $boxH];
        }

        $drawing = new Drawing;
        $drawing->setPath($tmp);
        $drawing->setCoordinates($anchor);
        $drawing->setOffsetX(max(0, (int) $pos['dx']));
        $drawing->setOffsetY(max(0, (int) $pos['dy']));
        $drawing->setResizeProportional(false);
        $drawing->setWidth(max(1, $w));
        $drawing->setHeight(max(1, $h));
        $drawing->setWorksheet($sheet);
    }

    /** 지정 앵커에 걸린 기존 Drawing 제거 (ArrayObject — 뒤에서부터 unset). */
    private function removeDrawingsAt(Worksheet $sheet, string $anchor): void
    {
        $drawings = $sheet->getDrawingCollection();
        for ($i = count($drawings) - 1; $i >= 0; $i--) {
            if (isset($drawings[$i]) && $drawings[$i]->getCoordinates() === $anchor) {
                unset($drawings[$i]);
            }
        }
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
            // 통화서식 복제 — 통화 없는 금액칸(예: 단가 H)에 옆칸(금액 I)의 $서식만 입혀
            //   뒤이은 applyCurrency 가 통화기호로 변환하게 한다(슬롯 main 행 기준).
            //   ⚠ 원본 서식을 먼저 변수로 읽어야 함 — getStyle() 중첩 호출은 supervisor 가 꼬여
            //      setFormatCode 가 엉뚱한 셀에 적용됨(실측). (번호서식만 복제, 테두리/정렬은 H 유지)
            foreach ($m['currencyMirror'] ?? [] as $target => $source) {
                $srcFmt = $sheet->getStyle($source.$base)->getNumberFormat()->getFormatCode();
                $sheet->getStyle($target.$base)->getNumberFormat()->setFormatCode($srcFmt);
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

        // 선택 차량 '전체' 합산 스칼라 푸터(운임·기타·입금·합계·잔금 등). header(primary만) 로는
        // 표현 불가한, 표에 없는 필드의 컬렉션 집계. footer 좌표에 '값'으로 기입 → 참조가 없어
        // 뒤이은 removeRow 가 위로 당겨도 안전(수식 footer 는 cross-ref 깨지므로 값으로).
        foreach ($m['aggregates'] ?? [] as $coord => $resolver) {
            $this->writeCell($sheet, $coord, $resolver($this->vehicles));
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
     * 판매통화 적응 — 템플릿이 USD($) 고정이라, 차량 판매통화에 맞춰
     *   ① 금액 셀 number format 의 '$' → 통화기호(€·¥·£·₩)
     *   ② "Dollar Rate" 등 라벨 텍스트의 'Dollar' → 통화코드(EUR 등)
     * USD 는 템플릿 그대로(무변경). currencyAware 매핑에서만 호출.
     */
    private function applyCurrency(Worksheet $sheet, Vehicle $vehicle): void
    {
        $cur = $vehicle->currency ?: 'USD';
        if ($cur === 'USD') {
            return;
        }
        $symbol = match ($cur) {
            'EUR' => '€', 'JPY' => '¥', 'GBP' => '£', 'CNY' => '¥', 'KRW' => '₩',
            default => $cur.' ',
        };

        foreach ($sheet->getCoordinates(false) as $coord) {
            // ① 금액 서식의 '$' → 통화기호.
            //   단, 날짜 로케일 토큰 `[$-409]`(영문등록증 등록증날짜 등)의 '$'는 건드리면 안 됨
            //   — 깨지면 Excel 이 서식을 못 읽어 날짜가 serial(예: 43705)로 표시됨. '$' 뒤가 '-'면 로케일이라 제외.
            $fmt = $sheet->getStyle($coord)->getNumberFormat()->getFormatCode();
            if ($fmt !== null && str_contains($fmt, '$')) {
                $converted = preg_replace('/\$(?!-)/', $symbol, $fmt);
                // 멀티바이트 통화기호(€·¥·£·₩) 앞의 이스케이프 백슬래시 제거. 양식의 `\$#,##0` 가
                // `\€#,##0` 이 되면 Excel 은 `\` 를 1바이트만 이스케이프 → 멀티바이트 기호 서식이 깨져
                // 셀이 빈칸/기호누락으로 표시됨(실측 EUR 통관SET). 백슬래시 빼고 `€#,##0` 로.
                $converted = str_replace('\\'.$symbol, $symbol, $converted);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode($converted);
            }
            // ② "Dollar" 라벨 → 통화코드 (RichText 라벨도 평문 치환)
            $val = $sheet->getCell($coord)->getValue();
            $text = $val instanceof RichText ? $val->getPlainText() : (is_string($val) ? $val : null);
            if ($text !== null && str_contains($text, 'Dollar')) {
                $sheet->getCell($coord)->setValue(str_replace('Dollar', $cur, $text));
            }
        }
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
            // 판매 계약서 (다중차량, export 전용) — 2026-07-01
            'sales_contract' => Mappings\SalesContractMapping::class,
            default => throw new \InvalidArgumentException('미지원 서류 type: '.$type),
        };

        return $mapping::config();
    }
}
