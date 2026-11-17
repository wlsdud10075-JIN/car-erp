<?php

/**
 * 📄 양식 차명칸 「셀에 맞춤(shrinkToFit)」 (jin 2026-11-17).
 *
 * 제보: 통관 SET Ver.1 한글등록증·영문등록증 E5:I5 차명이 길면(영문 약 25자) 잘리고 옆 「⑤ 형식 및 모델연도」 칸과 겹친다.
 * 같은 통합문서의 다른 차명칸(말소증!E10·차량인보이스!B22·차량팩킹!B22·구매리스트!B5)은 이미 shrinkToFit 이다 — 이 칸들만 빠져 있었다.
 * 같은 서식인 말소증 서류(말소증·영문말소증 E7:G7 차명)도 함께(jin «말소증 차명도 밀린다면 수정»).
 *
 * 🔧 PhpSpreadsheet 로 다시 저장하지 않는다 — xlsx(zip) 안 styles.xml 에 셀 서식(xf) 하나를 복제해 shrinkToFit 을 켜고,
 *    대상 셀의 s 만 그 새 번호로 바꾼다. 원래 xf 는 차량번호·차대번호 칸과 공유라 그대로 둔다. 직인·병합·인쇄설정 무변경.
 *
 *   php scripts/fix-template-car-name-shrink.php            # dry-run
 *   php scripts/fix-template-car-name-shrink.php --apply
 *   php scripts/fix-template-car-name-shrink.php --verify   # 대상 칸이 전부 shrinkToFit 이면 exit 0
 */
$root = dirname(__DIR__);
$mode = in_array('--apply', $argv, true) ? 'apply' : (in_array('--verify', $argv, true) ? 'verify' : 'dry');

$targets = [
    'clearance_set.xlsx' => ['한글등록증' => 'E5', '영문등록증' => 'E5'],
    'clearance_set_v2.xlsx' => ['한글등록증' => 'E5', '영문등록증' => 'E5'],
    'deregistration_certificate.xlsx' => ['말소증' => 'E7', '영문말소증' => 'E7'],
];

/** 시트 이름 → zip 안 경로 */
function sheetPath(ZipArchive $zip, string $sheetName): ?string
{
    $wb = new DOMDocument;
    $wb->loadXML($zip->getFromName('xl/workbook.xml'));
    $rid = null;
    foreach ($wb->getElementsByTagName('sheet') as $s) {
        if ($s->getAttribute('name') === $sheetName) {
            $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        }
    }
    if ($rid === null) {
        return null;
    }
    $rels = new DOMDocument;
    $rels->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));
    foreach ($rels->getElementsByTagName('Relationship') as $r) {
        if ($r->getAttribute('Id') === $rid) {
            $t = $r->getAttribute('Target');

            return str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/'.$t;
        }
    }

    return null;
}

/** 셀의 s 속성(없으면 0) */
function cellStyle(string $sheetXml, string $ref): ?int
{
    if (! preg_match('#<c r="'.$ref.'"([^>]*)>|<c r="'.$ref.'"([^>]*)/>#', $sheetXml, $m)) {
        return null;
    }
    $attrs = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');

    return preg_match('#\bs="(\d+)"#', $attrs, $s) ? (int) $s[1] : 0;
}

$bad = 0;
foreach (glob($root.'/resources/templates/*/*.xlsx') as $file) {
    $base = basename($file);
    if (! isset($targets[$base])) {
        continue;
    }
    $rel = substr($file, strlen($root) + 1);
    $zip = new ZipArchive;
    $zip->open($file);
    $styles = new DOMDocument;
    $styles->preserveWhiteSpace = true;
    $styles->loadXML($zip->getFromName('xl/styles.xml'));
    $cellXfs = $styles->getElementsByTagName('cellXfs')->item(0);
    $xfs = [];
    foreach ($cellXfs->childNodes as $n) {
        if ($n instanceof DOMElement && $n->localName === 'xf') {
            $xfs[] = $n;
        }
    }

    $sheetEdits = [];
    $stylesChanged = false;
    foreach ($targets[$base] as $sheet => $ref) {
        $path = sheetPath($zip, $sheet);
        if ($path === null) {
            echo "{$rel} :: {$sheet} 시트 없음\n";
            $bad++;

            continue;
        }
        $xml = $sheetEdits[$path] ?? $zip->getFromName($path);
        $s = cellStyle($xml, $ref);
        if ($s === null) {
            echo "{$rel} :: {$sheet}!{$ref} 셀 없음\n";
            $bad++;

            continue;
        }
        $align = $xfs[$s]->getElementsByTagName('alignment')->item(0);
        $already = $align && in_array($align->getAttribute('shrinkToFit'), ['1', 'true'], true);
        if ($already) {
            echo "{$rel} :: {$sheet}!{$ref} 이미 맞춤(xf {$s})\n";

            continue;
        }
        $bad++;
        echo "{$rel} :: {$sheet}!{$ref} xf {$s} → 맞춤 서식 새로\n";
        if ($mode !== 'apply') {
            continue;
        }
        $clone = $xfs[$s]->cloneNode(true);
        $a = $clone->getElementsByTagName('alignment')->item(0);
        if (! $a) {
            $a = $styles->createElementNS($styles->documentElement->namespaceURI, 'alignment');
            $clone->appendChild($a);
            $clone->setAttribute('applyAlignment', '1');
        }
        $a->setAttribute('shrinkToFit', '1');
        $a->setAttribute('wrapText', '0');   // 줄바꿈이 켜져 있으면 엑셀이 맞춤을 무시한다
        $cellXfs->appendChild($clone);
        $xfs[] = $clone;
        $newId = count($xfs) - 1;
        $cellXfs->setAttribute('count', (string) count($xfs));
        $stylesChanged = true;
        $sheetEdits[$path] = preg_replace_callback('#<c r="'.$ref.'"([^>]*?)(/?)>#', function ($m) use ($newId) {
            $attrs = preg_match('#\bs="\d+"#', $m[1]) ? preg_replace('#\bs="\d+"#', 's="'.$newId.'"', $m[1]) : $m[1].' s="'.$newId.'"';

            return '<c r="'.substr($m[0], 6, strpos($m[0], '"', 6) - 6).'"'.$attrs.$m[2].'>';
        }, $xml, 1);
    }
    if ($mode === 'apply' && $stylesChanged) {
        $zip->addFromString('xl/styles.xml', $styles->saveXML());
        foreach ($sheetEdits as $path => $xml) {
            $zip->addFromString($path, $xml);
        }
    }
    $zip->close();
}

if ($mode === 'verify') {
    echo $bad === 0 ? "✅ 차명칸 전부 셀에 맞춤\n" : "❌ 남은 곳 {$bad}\n";
    exit($bad === 0 ? 0 : 1);
}
echo $mode === 'apply' ? "✅ 적용 끝 — --verify 로 확인\n" : "dry-run — {$bad}곳. 고치려면 --apply\n";
