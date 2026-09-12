<?php

/**
 * 자동차양도증명서(별지 제16호서식) 양식 빌더 — 3사 `transfer_certificate.xlsx` 를 **백지에서** 생성.
 *
 * 🆕 **이 레포 최초의 「백지 작성」 빌더다.** 다른 빌더들(`build-deregistration-certificate-template.php`
 *    등)은 전부 jin 이 준 완성 xlsx 를 **청소**하는 스크립트다. 여기엔 원본이 없다 —
 *    jin 이 준 PDF 두 장이 **텍스트 0자 스캔 이미지**라 읽어올 격자가 없기 때문이다.
 *    ⇒ 아래 `LAYOUT` 계열 상수와 build() 가 그 격자의 정본이다. 스캔을 눈으로 옮겨 적은 결과다.
 *
 * 🟡 **노란칸 = ERP 값이 들어가는 칸.** 좌표는 `TransferCertificateMapping::CELLS` 를 **읽어서** 칠한다.
 *    옮겨 적지 않으므로 매핑과 양식이 어긋날 수 없다(SKILLS §8 #44).
 *    ⚠️ 거꾸로 칠하면 예외도 로그도 없이 조용히 틀린다 —
 *       매핑 있는 칸이 흰칸이면 **노란 배경이 인쇄물에 그대로 남고**,
 *       고정 리터럴이 노란칸이면 `DocumentFiller::clearYellowFill` 이 기입 전에 비워 **공란**이 된다(§8 #71).
 *
 * 🚫 **karaba 를 `generate-karaba-templates.php` 로 파생하지 말 것** — 그건 회사정보 셀만 치환해서
 *    안 적힌 칸에 싼카 값이 남는다(실사고: karaba 4곳에 싼카 법인등록번호, SKILLS §8 #75).
 *    이 빌더는 3사를 **각각 직접** 생성한다.
 *
 * 사용:
 *   php scripts/build-transfer-certificate-template.php            # dry-run (기본, 쓰기 없음)
 *   php scripts/build-transfer-certificate-template.php --apply    # 3사 생성
 *   php scripts/build-transfer-certificate-template.php --verify   # 현재 양식 실측 대조
 *
 * ⚠️ 저장은 `setPreCalculateFormulas(false)` — 프로젝트 공통 규칙(SKILLS §12).
 */

require __DIR__.'/../vendor/autoload.php';

use App\Services\Documents\Mappings\TransferCertificateMapping;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const OUT_NAME = 'transfer_certificate.xlsx';
const SHEET = '3.양도증명서';   // 기존 = 1.차량말소신청서 / 2.계약서 / 4.위임장 — 3 번이 비어 있었다.

const FMT_DATE_KO = 'yyyy"년"\ m"월"\ d"일";@';
const FMT_MONEY = '"일금 "#,##0"원정"';
const FMT_KM = '#,##0"  km"';
const FMT_WON = '#,##0"  원"';

/**
 * 약관·유의사항의 글자 폭 예산(한글 2칸 기준).
 *
 * 표 전체 폭 = 40열 × 약 2.3 엑셀폭 ≈ 483pt. 계산으로 뽑은 이론값(6pt → 160칸)은
 * **실제로 잘렸다** — 자간·기호 폭 때문에 이론보다 덜 들어간다.
 * 아래 값은 LibreOffice 로 PDF 를 뽑아 눈으로 확인해 정한 실측값이다.
 *
 * 🚨 이 값을 키우면 글자가 상자 밖으로 **잘린 채 인쇄**된다 — 예외 0 · 화면은 정상 · 테스트도 통과.
 *    바꿨으면 반드시 PDF 로 렌더해 눈으로 확인할 것.
 */
const CLAUSE_PT = 6.0;
const CLAUSE_LH = 9.2;
const CLAUSE_UNITS = 132;

const YELLOW = 'FFFFFF00';
const BLUE = 'FF1F3FBF';

/**
 * 회사별 고정정보.
 *
 * 출처(실측 2026-09-12) — 한글 상호·주소·전화 = `power_of_attorney.xlsx` C23·C24·K25.
 * 매매업자 등록번호·대표자·취급자 = jin 2026-09-11 확정(메모리 `project_transfer_certificate`).
 *
 * ⚠️ 전화가 3사 모두 `010-5338-9967` 인 것은 위임장 실측 그대로다. karaba 영문 양식엔
 *    `032-710-7979` 가 있어 어느 쪽이 맞는지 확인이 필요하다 — 정해지면 여기만 고친다.
 */
const TENANTS = [
    'system' => [
        'name' => '주식회사 싼카',
        'addr' => '경기도 시흥시 산기대학로 163, A동 328호(정왕동)',
        'phone' => '010-5338-9967',
        'dealer_no' => '02-4115-000476',
        'ceo' => '조태신',
        'agent' => '이기용',
    ],
    'heyman' => [
        'name' => '주식회사 헤이맨',
        'addr' => '서울특별시 영등포구 선유동1로 50, 513호(당산동3가, THE PARK 365)',
        'phone' => '010-5338-9967',
        'dealer_no' => '',          // jin 2026-09-11 — 공란
        'ceo' => '조태신',
        'agent' => '조태신',
    ],
    'karaba' => [
        'name' => '주식회사 카라바',
        'addr' => '인천광역시 중구 인중로 178 정우빌딩 303호',
        'phone' => '010-5338-9967',
        'dealer_no' => '',          // jin 2026-09-11 — 공란
        'ceo' => '김희철',
        'agent' => '김희철',
    ],
];

/**
 * 열 너비 — 40열(A..AN). 빈 양식 스캔의 세로 분할선 비율을 실측해 맞췄다.
 *   계약당사자 6.2% / 양도인(갑) 7.8% / 갑 상세 38.6% / 양수인(을) 8.6% / 을 상세 38.9%
 */
const COL_WIDTHS = [
    'A' => 2.4, 'B' => 2.4, 'C' => 2.4,                       // 세로 라벨(계약당사자 / 자동차매매업자)
    'D' => 2.6, 'E' => 2.6, 'F' => 2.6,                       // 갑 라벨
    // G..U (15) = 갑 상세
    'V' => 2.6, 'W' => 2.6, 'X' => 2.6,                       // 을 라벨
    // Y..AN (16) = 을 상세
];
const COL_DEFAULT = 2.3;

/** 제1조~제10조 — 빈 양식 스캔에서 줄바꿈까지 그대로 옮긴 것. 행 높이는 줄 수로 정해진다. */
const CLAUSES = [
    '제1조 (당사자표시) 양도인을 "갑"이라 하고, 양수인을 "을"이라 한다.',

    '제2조 (동시이행 등) ① "갑"은 잔금 수령과 상환으로 소유권이전등록에 필요한 서류와 매매목적물을 "을"에게 인도하기로 한다. 다만, "갑"과 "을"의 합의에 따라 매매금액의 2/3 이상의 상당액을 지급한'."\n"
        .'         경우에는 매매목적물을 인도할 수 있다. ② "을"은 "갑"에게 잔금을 지급함과 동시에 소유권이전등록의 절차에 필요한 서류와 등록비용을 자동차매매업자(이하 "매매업자"라 한다)에게 내주어야 한다.'."\n"
        .'         다만, 매매업자가 매수할 때에는 그러하지 아니하다. ③ 매매업자는 잔금지급일부터 15일 이내에 자동차소유권 이전등록 신청을 하여야 한다.',

    '제3조 (공과금 부담) 이 자동차에 대한 제세공과금은 자동차 인도일을 기준으로 하여, 그 기준일까지의 분은 "갑"이 부담하고 기준일 다음날부터의 분은 "을"이 부담한다. 다만, 관계 법령에 제세공과금'."\n"
        .'         납부에 관하여 특별한 규정이 있는 경우에는 그에 따른다.',

    '제4조 (사고책임) "을"은 이 자동차를 인수한 때부터 발생하는 모든 사고에 대하여 자기를 위하여 운행하는 자로서의 책임을 진다.',

    '제5조 (법률상의 하자책임) ① 자동차 인도일 이전에 발생한 행정처분 또는 이전등록 요건의 불비 등의 하자에 대해서는 "갑"이 그 책임을 진다. ② 매매업자는 「자동차관리법」 제58조제1항에 따라'."\n"
        .'         자동차의 성능 · 상태의 점검 내용을 "을"에게 알려야 하고, "을"이 원하는 경우 같은 법 제58조의4에 해당하는 자가 자동차 가격을 조사 · 산정한 내용을 알려야 한다.',

    '제6조 (해약금 등) ① "갑"이 이 계약을 위반한 경우에는 "갑"은 해약금으로 계약금의 2배액을 "을"에게 배상해야 하며, "을"이 위약한 경우에는 "을"은 "갑"에게 계약금의 반환을 요구할 수 없다. 다만,'."\n"
        .'         손해배상의 청구는 방해하지 않는다. ② 제5조제2항에 따라 "갑"이 고지한 자동차의 성능 · 상태의 점검내용 중 주행거리, 사고 또는 침수사실이 다르거나, "갑"이 자동차의 성능 · 상태의 점검내용'."\n"
        .'         또는 압류 · 저당권의 등록 여부를 거짓으로 고지하거나 고지하지 아니한 경우 "을"은 자동차인도일로부터 30일 이내에 매매계약을 해제할 수 있으며, 이 경우 "을"은 자동차를 즉시 "갑"에게'."\n"
        .'         반환하고 "갑"은 자동차의 반환과 동시에 이미 지급받은 매매금액을 "을"에게 반환하여야 한다.',

    '제7조 (매매업자의 책임) 제5조의 하자에 대해서는 매매업자가 매도인과 동일한 책임을 지며, 시 · 도의 조례가 하자보증금을 예치하도록 하는 경우 매수인의 요청이 있을 때에는 그 하자보증금으로'."\n"
        .'         매수인에게 우선 지급해야 한다. 다만, 매매업자는 양도인 또는 그 하자에 책임이 있는 자에 대하여 구상권을 행사할 수 있다.',

    '제8조 (등록 지체 책임) "갑"과 "을"이 매매업자에게 이전등록의 대행에 필요한 서류 등의 발급 또는 권한의 위임을 한 후 매매업자가 이전등록 신청을 대행하지 않을 때에는 이에 대한 모든 책임은'."\n"
        .'         매매업자가 진다.',

    '제9조 (할부승계 특약) "갑"이 자동차를 할부로 구입하여 할부금을 다 내지 않은 상태에서 "을"에게 양도하는 경우에는 나머지 할부금을 "을"이 승계하여 부담할 것인지의 여부를 특약사항란에 적어야 한다.',

    '제10조 (계약서) 이 계약서는       년       월       일 4통 작성하여 "갑"이 1통, "을"이 1통, 등록절차를 대행하는 매매업자가 2통씩을 각각 지닌다.',
];

/** 특약사항 — 왼쪽 상자 안의 14줄. 둘째 값이 true 면 굵게(【 】·※ 줄). */
const SPECIAL = [
    ['1. 상사에서 부가가치세 신고와 관련하여 주민번호를 이용, 국세청(홈텍스)에서', false],
    ['    사업자 등록 유무를 조회하는데 동의함        양도인(서명)', false],
    ['【 공통사항 】', true],
    ['1. 사고유무, 주행거리, 용도이력, 침수, 전손 여부에 대한 설명을 듣고', false],
    ['    성능상태점검기록부에 서명 후 교부 받았음', false],
    ['2. 압류 및 저당권의 등록 여부, 인허가보증보험증권에 대해 고지 받았음', false],
    ['3. 차량인수 후 단순변심으로 인한 교환이나 환불 불가', false],
    ['※ 상기 각호에 대해 설명을 듣고 동의함        양수인(서명)', true],
    ['【 성능상태점검책임보험 차량 】', true],
    ['1. 성능상태점검책임보험에 가입(증권 교부)되어 있으며 보험료는 소비자의', false],
    ['    부담임을 고지 받았음', false],
    ['2. 성능상태점검책임보험 증권의 보증범위가 아닌 노후에 의한 잔고장이나', false],
    ['    소모품은 출고 후 보증 불가', false],
    ['※ 상기 각호에 대해 설명을 듣고 동의함        양수인(서명)', true],
];

const NOTICE = '(유의사항)   1. 이 양도증명서는 「자동차관리법」에 따라 자동차매매업의 등록을 한 자만이 사용할 수 있습니다.  2. 매매업자는 반드시 직인을 찍어야 합니다.  3. 자동차매매사업조합의'."\n"
    .'              중고자동차 제시 또는 매도신고번호를 적어야 합니다.  4. 이 양도증명서를 작성할 때 매매대상 중고자동차의 배출가스저감장치 자기부담금의 납부 여부를 확인하여 뜻하지'."\n"
    .'              않은 손해를 입지 않도록 하시기 바랍니다.  5. 자동차정비, 검사, 주행거리 이력, 자동차세 납부 여부, 압류내역 및 배출가스저감장치 자기부담금 납부내역 등'."\n"
    .'              자동차토탈이력정보는 국토교통부에서 제공하는 스마트폰용 어플("마이카정보") 또는 자동차민원대국민포털(www.ecar.go.kr)에서 조회가 가능하므로 확인 바랍니다.';

// ── 실행 ──────────────────────────────────────────────────────────────
$mode = $argv[1] ?? '--dry-run';
$templateDir = __DIR__.'/../resources/templates';

if ($mode === '--verify') {
    verify($templateDir);
    exit(0);
}

$apply = $mode === '--apply';

echo $apply ? "생성 모드(--apply)\n\n" : "미리보기 모드(dry-run) — 쓰지 않습니다. 실제 생성은 --apply\n\n";

foreach (TENANTS as $set => $co) {
    $out = sprintf('%s/%s/%s', $templateDir, $set, OUT_NAME);
    echo sprintf("[%s] %s\n", $set, $out);
    echo sprintf("      상호 %s / 매매업자 등록번호 %s / 대표 %s / 취급 %s\n",
        $co['name'], $co['dealer_no'] !== '' ? $co['dealer_no'] : '(공란)', $co['ceo'], $co['agent']);

    if (! $apply) {
        continue;
    }

    $ss = build($co);
    $w = new XlsxWriter($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($out);
    $ss->disconnectWorksheets();
    echo "      ✅ 생성\n";
}

echo "\n".($apply ? "완료. `--verify` 로 대조하세요.\n" : "생성하려면 --apply 를 붙이세요.\n");

// ── 빌드 ──────────────────────────────────────────────────────────────
function build(array $co): Spreadsheet
{
    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setTitle(SHEET);

    $ss->getDefaultStyle()->getFont()->setName('맑은 고딕')->setSize(8);

    for ($i = 1; $i <= 40; $i++) {
        $col = Coordinate::stringFromColumnIndex($i);
        $sh->getColumnDimension($col)->setWidth(COL_WIDTHS[$col] ?? COL_DEFAULT);
    }

    buildHeader($sh);
    buildParties($sh, $co);
    buildDealer($sh, $co);
    buildContract($sh);
    buildClauses($sh);
    buildSpecial($sh);
    buildFooter($sh);

    paintYellow($sh);
    pageSetup($sh);

    $sh->setSelectedCell('A1');

    return $ss;
}

function buildHeader(Worksheet $sh): void
{
    // 1행 — 서식 번호 / 구분표시.
    // ⚠️ 「매수인용」 — 빈 양식엔 「매도인용」이 찍혀 있지만 그건 4통 중 매도인 보관본이다.
    //    우리는 매수인(양수인)이므로 회사가 보관하는 사본은 매수인용이 맞다(2026-09-12 판단).
    put($sh, 'A1:L1', '■ [별지 제16호서식]', ['size' => 8]);
    put($sh, 'AA1:AN1', '매수인용 ', ['size' => 9, 'align' => 'right']);
    $sh->getRowDimension(1)->setRowHeight(13);

    put($sh, 'A2:AN2', '자동차양도증명서(자동차매매업자거래용)',
        ['size' => 15, 'bold' => true, 'align' => 'center', 'color' => BLUE]);
    $sh->getRowDimension(2)->setRowHeight(22);

    put($sh, 'A3:H3', '  지역 및 일련번호', ['size' => 9]);
    // 일련번호 — 3사 공용(jin 2026-09-11 승인). 모든 서류가 같은 번호로 나간다는 점을 알고 정한 값이다.
    put($sh, 'I3:T3', '경기 31-25-782602', ['size' => 10]);
    put($sh, 'U3:AN3', '※ 유의사항을 읽고 작성하여 주시기 바랍니다.   ', ['size' => 8, 'align' => 'right']);
    $sh->getRowDimension(3)->setRowHeight(16);
    box($sh, 'A2:AN3');

    put($sh, 'A4:J4', '  중고자동차 제시 또는 매도 번호', ['size' => 9]);
    put($sh, 'K4:AN4', '', []);   // 공란 — 조합 발급번호는 손으로 적는다(jin 확정)
    $sh->getRowDimension(4)->setRowHeight(16);
    box($sh, 'A4:J4');
    box($sh, 'K4:AN4');
}

function buildParties(Worksheet $sh, array $co): void
{
    foreach ([5, 6, 7] as $r) {
        $sh->getRowDimension($r)->setRowHeight(17);
    }

    put($sh, 'A5:C7', "계 약\n당사자", ['align' => 'center', 'wrap' => true, 'size' => 9]);
    put($sh, 'D5:F7', "양도인\n(갑)", ['align' => 'center', 'wrap' => true, 'size' => 9]);
    put($sh, 'V5:X7', "양수인\n(을)", ['align' => 'center', 'wrap' => true, 'size' => 9]);

    // 갑 = 차주. 성명 행만 우측에 (서명 또는 인).
    put($sh, 'G5:K5', ' 성명(명칭)', ['size' => 8]);
    put($sh, 'L5:P5', '', ['size' => 10, 'indent' => 1]);                                  // 🟡 owner_name
    put($sh, 'Q5:U5', '(서명 또는 인) ', ['size' => 7, 'align' => 'right']);
    put($sh, 'G6:K6', ' 전화번호', ['size' => 8]);
    put($sh, 'L6:U6', '', ['size' => 10, 'indent' => 1]);                                  // 공란 — ERP 에 차주 연락처 컬럼이 없다
    put($sh, 'G7:K7', ' 주소', ['size' => 8]);
    put($sh, 'L7:U7', '', ['size' => 9, 'indent' => 1]);                                   // 🟡 owner_addr

    // 을 = 회사. 양식 셀에 박는다(매핑 아님 — `config/company.php` 는 dead, SKILLS §12).
    put($sh, 'Y5:AC5', ' 성명(명칭)', ['size' => 8]);
    put($sh, 'AD5:AH5', $co['name'], ['size' => 10, 'indent' => 1]);
    put($sh, 'AI5:AN5', '(서명 또는 인) ', ['size' => 7, 'align' => 'right']);
    put($sh, 'Y6:AC6', ' 전화번호', ['size' => 8]);
    put($sh, 'AD6:AN6', $co['phone'], ['size' => 10, 'indent' => 1]);
    put($sh, 'Y7:AC7', ' 주소', ['size' => 8]);
    put($sh, 'AD7:AN7', $co['addr'], ['size' => 8, 'indent' => 1]);

    box($sh, 'A5:C7');
    box($sh, 'D5:F7');
    box($sh, 'V5:X7');
    foreach ([5, 6, 7] as $r) {
        box($sh, "G$r:U$r");
        box($sh, "Y$r:AN$r");
    }
}

function buildDealer(Worksheet $sh, array $co): void
{
    foreach ([8, 9, 10] as $r) {
        $sh->getRowDimension($r)->setRowHeight(17);
    }

    put($sh, 'A8:C10', "자동차\n매매업자", ['align' => 'center', 'wrap' => true, 'size' => 9]);

    // ⚠️ 좌우가 갑/을이 아니다 — 좌 = 값(등록번호·대표자명·취급자명), 우 = 상호 + [직인]/(서명 또는 인).
    //    기입 예시 실물을 그대로 옮긴 구조다. 갑/을로 읽으면 서류가 통째로 틀린다.
    put($sh, 'D8:G8', ' 등록번호 및 상호', ['size' => 8]);
    put($sh, 'H8:W8', '  '.$co['dealer_no'], ['size' => 10]);
    put($sh, 'X8:AN8', '  '.$co['name'], ['size' => 10]);

    put($sh, 'D9:G9', '대 표 자', ['size' => 8, 'align' => 'center']);
    put($sh, 'H9:S9', '  '.$co['ceo'], ['size' => 10]);
    put($sh, 'T9:W9', '[직인] ', ['size' => 7, 'align' => 'right']);
    put($sh, 'X9:AJ9', '', []);
    put($sh, 'AK9:AN9', '[직인] ', ['size' => 7, 'align' => 'right']);

    put($sh, 'D10:G10', '취 급 자', ['size' => 8, 'align' => 'center']);
    put($sh, 'H10:S10', '  '.$co['agent'], ['size' => 10]);
    put($sh, 'T10:W10', '(서명 또는 인) ', ['size' => 7, 'align' => 'right']);
    put($sh, 'X10:AJ10', '', []);
    put($sh, 'AK10:AN10', '(서명 또는 인) ', ['size' => 7, 'align' => 'right']);

    box($sh, 'A8:C10');
    foreach ([8, 9, 10] as $r) {
        box($sh, "D$r:G$r");
        box($sh, "H$r:W$r");
        box($sh, "X$r:AN$r");
    }

    // 계약연월일 — 공란(jin 확정). 양식의 년/월/일 안내만 찍는다.
    put($sh, 'A11:G11', '계약연월일', ['size' => 9, 'align' => 'center']);
    put($sh, 'H11:AN11', '                    년                        월                        일', ['size' => 9]);
    $sh->getRowDimension(11)->setRowHeight(17);
    box($sh, 'A11:G11');
    box($sh, 'H11:AN11');
}

function buildContract(Worksheet $sh): void
{
    put($sh, 'A12:AN12', '중고자동차 매매계약서',
        ['size' => 12, 'bold' => true, 'align' => 'center', 'color' => BLUE]);
    $sh->getRowDimension(12)->setRowHeight(19);
    box($sh, 'A12:AN12');

    for ($r = 13; $r <= 19; $r++) {
        $sh->getRowDimension($r)->setRowHeight(17);
    }
    // 20행만 높다 — 오른쪽 라벨(「압류 및 저당권 / 등록여부」)이 2줄이라 17 이면 아랫줄이 잘린다.
    $sh->getRowDimension(20)->setRowHeight(30);

    $v = ['size' => 10, 'indent' => 1];

    labelRow($sh, 13, '자동차등록번호', '주 행 거 리');
    put($sh, 'F13:S13', '', $v);                                                          // 🟡 plate
    put($sh, 'Y13:AN13', '', ['size' => 10, 'align' => 'right', 'fmt' => FMT_KM]);        // 🟡 mileage

    labelRow($sh, 14, '차            종', '차            명');
    put($sh, 'F14:S14', '', $v);                                                          // 🟡 form_year
    put($sh, 'Y14:AN14', '', $v);                                                         // 🟡 car_name

    labelRow($sh, 15, '차 대 번 호', '계 약 금');
    put($sh, 'F15:S15', '', $v);                                                          // 🟡 vin
    put($sh, 'Y15:AD15', '', ['size' => 9, 'align' => 'center', 'fmt' => FMT_DATE_KO]);   // 🟡 down_date
    put($sh, 'AE15:AN15', '', ['size' => 10, 'align' => 'right', 'fmt' => FMT_MONEY]);    // 🟡 down_amount

    labelRow($sh, 16, '중 도 금', '잔            금');
    // 중도금 = 0원정 고정(jin 확정). **흰칸 리터럴** — 노란칸이면 기입 전에 비워진다(§8 #71).
    put($sh, 'F16:K16', '', ['size' => 9, 'align' => 'center']);
    num($sh, 'L16:S16', 0, ['size' => 10, 'align' => 'right', 'fmt' => FMT_MONEY]);
    put($sh, 'Y16:AD16', '', ['size' => 9, 'align' => 'center', 'fmt' => FMT_DATE_KO]);   // 🟡 balance_date
    put($sh, 'AE16:AN16', '', ['size' => 10, 'align' => 'right', 'fmt' => FMT_MONEY]);    // 🟡 balance_amount

    // 매매금액은 2행 세로병합 — 오른쪽 「등록비 및 대행수수료」가 2줄이기 때문이다.
    put($sh, 'A17:E18', '매 매 금 액', ['size' => 9, 'align' => 'center']);
    put($sh, 'F17:S18', '', ['size' => 11, 'align' => 'right', 'fmt' => FMT_MONEY]);      // 🟡 price
    put($sh, 'T17:X18', "등록비 및\n대행수수료", ['size' => 8, 'align' => 'center', 'wrap' => true]);
    put($sh, 'Y17:AN17', '등록비 : 일금                                  0 원정   ', ['size' => 9, 'align' => 'right']);
    put($sh, 'Y18:AN18', '대행수수료 : 일금                             0 원정   ', ['size' => 9, 'align' => 'right']);
    box($sh, 'A17:E18');
    box($sh, 'F17:S18');
    box($sh, 'T17:X18');
    box($sh, 'Y17:AN17');
    box($sh, 'Y18:AN18');

    labelRow($sh, 19, '매매알선수수료', '관 리 비 용');
    num($sh, 'F19:S19', 0, ['size' => 10, 'align' => 'right', 'fmt' => FMT_MONEY]);
    num($sh, 'Y19:AN19', 0, ['size' => 10, 'align' => 'right', 'fmt' => FMT_MONEY]);

    // 🚫 이 문자열을 작은따옴표 + 실제 줄바꿈으로 쓰지 말 것 — pint 의 `single_quote` 가 그렇게 펼치면
    //    파일이 CRLF 일 때 **셀 안에 CR 이 박힌다**(SKILLS §8 #77). 항상 큰따옴표 + \n.
    labelRow($sh, 20, '자동차인도일', "압류 및\n저당권\n등록여부");
    put($sh, 'F20:S20', '', $v);      // 공란 — jin 확정(우리는 매입이라 인도일을 적지 않는다)
    put($sh, 'Y20:AN20', '', $v);     // 공란 — 원부조회는 DB 에 안 남는다. 사람이 보고 적는다(§ 매핑 주석)
}

/** 좌·우 라벨 한 쌍 + 그 행의 상자 4개. */
function labelRow(Worksheet $sh, int $r, string $left, string $right): void
{
    $wrap = str_contains($right, "\n");
    put($sh, "A$r:E$r", $left, ['size' => 9, 'align' => 'center']);
    put($sh, "T$r:X$r", $right, ['size' => $wrap ? 8 : 9, 'align' => 'center', 'wrap' => $wrap]);
    box($sh, "A$r:E$r");
    box($sh, "F$r:S$r");
    box($sh, "T$r:X$r");
    box($sh, "Y$r:AN$r");
}

function buildClauses(Worksheet $sh): void
{
    $r = 21;
    foreach (CLAUSES as $text) {
        // 🚨 **원문의 줄바꿈을 그대로 쓰면 안 된다.** 우리 표 폭이 원본 양식보다 좁아
        //    한 줄이 두 줄로 다시 감기는데, 병합셀은 엑셀이 행 높이를 자동으로 못 맞춰
        //    **아랫줄이 통째로 잘린 채 인쇄된다**(예외 0 · 화면은 정상 · 테스트도 통과).
        //    직접 감아서 줄 수를 확정한다 — 그래야 높이 계산이 구조적으로 맞는다.
        $wrapped = reflow($text, CLAUSE_UNITS);
        $lines = substr_count($wrapped, "\n") + 1;
        put($sh, "A$r:AN$r", $wrapped, ['size' => CLAUSE_PT, 'wrap' => true, 'valign' => 'top']);
        $sh->getRowDimension($r)->setRowHeight($lines * CLAUSE_LH);
        $r++;
    }
    box($sh, 'A21:AN'.($r - 1));
}

/**
 * 글자 폭 예산에 맞춰 문단을 다시 감는다. 둘째 줄부터는 들여쓰기를 붙여
 * 원본 양식의 「조 번호는 왼쪽, 이어지는 줄은 들여쓰기」 모양을 유지한다.
 */
function reflow(string $text, int $maxUnits, string $indent = '       '): string
{
    $flat = preg_replace('/\s*\n\s*/u', ' ', $text);
    $words = preg_split('/ +/u', trim((string) $flat));
    $lines = [];
    $cur = '';
    $indentUnits = mb_strlen($indent);

    foreach ($words as $w) {
        $cand = $cur === '' ? $w : $cur.' '.$w;
        $budget = $lines === [] ? $maxUnits : $maxUnits - $indentUnits;
        if ($cur !== '' && textUnits($cand) > $budget) {
            $lines[] = $cur;
            $cur = $w;
        } else {
            $cur = $cand;
        }
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }

    $out = (string) array_shift($lines);
    foreach ($lines as $l) {
        $out .= "\n".$indent.$l;
    }

    return $out;
}

/** 한글·전각·원문자는 2칸, 그 밖은 1칸으로 센 폭. */
function textUnits(string $s): int
{
    $n = 0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $wide = mb_strwidth($ch) > 1
            || preg_match('/[\x{3130}-\x{318F}\x{AC00}-\x{D7AF}\x{2460}-\x{2473}\x{300C}-\x{300F}\x{00B7}]/u', $ch) === 1;
        $n += $wide ? 2 : 1;
    }

    return $n;
}

function buildSpecial(Worksheet $sh): void
{
    $top = 31;
    $bottom = $top + count(SPECIAL) - 1;   // 44

    put($sh, "A$top:B$bottom", "특\n약\n사\n항",
        ['size' => 9, 'bold' => true, 'align' => 'center', 'wrap' => true]);

    $r = $top;
    foreach (SPECIAL as [$text, $head]) {
        put($sh, "C$r:W$r", ' '.$text, ['size' => 7.5, 'bold' => $head]);
        $sh->getRowDimension($r)->setRowHeight(10.5);
        $r++;
    }
    box($sh, "A$top:B$bottom");
    box($sh, "C$top:W$bottom");

    // 오른쪽 — 성능책임보험료 + 증명 문구 + 발행일. 특약 상자와 같은 행 범위를 나눠 쓴다.
    put($sh, 'X31:AE32', '성능 책임 보험료', ['size' => 10, 'align' => 'center']);
    num($sh, 'AF31:AN32', 0, ['size' => 10, 'align' => 'right', 'fmt' => FMT_WON]);
    box($sh, 'X31:AE32');
    box($sh, 'AF31:AN32');

    // ⚠️ 오른쪽 폭(X:AN ≈ 37 엑셀폭 ≈ 193pt)은 9pt 로 21자밖에 안 들어간다 —
    //    3행에 우겨넣으면 아랫줄이 잘린다. 8pt 로 낮추고 5행을 준다.
    put($sh, 'X34:AN38',
        reflow('「자동차등록규칙」 제33조제2항제2호에 따라 위의 중고자동차매매계약서 기재내용과 같이 양도하였음을 증명합니다.', 46, ''),
        ['size' => 8, 'wrap' => true]);

    put($sh, 'X40:AN41', '        년                 월                 일       ', ['size' => 9, 'align' => 'right']);
}

function buildFooter(Worksheet $sh): void
{
    // 서명줄 — 양도인(갑) / 양수인(을). 도장은 을 쪽에만 찍힌다(StampSlots).
    put($sh, 'A45:F45', '  양도인', ['size' => 10]);
    put($sh, 'G45:P45', '', []);
    put($sh, 'Q45:V45', '(서명 또는 인) ', ['size' => 8, 'align' => 'right']);
    put($sh, 'W45:AB45', '  양수인', ['size' => 10]);
    put($sh, 'AC45:AH45', '', []);
    put($sh, 'AI45:AN45', '(서명 또는 인) ', ['size' => 8, 'align' => 'right']);
    $sh->getRowDimension(45)->setRowHeight(20);

    $notice = reflow(NOTICE, CLAUSE_UNITS, '              ');
    put($sh, 'A46:AN46', $notice, ['size' => CLAUSE_PT, 'wrap' => true, 'valign' => 'top']);
    $sh->getRowDimension(46)->setRowHeight((substr_count($notice, "\n") + 1) * CLAUSE_LH);
    box($sh, 'A46:AN46');

    put($sh, 'A47:AA47', '경 기 도 자 동 차 매 매 사 업 조 합', ['size' => 11, 'align' => 'center']);
    put($sh, 'AB47:AN47', '210mm×297mm[백상지 80g/㎡] ', ['size' => 7, 'align' => 'right']);
    $sh->getRowDimension(47)->setRowHeight(18);
}

/**
 * 🟡 매핑이 쓰는 칸만 노랗게. 좌표는 매핑 상수를 **읽는다** — 옮겨 적지 않으므로 어긋날 수 없다.
 */
function paintYellow(Worksheet $sh): void
{
    foreach (TransferCertificateMapping::CELLS as $coord) {
        $range = mergedRangeOf($sh, $coord) ?? $coord;
        $sh->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(YELLOW);
    }
}

function mergedRangeOf(Worksheet $sh, string $coord): ?string
{
    foreach ($sh->getMergeCells() as $range) {
        if (str_starts_with($range, $coord.':')) {
            return $range;
        }
    }

    return null;
}

function pageSetup(Worksheet $sh): void
{
    $ps = $sh->getPageSetup();
    $ps->setPaperSize(PageSetup::PAPERSIZE_A4);
    $ps->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
    // fitToPage 는 **안전망**이다 — 레이아웃이 이미 한 장에 들어가게 짜여 있다.
    // 이것에 기대면 값 글자까지 같이 줄어 읽기 어려워진다(SKILLS §8 #71-C 의 그 축).
    $ps->setFitToPage(true);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(1);
    $ps->setPrintArea('A1:AN47');

    $m = $sh->getPageMargins();
    $m->setTop(0.28)->setBottom(0.2)->setLeft(0.28)->setRight(0.28)->setHeader(0)->setFooter(0);
}

// ── 셀 헬퍼 ────────────────────────────────────────────────────────────
function put(Worksheet $sh, string $range, string $value, array $opt): void
{
    $anchor = str_contains($range, ':') ? explode(':', $range)[0] : $range;
    if (str_contains($range, ':')) {
        $sh->mergeCells($range);
    }
    if ($value === '') {
        $sh->getCell($anchor)->setValueExplicit(null, DataType::TYPE_NULL);
    } else {
        $sh->getCell($anchor)->setValueExplicit($value, DataType::TYPE_STRING);
    }
    style($sh, $range, $opt);
}

function num(Worksheet $sh, string $range, int $value, array $opt): void
{
    $anchor = str_contains($range, ':') ? explode(':', $range)[0] : $range;
    if (str_contains($range, ':')) {
        $sh->mergeCells($range);
    }
    $sh->getCell($anchor)->setValueExplicit($value, DataType::TYPE_NUMERIC);
    style($sh, $range, $opt);
}

function style(Worksheet $sh, string $range, array $opt): void
{
    $st = $sh->getStyle($range);
    $font = $st->getFont();
    $font->setName('맑은 고딕');
    if (isset($opt['size'])) {
        $font->setSize($opt['size']);
    }
    if (! empty($opt['bold'])) {
        $font->setBold(true);
    }
    if (isset($opt['color'])) {
        $font->getColor()->setARGB($opt['color']);
    }

    $al = $st->getAlignment();
    $al->setHorizontal(match ($opt['align'] ?? 'left') {
        'center' => Alignment::HORIZONTAL_CENTER,
        'right' => Alignment::HORIZONTAL_RIGHT,
        default => Alignment::HORIZONTAL_LEFT,
    });
    $al->setVertical(($opt['valign'] ?? 'center') === 'top' ? Alignment::VERTICAL_TOP : Alignment::VERTICAL_CENTER);
    if (! empty($opt['wrap'])) {
        $al->setWrapText(true);
    } else {
        // 🚫 wrap 과 shrinkToFit 을 같이 켜지 말 것 — 엑셀이 축소를 무시한다(SKILLS §8 #71-C).
        $al->setShrinkToFit(true);
    }

    // 값칸은 한 칸 들여쓴다 — 라벨이 가운데 정렬이라 상자 오른쪽 끝에 닿아서,
    // 들여쓰기가 없으면 「자동차등록번호99테0001」처럼 라벨과 값이 붙어 보인다.
    if (! empty($opt['indent'])) {
        $al->setIndent((int) $opt['indent']);
    }

    if (isset($opt['fmt'])) {
        $st->getNumberFormat()->setFormatCode($opt['fmt']);
    }
}

/** 상자 하나 = 바깥 테두리만. 안쪽 세로선이 안 생겨 「라벨 | 값 | 주석」이 한 칸처럼 보인다. */
function box(Worksheet $sh, string $range): void
{
    $sh->getStyle($range)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
}

// ── 대조 ──────────────────────────────────────────────────────────────
function verify(string $templateDir): void
{
    $bad = 0;
    foreach (TENANTS as $set => $co) {
        $path = sprintf('%s/%s/%s', $templateDir, $set, OUT_NAME);
        echo "=== $set ===\n";
        if (! is_file($path)) {
            echo "  ❌ 파일 없음: $path\n";
            $bad++;

            continue;
        }
        $ss = IOFactory::createReader('Xlsx')->load($path);
        $sh = $ss->getSheetByName(SHEET);
        if (! $sh) {
            echo '  ❌ 시트 없음: '.SHEET."\n";
            $bad++;
            $ss->disconnectWorksheets();

            continue;
        }

        // 회사정보 — 「대상 목록」이 아니라 **값 비교**로 확인한다(SKILLS §8 #71-D).
        foreach ([
            'AD5' => $co['name'], 'AD6' => $co['phone'], 'AD7' => $co['addr'],
            'H8' => '  '.$co['dealer_no'], 'X8' => '  '.$co['name'],
            'H9' => '  '.$co['ceo'], 'H10' => '  '.$co['agent'],
        ] as $coord => $want) {
            $got = (string) $sh->getCell($coord)->getValue();
            $ok = $got === $want;
            echo sprintf("  %s %-5s %s\n", $ok ? '✅' : '❌', $coord, $ok ? trim($got) : "기대 '$want' / 실제 '$got'");
            $bad += $ok ? 0 : 1;
        }

        // 🟡 노란칸이 매핑 좌표와 정확히 일치하는가.
        // 병합된 칸은 **앵커(좌상단)만** 센다 — 나머지 칸은 같은 스타일을 물려받을 뿐이고,
        // `DocumentFiller` 도 앵커 기준으로 기입한다. 안 거르면 병합 내부 셀이 전부 「여분」으로 뜬다.
        $inner = [];
        foreach ($sh->getMergeCells() as $range) {
            foreach (Coordinate::extractAllCellReferencesInRange($range) as $c) {
                if ($c !== explode(':', $range)[0]) {
                    $inner[$c] = true;
                }
            }
        }
        $wantYellow = array_values(TransferCertificateMapping::CELLS);
        $gotYellow = [];
        foreach ($sh->getCoordinates() as $coord) {
            if (isset($inner[$coord])) {
                continue;
            }
            if ($sh->getStyle($coord)->getFill()->getStartColor()->getARGB() === YELLOW) {
                $gotYellow[] = $coord;
            }
        }
        sort($wantYellow);
        sort($gotYellow);
        $missing = array_values(array_diff($wantYellow, $gotYellow));
        $extra = array_values(array_diff($gotYellow, $wantYellow));
        if ($missing === [] && $extra === []) {
            echo '  ✅ 노란칸 '.count($gotYellow)."개 = 매핑 좌표와 일치\n";
        } else {
            echo '  ❌ 노란칸 불일치 — 빠짐 ['.implode(',', $missing).'] / 여분 ['.implode(',', $extra)."]\n";
            $bad++;
        }

        // 다른 회사 값이 섞여 있지 않은가(SKILLS §8 #75 — 테넌트 파생 잔재).
        foreach (TENANTS as $other => $oc) {
            if ($other === $set) {
                continue;
            }
            $hit = [];
            foreach ($sh->getCoordinates() as $coord) {
                $v = (string) $sh->getCell($coord)->getValue();
                if ($v === '') {
                    continue;
                }
                if (str_contains($v, $oc['name']) && $oc['name'] !== $co['name']) {
                    $hit[] = $coord;
                }
                if ($oc['dealer_no'] !== '' && $oc['dealer_no'] !== $co['dealer_no'] && str_contains($v, $oc['dealer_no'])) {
                    $hit[] = $coord;
                }
            }
            if ($hit !== []) {
                echo "  ❌ $other 값 잔존: ".implode(',', array_unique($hit))."\n";
                $bad++;
            }
        }

        $ss->disconnectWorksheets();
    }

    echo "\n".($bad === 0 ? "✅ 전부 일치\n" : "❌ 문제 $bad 건\n");
}
