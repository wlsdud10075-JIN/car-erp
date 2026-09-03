<?php

/**
 * 말소증(한글·영문) 양식 빌더 — 3사 `deregistration_certificate.xlsx` 생성.
 *
 * 원본 = jin 제공 `Desktop\heyman\말소증_한글_영문.xlsx` (2시트: 말소증 / 영문말소증).
 * 그 파일은 **원본 워크북에서 시트만 뜯어온 초기문서**라 데이터 칸이 전부 `=#REF!` 이고
 * 외부링크 3개(`D:\성신무역\…`, `D:\2017년 수출현황.xlsm`)가 살아 있다(SKILLS §8 #19).
 *
 * 🚨 `#REF!` 를 안 걷어내면 매핑을 붙여도 **전 칸이 `#REF!` 로 인쇄**된다 —
 *    `DocumentFiller::writeCell` 은 `=` 로 시작하는 셀을 절대 안 덮어쓴다(cascade 보존).
 *    예외도 로그도 없이 조용히 건너뛴다.
 *
 * 이 스크립트가 하는 일:
 *   ① `#REF!` 수식 제거 (셀 비움) — 살아있는 수식(`=E13`·`=말소증!K6`)은 보존
 *   ② definedName 잔재 제거 (외부참조 → Excel "복구할 수 없는 콘텐츠", SKILLS §8 #19)
 *   ③ 날짜칸 서식 — 한글시트=한국식 / 영문시트=미국식 (기존 한글·영문등록증과 동일 규칙)
 *   ④ 소유자 3칸을 회사별로 기입 (상호·법인등록번호·주소)
 *   ⑤ 도장·직인(관인 + 수입증지)은 **손대지 않는다** — jin 2026-09-03 "지금 있는 파일 그대로"
 *
 * 사용:
 *   php scripts/build-deregistration-certificate-template.php            # dry-run (기본)
 *   php scripts/build-deregistration-certificate-template.php --apply    # 3사 생성
 *   php scripts/build-deregistration-certificate-template.php --verify   # 현재 상태 실측 대조
 *
 * ⚠️ 저장은 `setPreCalculateFormulas(false)` — preCalc 를 켜면 크로스시트 수식
 *    (`영문말소증!K6 = 말소증!K6`)이 값으로 굳어 미러가 죽는다(SKILLS §12).
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\DefinedName;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const SOURCE = 'C:/Users/User/Desktop/heyman/말소증_한글_영문.xlsx';
const OUT_NAME = 'deregistration_certificate.xlsx';

const SHEET_KO = '말소증';
const SHEET_EN = '영문말소증';

/** 날짜 서식 — 한글등록증 O3 / 영문등록증 O3 와 동일(시스템 내 기존 규칙 재사용). */
const FMT_DATE_KO = 'yyyy"년"\ m"월"\ d"일";@';
const FMT_DATE_EN = '[$-409]mmm" . "dd" . "yy;@';

/** 날짜칸 = 최초등록일(E10)·말소등록일(E13). 발행일(H20)은 이미 규칙대로라 안 건드린다. */
const DATE_CELLS = ['E10', 'E13'];

/**
 * 🚨 양식에 박힌 **샘플 리터럴** — 차종(K5) `승용`/`Passenger`, 용도(K9) `자가용`/`passenger`.
 *
 * 두 칸 다 **흰칸**이라 `DocumentFiller` 의 노란칸 청소가 안 건드리고, NICE 값이 없는 차는
 * `writeCell` 이 빈 값을 건너뛰어 **샘플이 그대로 인쇄**된다. 「자가용」은 그럴듯해서
 * 사람이 못 알아챈다 — 빈칸보다 위험하다(SKILLS §8 #71 의 그 자리).
 *
 * ⇒ 양식 단계에서 **비워 둔다.** 값은 오로지 NICE(`nice_reg_vehicle_form`·`nice_reg_use_type`)에서만 온다.
 *    없으면 빈칸이 맞다 — 없는 값을 만들어 채우지 않는다.
 */
const SAMPLE_LITERAL_CELLS = ['K5', 'K9'];

/**
 * 회사별 소유자 정보.
 *
 * 출처(실측) — 한글 = `power_of_attorney.xlsx` C23~C25 + `deregistration_application.xlsx` C34~C36
 *              영문 = `clearance_set.xlsx` 영문등록증 E7·E8
 * 상호는 **띄어쓰기 있는 표기**로 통일(공식 신고서류 = 위임장·말소신청서 표기).
 *
 * 🚨 karaba 법인등록번호는 **기존 템플릿에서 베껴오면 안 된다.** 지금 karaba 템플릿 4곳
 *    (위임장 C25 · 통관SET 말소증 K21 · 한글/영문등록증 M8)에 **싼카 번호 `120111-0922270`**
 *    이 그대로 들어 있다 — `generate-karaba-templates.php` 치환맵이 그 칸들을 아예 안 건드려서
 *    생긴 잔재다(heyman 은 `docs/operations/heyman-company-info-cleanup.md` 로 정리된 이력이 있다).
 *    사업자등록번호(`801-81-01696`)도 이 칸에 못 쓴다 — 자릿수·성격이 다르다.
 *    아래 값은 jin 이 2026-09-03 에 직접 확인해 준 것이다. 그 4곳은 **별건으로 같이 고쳐야 한다.**
 */
const TENANTS = [
    'system' => [
        'name_ko' => '주식회사 싼카',
        'name_en' => 'SSANCAR LTD.',
        'corp_no' => '120111-0922270',
        'addr_ko' => '경기도 시흥시 산기대학로 163, A동 328호(정왕동)',
        'addr_en' => '163 Sangidaehak-ro, Siheung-si, Gyeonggi-do, Korea',
    ],
    'heyman' => [
        'name_ko' => '주식회사 헤이맨',
        'name_en' => 'HEYMAN LTD.',
        'corp_no' => '110111-7526176',
        'addr_ko' => '서울특별시 영등포구 선유동1로 50, 513호(당산동3가, THE PARK 365)',
        'addr_en' => '#513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul, Korea',
    ],
    'karaba' => [
        'name_ko' => '주식회사 카라바',
        'name_en' => 'KARABA CO., LTD.',
        'corp_no' => '120111-1058941',   // jin 확인 2026-09-03. 🚫 싼카 번호(120111-0922270)를 넣지 말 것.
        'addr_ko' => '인천광역시 중구 인중로 178 정우빌딩 303호',
        'addr_en' => '#303, 178 Injung-ro, Jung-gu, Incheon, Korea',
    ],
];

/** 소유자 칸 좌표 — 두 시트 좌표가 같다(양식이 같은 격자). */
const OWNER_CELLS = [
    SHEET_KO => ['name' => 'E11', 'corp' => 'K11', 'addr' => 'E12'],
    SHEET_EN => ['name' => 'E11', 'corp' => 'K11', 'addr' => 'E12'],
];

$mode = $argv[1] ?? '--dry-run';
$templateDir = __DIR__.'/../resources/templates';

if ($mode === '--verify') {
    verify($templateDir);
    exit(0);
}

$apply = $mode === '--apply';

if (! is_file(SOURCE)) {
    fwrite(STDERR, '원본을 찾을 수 없습니다: '.SOURCE."\n");
    exit(1);
}

echo $apply ? "=== 생성 (--apply) ===\n" : "=== dry-run — 실제 저장 안 함 (--apply 로 실행) ===\n";

foreach (TENANTS as $set => $info) {
    $out = sprintf('%s/%s/%s', $templateDir, $set, OUT_NAME);
    echo "\n--- {$set} → ".str_replace('\\', '/', realpath(dirname($out)) ?: dirname($out)).'/'.OUT_NAME." ---\n";

    $ss = IOFactory::createReaderForFile(SOURCE)->load(SOURCE);

    // ② definedName 잔재 제거 — 외부참조가 남으면 Excel 이 "복구할 수 없는 콘텐츠" 로 거부(SKILLS #19).
    $names = array_keys($ss->getDefinedNames());
    foreach ($names as $n) {
        $ss->removeDefinedName($n);
    }
    echo sprintf("  definedName 제거: %d개%s\n", count($names), $names ? ' ('.implode(', ', $names).')' : '');

    foreach ([SHEET_KO, SHEET_EN] as $sheetName) {
        $sheet = $ss->getSheetByName($sheetName);
        if (! $sheet instanceof Worksheet) {
            fwrite(STDERR, "  시트 없음: {$sheetName}\n");
            exit(1);
        }

        // ① #REF! 수식 비우기 (살아있는 수식은 보존)
        $cleared = clearBrokenRefs($sheet);
        echo sprintf("  [%s] #REF! 제거 %d칸: %s\n", $sheetName, count($cleared), implode(' ', $cleared) ?: '-');

        // ①-b 샘플 리터럴 비우기 (차종·용도 — 위 상수 주석 참조)
        $before = [];
        foreach (SAMPLE_LITERAL_CELLS as $c) {
            $before[$c] = (string) $sheet->getCell($c)->getValue();
            setText($sheet, $c, '');
        }
        echo sprintf("  [%s] 샘플 리터럴 제거: %s\n", $sheetName,
            implode(' ', array_map(fn ($c, $v) => "{$c}='{$v}'", array_keys($before), $before)));

        // ③ 날짜 서식
        $fmt = $sheetName === SHEET_KO ? FMT_DATE_KO : FMT_DATE_EN;
        foreach (DATE_CELLS as $c) {
            $sheet->getStyle($c)->getNumberFormat()->setFormatCode($fmt);
        }
        echo sprintf("  [%s] 날짜서식 %s ← %s\n", $sheetName, implode(',', DATE_CELLS), $fmt);

        // ④ 소유자
        $map = OWNER_CELLS[$sheetName];
        $name = $sheetName === SHEET_KO ? $info['name_ko'] : $info['name_en'];
        $addr = $sheetName === SHEET_KO ? $info['addr_ko'] : $info['addr_en'];
        setText($sheet, $map['name'], $name);
        setText($sheet, $map['addr'], $addr);
        setText($sheet, $map['corp'], $info['corp_no']);
        echo sprintf("  [%s] 소유자 %s=%s / %s=%s / %s=%s\n",
            $sheetName, $map['name'], $name, $map['addr'], $addr,
            $map['corp'], $info['corp_no'] !== '' ? $info['corp_no'] : '(빈칸 — 확인 대기)');

        // 도장·직인은 손대지 않는다. 남아 있는지만 확인.
        echo sprintf("  [%s] 도장/직인 %d개 보존\n", $sheetName, $sheet->getDrawingCollection()->count());

        foreach (array_keys($sheet->getHyperlinkCollection()) as $coord) {
            $sheet->setHyperlink($coord, null);
        }
    }

    if (! $apply) {
        continue;
    }

    $writer = new XlsxWriter($ss);
    $writer->setPreCalculateFormulas(false);   // 크로스시트 미러(=말소증!K6) 보존
    $writer->save($out);
    echo '  ✅ 저장: '.filesize($out)." bytes\n";
}

echo $apply ? "\n완료. `--verify` 로 대조하세요.\n" : "\ndry-run 끝. 실제 생성은 `--apply`.\n";

/** `#REF!` 가 들어간 수식 셀을 비운다. 살아있는 수식은 건드리지 않는다. */
function clearBrokenRefs(Worksheet $sheet): array
{
    $cleared = [];
    foreach ($sheet->getRowIterator() as $row) {
        $it = $row->getCellIterator();
        $it->setIterateOnlyExistingCells(true);
        foreach ($it as $cell) {
            $v = $cell->getValue();
            if (is_string($v) && str_contains($v, '#REF!')) {
                $cell->setValueExplicit(null, DataType::TYPE_NULL);
                $cleared[] = $cell->getCoordinate();
            }
        }
    }

    return $cleared;
}

function setText(Worksheet $sheet, string $coord, string $value): void
{
    if ($value === '') {
        $sheet->getCell($coord)->setValueExplicit(null, DataType::TYPE_NULL);

        return;
    }
    $sheet->getCell($coord)->setValueExplicit($value, DataType::TYPE_STRING);
}

/** 생성물의 셀을 실제로 읽어 대조한다 — 매핑 배열이 아니라 결과물로(SKILLS §8 #37). */
function verify(string $templateDir): void
{
    $bad = 0;
    foreach (TENANTS as $set => $info) {
        $path = sprintf('%s/%s/%s', $templateDir, $set, OUT_NAME);
        echo "--- {$set} ---\n";
        if (! is_file($path)) {
            echo "  ❌ 없음: {$path}\n";
            $bad++;

            continue;
        }
        $ss = IOFactory::createReaderForFile($path)->load($path);

        foreach ([SHEET_KO, SHEET_EN] as $sheetName) {
            $sheet = $ss->getSheetByName($sheetName);
            if (! $sheet instanceof Worksheet) {
                echo "  ❌ 시트 없음: {$sheetName}\n";
                $bad++;

                continue;
            }
            $map = OWNER_CELLS[$sheetName];
            $wantName = $sheetName === SHEET_KO ? $info['name_ko'] : $info['name_en'];
            $wantAddr = $sheetName === SHEET_KO ? $info['addr_ko'] : $info['addr_en'];
            $wantFmt = $sheetName === SHEET_KO ? FMT_DATE_KO : FMT_DATE_EN;

            $checks = [
                [$map['name'], (string) $sheet->getCell($map['name'])->getValue(), $wantName],
                [$map['addr'], (string) $sheet->getCell($map['addr'])->getValue(), $wantAddr],
                [$map['corp'], (string) $sheet->getCell($map['corp'])->getValue(), $info['corp_no']],
            ];
            foreach ($checks as [$coord, $got, $want]) {
                $ok = $got === $want;
                $bad += $ok ? 0 : 1;
                printf("  %s [%s] %-4s %s\n", $ok ? '✅' : '❌', $sheetName, $coord,
                    $ok ? ($got !== '' ? $got : '(빈칸)') : "got='{$got}' want='{$want}'");
            }
            foreach (DATE_CELLS as $c) {
                $got = $sheet->getStyle($c)->getNumberFormat()->getFormatCode();
                $ok = $got === $wantFmt;
                $bad += $ok ? 0 : 1;
                printf("  %s [%s] %-4s 날짜서식 %s\n", $ok ? '✅' : '❌', $sheetName, $c, $ok ? $got : "got='{$got}' want='{$wantFmt}'");
            }

            foreach (SAMPLE_LITERAL_CELLS as $c) {
                $got = (string) $sheet->getCell($c)->getValue();
                $ok = $got === '';
                $bad += $ok ? 0 : 1;
                printf("  %s [%s] %-4s 샘플 리터럴 %s\n", $ok ? '✅' : '❌', $sheetName, $c,
                    $ok ? '없음' : "'{$got}' 잔존 — NICE 값 없는 차에 그대로 인쇄된다");
            }

            $refs = clearBrokenRefsProbe($sheet);
            $ok = $refs === [];
            $bad += $ok ? 0 : 1;
            printf("  %s [%s] #REF! 잔존 %s\n", $ok ? '✅' : '❌', $sheetName, $ok ? '0' : implode(' ', $refs));

            $draw = $sheet->getDrawingCollection()->count();
            $ok = $draw === 2;
            $bad += $ok ? 0 : 1;
            printf("  %s [%s] 도장/직인 %d개\n", $ok ? '✅' : '❌', $sheetName, $draw);
        }
    }
    echo $bad === 0 ? "\n✅ 전부 일치.\n" : "\n❌ 불일치 {$bad}건.\n";
}

function clearBrokenRefsProbe(Worksheet $sheet): array
{
    $found = [];
    foreach ($sheet->getRowIterator() as $row) {
        $it = $row->getCellIterator();
        $it->setIterateOnlyExistingCells(true);
        foreach ($it as $cell) {
            $v = $cell->getValue();
            if (is_string($v) && str_contains($v, '#REF!')) {
                $found[] = $cell->getCoordinate();
            }
        }
    }

    return $found;
}
