<?php

/**
 * 통관SET(Ver.2) 양식 생성 — 3사 세트(system·heyman·karaba) `clearance_set_v2.xlsx` (jin 2026-10-15).
 *
 *   php scripts/build-clearance-set-v2.php --source="C:/Users/User/Desktop/heyman/통관SET_28머2098_20261012.xlsx"            # dry-run
 *   php scripts/build-clearance-set-v2.php --source=... --apply                                                             # 생성
 *   php scripts/build-clearance-set-v2.php --verify                                                                         # 생성본 검증(배포 전·후)
 *
 * 원본(`--source`) = heymanerp 에서 생성한 Ver.1 통관SET 을 jin 이 손본 파일. 실측 차이(2026-10-15)는 두 가지뿐이다:
 *   ① 「차량인보이스」 탭 삭제   ② 「차량팩킹」 1~16행 오른쪽 블록(Reference No.·CERTIFICATE OF ORIGIN·ID·Remark·L/C) 제거 + 전폭 병합 + G9 그림 제거
 * 나머지(구매리스트 값·노란칸 없음·통화 서식·업로드 도장 오버레이)는 생성·재저장 흔적이라 가져오지 않는다.
 *
 * 그래서 **원본을 통째로 쓰지 않고 각 세트의 Ver.1 양식에서 출발**한다 — 노란칸(기입 표식)·회사정보·그 회사의 baked 직인을 지키기 위해.
 *   세트별: Ver.1 로드 → 차량인보이스 제거 → 차량팩킹을 원본 시트로 교체(같은 자리) →
 *     ⓐ 차량팩킹 A3(Shipper 블록) = 그 세트 Ver.1 값·스타일   ⓑ 그림 = 그 세트 Ver.1 의 하단 직인 1장만(원본의 heyman 업로드 오버레이 X, G9 그림은 jin 이 뺐다)
 *     ⓒ 탭 삭제로 끊긴 수식을 원래 가리키던 구매리스트 칸으로 — 차량팩킹 E17=구매리스트!D12 · Travel F21=구매리스트!B15 · F29=구매리스트!D15
 *   저장 = preCalc OFF(크로스시트 cascade 보존) + 하이퍼링크 제거(SKILLS §12).
 * 🚫 Excel 에서 손으로 고치지 말 것 — 다음 빌드가 되돌린다. 바꿀 게 생기면 원본을 고치고 이 스크립트를 다시 돌린다.
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SETS = ['system', 'heyman', 'karaba'];
const V1 = 'clearance_set.xlsx';
const V2 = 'clearance_set_v2.xlsx';
const DROP_SHEET = '차량인보이스';
const PACKING = '차량팩킹';
const TRAVEL = 'Travel Services Invoice';
/** 탭 삭제로 끊긴 수식 → 차량인보이스가 가리키던 구매리스트 칸 (Ver.1 실측: 인보이스 E17=구매리스트!D12 · H22=SUM(G22)=B15 · I22=D15). */
const REPAIRS = [
    PACKING => ['E17' => '=구매리스트!D12', 'A9' => '=구매리스트!B14', 'B22' => '=구매리스트!B5', 'D22' => '=구매리스트!D5', 'E22' => 1],
    TRAVEL => ['F21' => '=구매리스트!B15', 'F29' => '=구매리스트!D15'],
];
/** Ver.2 에 남아야 하는 시트 순서. */
const EXPECT_SHEETS = ['구매리스트', '한글등록증', '영문등록증', '말소증', PACKING, TRAVEL];

$opts = getopt('', ['source::', 'apply', 'verify']);
$root = realpath(__DIR__.'/../resources/templates');

if (isset($opts['verify'])) {
    exit(verifyAll($root) ? 0 : 1);
}

$source = $opts['source'] ?? null;
if (! $source || ! is_file($source)) {
    fwrite(STDERR, "--source=<jin 이 손본 통관SET xlsx> 가 필요합니다.\n");
    exit(1);
}
$apply = isset($opts['apply']);
echo $apply ? "════ APPLY ════\n" : "════ DRY-RUN (--apply 로 생성) ════\n";

$srcBook = IOFactory::load($source);
$srcPacking = $srcBook->getSheetByName(PACKING);
if (! $srcPacking) {
    fwrite(STDERR, '원본에 「'.PACKING."」 시트가 없습니다.\n");
    exit(1);
}
if ($srcBook->getSheetByName(DROP_SHEET)) {
    fwrite(STDERR, '원본에 아직 「'.DROP_SHEET."」 탭이 있습니다 — jin 이 지운 파일이 맞는지 확인.\n");
    exit(1);
}

foreach (SETS as $set) {
    $v1Path = "$root/$set/".V1;
    $book = IOFactory::load($v1Path);
    $v1Packing = $book->getSheetByName(PACKING);
    $v1A3 = $v1Packing->getCell('A3')->getValue();
    $v1A3Style = $v1Packing->getStyle('A3')->exportArray();
    $sealIndex = null;
    $seal = null;
    foreach ($v1Packing->getDrawingCollection() as $i => $d) {
        // 하단 직인(G33/G34, 290 폭) 만 — G9 의 작은 그림은 jin 이 지운 블록에 있던 것
        if ($d->getWidth() >= 250) {
            $seal = $d;
            $sealIndex = $i;
        }
    }
    $packIdx = $book->getIndex($v1Packing);

    echo "\n── $set\n";
    echo '  차량팩킹 A3 = 「'.mb_substr(str_replace("\n", '⏎', $v1A3 instanceof RichText ? $v1A3->getPlainText() : (string) $v1A3), 0, 40)."」\n";
    echo '  직인 = '.($seal ? $seal->getName().' @'.$seal->getCoordinates().' '.$seal->getWidth().'x'.$seal->getHeight() : '❌ 없음')."\n";
    echo '  시트: '.implode(' · ', array_map(fn (Worksheet $w) => $w->getTitle(), $book->getAllSheets())).' → 「'.DROP_SHEET.'」 제거, 「'.PACKING."」 교체(index $packIdx)\n";
    foreach (REPAIRS as $sheetName => $cells) {
        foreach ($cells as $coord => $formula) {
            echo "  $sheetName!$coord ← ".(is_int($formula) ? $formula : $formula)."\n";
        }
    }
    if (! $apply) {
        continue;
    }

    // ① 차량인보이스 제거
    $drop = $book->getSheetByName(DROP_SHEET);
    if ($drop) {
        $book->removeSheetByIndex($book->getIndex($drop));
    }
    // ② 차량팩킹 교체 — 원본 시트를 같은 자리에 (외부 시트 이관 = 셀·스타일·병합·열폭·인쇄설정)
    $packIdx = $book->getIndex($book->getSheetByName(PACKING));
    $book->removeSheetByIndex($packIdx);
    $fresh = IOFactory::load($source);   // 세트마다 원본을 새로 읽는다(이관하면 원본 워크북에서 빠지므로)
    $book->addExternalSheet($fresh->getSheetByName(PACKING), $packIdx);
    $packing = $book->getSheetByName(PACKING);
    // ⓐ Shipper 블록 = 그 세트 값
    $packing->getCell('A3')->setValue($v1A3 instanceof RichText ? clone $v1A3 : $v1A3);
    $packing->getStyle('A3')->applyFromArray($v1A3Style);
    // ⓑ 그림 = 그 세트의 하단 직인 1장만(원본의 heymanerp 업로드 오버레이는 버린다). Ver.1 직인 객체를 그대로 옮긴다(zip 경로 그대로).
    $packing->getDrawingCollection()->exchangeArray([]);
    if ($seal) {
        $seal->setWorksheet($packing, true);
    }
    // ⓒ 끊긴 수식 복구
    foreach (REPAIRS as $sheetName => $cells) {
        $ws = $book->getSheetByName($sheetName);
        foreach ($cells as $coord => $val) {
            $ws->getCell($coord)->setValue($val);
        }
    }
    foreach ($book->getWorksheetIterator() as $sh) {
        foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
            $sh->setHyperlink($hc, null);
        }
    }
    $book->setActiveSheetIndex(0);
    $writer = new Xlsx($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save("$root/$set/".V2);
    echo "  ✅ 저장 $set/".V2."\n";
    $book->disconnectWorksheets();
}

if ($apply) {
    echo "\n";
    exit(verifyAll($root) ? 0 : 1);
}

/** 생성본 검증 — 시트 구성·끊긴 수식·제거 블록·회사 블록·직인. 배포 전후 같은 검사. */
function verifyAll(string $root): bool
{
    $ok = true;
    foreach (SETS as $set) {
        $path = "$root/$set/".V2;
        if (! is_file($path)) {
            echo "❌ $set: ".V2." 없음\n";
            $ok = false;

            continue;
        }
        $bad = verifyBook(IOFactory::load($path), $set);
        echo ($bad ? '❌' : '✅')." $set/".V2.($bad ? "\n   - ".implode("\n   - ", $bad) : '')."\n";
        $ok = $ok && ! $bad;
    }

    return $ok;
}

/** @return list<string> 문제 목록(비면 정상). 테스트(ClearanceSetV2Test)가 같은 함수를 쓴다. */
function verifyBook(Spreadsheet $book, string $set): array
{
    $bad = [];
    $titles = array_map(fn (Worksheet $w) => $w->getTitle(), $book->getAllSheets());
    if ($titles !== EXPECT_SHEETS) {
        $bad[] = '시트 구성: '.implode('·', $titles);
    }
    foreach ($book->getAllSheets() as $ws) {
        foreach ($ws->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $c) {
                $v = $c->getValue();
                if (is_string($v) && str_starts_with($v, '=') && (str_contains($v, '#REF') || str_contains($v, DROP_SHEET))) {
                    $bad[] = '끊긴 수식 '.$ws->getTitle().'!'.$c->getCoordinate().' '.$v;
                }
                if ($ws->getTitle() === PACKING && is_string($v) && str_contains($v, 'CERTIFICATE OF ORIGIN')) {
                    $bad[] = '차량팩킹 CO 블록 잔존 '.$c->getCoordinate();
                }
            }
        }
    }
    $packing = $book->getSheetByName(PACKING);
    if ($packing) {
        $a3 = $packing->getCell('A3')->getValue();
        $a3 = $a3 instanceof RichText ? $a3->getPlainText() : (string) $a3;
        $expectBrand = ['system' => 'SSANCAR', 'heyman' => 'HEYMAN', 'karaba' => 'KARABA'][$set];
        if (! str_contains(strtoupper($a3), $expectBrand)) {
            $bad[] = "차량팩킹 A3 에 $expectBrand 없음: ".mb_substr($a3, 0, 30);
        }
        if (! isset($packing->getMergeCells()['A3:I7'])) {
            $bad[] = '차량팩킹 전폭 병합(A3:I7) 없음';
        }
        if (count($packing->getDrawingCollection()) !== 1) {
            $bad[] = '차량팩킹 그림 '.count($packing->getDrawingCollection()).'장(직인 1장이어야)';
        }
        foreach (REPAIRS[PACKING] as $coord => $want) {
            if ($packing->getCell($coord)->getValue() != $want) {
                $bad[] = "차량팩킹 $coord = ".$packing->getCell($coord)->getValue();
            }
        }
    }
    $travel = $book->getSheetByName(TRAVEL);
    if ($travel) {
        foreach (REPAIRS[TRAVEL] as $coord => $want) {
            if ($travel->getCell($coord)->getValue() !== $want) {
                $bad[] = "Travel $coord = ".$travel->getCell($coord)->getValue();
            }
        }
    }
    $list = $book->getSheetByName('구매리스트');
    if ($list && $list->getStyle('B4')->getFill()->getFillType() !== 'solid') {
        $bad[] = '구매리스트 노란칸(기입 표식) 없음 — 원본을 통째로 썼나?';
    }

    return $bad;
}
