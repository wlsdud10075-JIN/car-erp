<?php

/**
 * 매핑표시(기준표) 재생성기 — Desktop `0. 매핑한것` 용.
 *
 * 각 Mappings/*.php 의 config()(권위 좌표·multi 기하) + 소스 인라인 주석(한글 라벨·변수명)을
 * 읽어, 운영 heyman 템플릿 사본의 매핑 셀에 ▶/↻/⚙ 주석을 찍는다.
 * 결과는 Desktop `0. 매핑한것/재생성_YYYY-MM-DD/{매입,판매,선적,통관}/` 에 비파괴 출력.
 *
 * 실행: php scripts/generate-mapping-annotations.php
 */
require __DIR__.'/../vendor/autoload.php';

use App\Services\Documents\Mappings\ClearanceSetMapping;
use App\Services\Documents\Mappings\ContainerContractMapping;
use App\Services\Documents\Mappings\ContainerInvoicePackingMapping;
use App\Services\Documents\Mappings\DeregistrationContractMapping;
use App\Services\Documents\Mappings\DeregistrationMapping;
use App\Services\Documents\Mappings\PowerOfAttorneyMapping;
use App\Services\Documents\Mappings\RoroContractMapping;
use App\Services\Documents\Mappings\RoroInvoicePackingMapping;
use App\Services\Documents\Mappings\SalesContractMapping;
use App\Services\Documents\Mappings\SalesInvoiceMapping;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const GREEN = 'FFC6EFCE';   // ▶ 매핑
const GRAY = 'FFE7E6E6';    // ↻ 반복슬롯
const BLUE = 'FFDDEBF7';    // ⚙ 자동(수식)

$TEMPLATE_SET = 'heyman';
$OUT_ROOT = 'C:/Users/User/Desktop/system/0. 매핑한것/재생성_2026-07-01';

/** type → [Mapping class, source file, 출력폴더, 출력파일명] */
$JOBS = [
    'deregistration' => [DeregistrationMapping::class, '매입', '1. 말소 신청서_매핑표시(재생성).xlsx'],
    'deregistration_contract' => [DeregistrationContractMapping::class, '매입', '2. 말소 계약서_매핑표시(재생성).xlsx'],
    'poa' => [PowerOfAttorneyMapping::class, '매입', '3. 위임장_매핑표시(재생성).xlsx'],
    'invoice' => [SalesInvoiceMapping::class, '판매', '인보이스_매핑표시(재생성).xlsx'],
    'clearance' => [ClearanceSetMapping::class, '통관', '통관 SET_매핑표시(재생성).xlsx'],
    'container_invoice_packing' => [ContainerInvoicePackingMapping::class, '선적', '1-1 컨테이너_Invoice & packing_매핑표시(재생성).xlsx'],
    'container_contract' => [ContainerContractMapping::class, '선적', '1-2 컨테이너_CONTRACT_매핑표시(재생성).xlsx'],
    'roro_invoice_packing' => [RoroInvoicePackingMapping::class, '선적', '2-1 RORO_Invoice & packing_매핑표시(재생성).xlsx'],
    'roro_contract' => [RoroContractMapping::class, '선적', '2-2 RORO_CONTRACT_매핑표시(재생성).xlsx'],
    'sales_contract' => [SalesContractMapping::class, '판매', '판매계약서_매핑표시(재생성).xlsx'],
];

/**
 * Mapping 소스에서 라벨 추출.
 * 반환: ['cells'=>[coord=>label], 'header'=>[coord=>label], 'slot'=>[offset=>[col=>label]]]
 * label = "한글주석 | 변수명"
 */
function parseLabels(string $classFqn): array
{
    $ref = new ReflectionClass($classFqn);
    $lines = file($ref->getFileName());
    $out = ['cells' => [], 'header' => [], 'slot' => [], 'agg' => []];
    $ctx = null;        // 'cells' | 'header' | 'slot' | 'agg'
    $slotOffset = null;
    foreach ($lines as $ln) {
        $t = trim($ln);
        if (preg_match("/^'cells'\s*=>\s*\[/", $t)) {
            $ctx = 'cells';

            continue;
        }
        if (preg_match("/^'header'\s*=>\s*\[/", $t)) {
            $ctx = 'header';

            continue;
        }
        if (preg_match("/^'slotCells'\s*=>\s*\[/", $t)) {
            $ctx = 'slot';

            continue;
        }
        if (preg_match("/^'aggregates'\s*=>\s*\[/", $t)) {
            $ctx = 'agg';

            continue;
        }
        if ($ctx === 'slot' && preg_match('/^(\d+)\s*=>\s*\[/', $t, $m)) {
            $slotOffset = (int) $m[1];
            $out['slot'][$slotOffset] ??= [];

            continue;
        }
        // 항목 라인: 'KEY' => fn (...) => EXPR,   // COMMENT
        if (! preg_match("/^'([^']+)'\s*=>\s*fn\b/", $t, $km)) {
            continue;
        }
        $key = $km[1];
        $rest = preg_replace("/^'[^']+'\s*=>\s*fn\s*\([^)]*\)\s*=>\s*/", '', $t);
        $comment = '';
        if (($p = strpos($rest, '//')) !== false) {
            $comment = trim(substr($rest, $p + 2));
            $rest = substr($rest, 0, $p);
        }
        $expr = trim(rtrim(trim($rest), ','));
        $label = buildLabel($expr, $comment);
        if ($ctx === 'slot' && $slotOffset !== null) {
            $out['slot'][$slotOffset][$key] = $label;
        } elseif ($ctx === 'agg') {
            $out['agg'][$key] = $label;
        } elseif ($ctx === 'header') {
            $out['header'][$key] = $label;
        } elseif ($ctx === 'cells') {
            $out['cells'][$key] = $label;
        }
    }

    return $out;
}

/** 변수명 짧게 + 한글주석 결합 → "▶ 주석 | 변수" */
function buildLabel(string $expr, string $comment): string
{
    // 변수 축약
    $short = $expr;
    $short = preg_replace('/\$v->/', '', $short);
    $short = preg_replace('/\(\s*\$v[^)]*\)/', '', $short);   // (…$v…) 인자 제거
    $short = preg_replace('/\s+/', ' ', trim($short));
    if (mb_strlen($short) > 42) {
        $short = mb_substr($short, 0, 42).'…';
    }
    if ($comment !== '') {
        return '▶ '.$comment.'  |  '.$short;
    }

    return '▶ '.$short;
}

function paint($sheet, string $coord, string $argb, ?string $text): void
{
    $anchor = $coord;
    // 병합 앵커 해석
    [$col, $row] = Coordinate::coordinateFromString($coord);
    $ci = Coordinate::columnIndexFromString($col);
    foreach ($sheet->getMergeCells() as $range) {
        [$rs, $re] = explode(':', $range);
        [$c1, $r1] = Coordinate::coordinateFromString($rs);
        [$c2, $r2] = Coordinate::coordinateFromString($re);
        $ci1 = Coordinate::columnIndexFromString($c1);
        $ci2 = Coordinate::columnIndexFromString($c2);
        if ($ci >= $ci1 && $ci <= $ci2 && (int) $row >= (int) $r1 && (int) $row <= (int) $r2) {
            $anchor = $rs;
            break;
        }
    }
    $cell = $sheet->getCell($anchor);
    $existing = $cell->getValue();
    $isFormula = is_string($existing) && str_starts_with($existing, '=');
    $fill = $sheet->getStyle($anchor)->getFill();
    $fill->setFillType(Fill::FILL_SOLID);
    $fill->getStartColor()->setARGB($argb);
    if ($text !== null && ! $isFormula) {
        $cell->setValueExplicit($text, DataType::TYPE_STRING);
    }
}

$total = 0;
foreach ($JOBS as $type => [$class, $folder, $outName]) {
    $cfg = $class::config();
    $labels = parseLabels($class);
    $tpl = __DIR__."/../resources/templates/{$GLOBALS['TEMPLATE_SET']}/".$cfg['template'];
    $reader = IOFactory::createReaderForFile($tpl);
    $reader->setReadDataOnly(false);
    $ss = $reader->load($tpl);
    $mainSheet = $ss->getSheetByName($cfg['sheet']);

    $count = 0;

    // 1) flat cells (통관/매입/판매) — 크로스시트 'Sheet!Coord' 지원
    foreach (($cfg['cells'] ?? []) as $coord => $_) {
        $sheet = $mainSheet;
        $c = $coord;
        if (str_contains($coord, '!')) {
            [$sn, $c] = explode('!', $coord, 2);
            $sheet = $ss->getSheetByName($sn);
        }
        if (! $sheet) {
            continue;
        }
        paint($sheet, $c, GREEN, $labels['cells'][$coord] ?? '▶ (매핑)');
        $count++;
    }

    // 2) header (선적)
    foreach (($cfg['header'] ?? []) as $coord => $_) {
        paint($mainSheet, $coord, GREEN, $labels['header'][$coord] ?? '▶ (헤더)');
        $count++;
    }

    // 3) multi 슬롯 (선적) — slot0 ▶, 다음 2슬롯 ↻
    if (isset($cfg['multi'])) {
        $m = $cfg['multi'];
        foreach ($m['slotCells'] as $off => $cols) {
            foreach ($cols as $col => $_) {
                $row0 = $m['first'] + $off;
                paint($mainSheet, $col.$row0, GREEN, $labels['slot'][$off][$col] ?? '▶ (슬롯)');
                $count++;
                for ($k = 1; $k <= 2; $k++) {
                    $r = $m['first'] + $m['stride'] * $k + $off;
                    paint($mainSheet, $col.$r, GRAY, '↻ 반복슬롯 (차량 '.($k + 1).'행)');
                }
            }
        }
        // footer 집계 = ⚙ (수식 보존, 색만)
        foreach (($m['footerAggregates'] ?? []) as $f) {
            paint($mainSheet, $f['cell'], BLUE, null);
        }
        // aggregates(선택 차량 전체 합산 스칼라 푸터) = ▶ 매핑값
        foreach (($m['aggregates'] ?? []) as $coord => $_) {
            paint($mainSheet, $coord, GREEN, $labels['agg'][$coord] ?? '▶ (합산)');
            $count++;
        }
    }

    // 4) 시트 내 수식 셀 = ⚙ (데이터영역, 이미 칠해진 곳 제외) — I6/I13/D14/E27 등 자동연동 표시
    foreach ($ss->getWorksheetIterator() as $sheet) {
        if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
            continue;
        }
        $maxR = min($sheet->getHighestRow(), 60);
        $maxC = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($r = 1; $r <= $maxR; $r++) {
            for ($ci = 1; $ci <= $maxC; $ci++) {
                $co = Coordinate::stringFromColumnIndex($ci).$r;
                $val = $sheet->getCell($co)->getValue();
                if (! (is_string($val) && str_starts_with($val, '='))) {
                    continue;
                }
                $curArgb = $sheet->getStyle($co)->getFill()->getStartColor()->getARGB();
                if (in_array($curArgb, [GREEN, GRAY, BLUE], true)) {
                    continue;
                }
                paint($sheet, $co, BLUE, null);
            }
        }
    }

    $dir = "$OUT_ROOT/$folder";
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    (new Xlsx($ss))->save("$dir/$outName");
    echo str_pad($type, 28)." ▶$count → $folder/$outName".PHP_EOL;
    $total += $count;
}
echo "\n총 매핑셀 주석: $total\n출력: $OUT_ROOT\n";
