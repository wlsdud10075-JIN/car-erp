<?php

/**
 * 📄 양식의 TEL·FAX 줄 끝 공백 제거 (jin 2026-11-17).
 *
 * 제보: ssancarerp 통관 SET Ver.1 차량인보이스·차량팩킹 Shipper/Exporter(A3:D7 병합칸)에서 받는 쪽 화면에
 *   TEL : …
 *   (빈 줄)
 *   FAX : …
 * 로 보인다. 양식 원문은 `TEL:82-505-355-9977␣␣\nFAX:…␣\n` — 줄바꿈은 하나인데 **줄 끝 공백**이 붙어 있어
 * 받는 프로그램(휴대폰 미리보기·다른 엑셀·PDF 변환)이 그 공백을 다음 줄로 밀어 빈 줄처럼 보인다(jin «그 공백 때문인 것 같아»).
 * 출처 = `scripts/generate-ssancar-templates.php` 의 `$invBlock`(같이 고쳤다).
 *
 * 🔧 PhpSpreadsheet 로 다시 저장하지 않는다 — xlsx(zip) 안의 sharedStrings/sheet XML 문자열만 고친다.
 *    직인 이미지·병합·서식·수식이 그대로 남는다(SKILLS §12 체크리스트: 저장 경로가 cascade·이미지를 죽이는 함정을 피한다).
 *
 *   php scripts/fix-template-tel-fax-trailing-space.php            # dry-run(고칠 곳 목록)
 *   php scripts/fix-template-tel-fax-trailing-space.php --apply
 *   php scripts/fix-template-tel-fax-trailing-space.php --verify   # 남은 곳 0 이면 exit 0
 */
$root = dirname(__DIR__);
$mode = in_array('--apply', $argv, true) ? 'apply' : (in_array('--verify', $argv, true) ? 'verify' : 'dry');

// TEL/FAX 가 든 줄의 끝 공백(일반·NBSP) — 줄바꿈(실제 LF·&#10;·&#xA;) 앞 또는 문자열 끝(</t>) 앞
$pattern = '#((?:TEL|FAX)[^<\n]*?)[ \x{00A0}]+(?=\n|&\#10;|&\#xA;|\r|</t>)#u';

$left = 0;
foreach (glob($root.'/resources/templates/*/*.xlsx') as $file) {
    $zip = new ZipArchive;
    if ($zip->open($file) !== true) {
        fwrite(STDERR, "열기 실패: {$file}\n");
        exit(2);
    }
    $changes = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (! preg_match('#^xl/(sharedStrings|worksheets/sheet\d+)\.xml$#', $name)) {
            continue;
        }
        $xml = $zip->getFromIndex($i);
        $n = preg_match_all($pattern, $xml);
        if ($n > 0) {
            $changes[$name] = [$n, preg_replace($pattern, '$1', $xml)];
        }
    }
    $rel = substr($file, strlen($root) + 1);
    foreach ($changes as $name => [$n]) {
        echo "{$rel} :: {$name} — {$n}곳\n";
        $left += $n;
    }
    if ($mode === 'apply' && $changes !== []) {
        foreach ($changes as $name => [, $fixed]) {
            $zip->addFromString($name, $fixed);
        }
    }
    $zip->close();
}

if ($mode === 'verify') {
    echo $left === 0 ? "✅ TEL·FAX 줄 끝 공백 0곳\n" : "❌ 남은 곳 {$left}\n";
    exit($left === 0 ? 0 : 1);
}
echo $mode === 'apply' ? "✅ {$left}곳 고침\n" : "dry-run — {$left}곳. 고치려면 --apply\n";
