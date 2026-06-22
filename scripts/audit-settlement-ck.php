<?php

/**
 * 정산 데이터 정리 — xlsx CK(비고/정산일) ↔ 서버 import 정산 대조 (읽기전용, 2026-06-22).
 *   php scripts/audit-settlement-ck.php "1. 헤이맨 수출차량현황표.xlsx"
 *
 * 규칙 (jin xlsx 권위, 2026-06-22 확정):
 *   - CK 에 '정산' 포함 AND '미정산' 미포함  = 진짜 정산됨 → paid 유지.
 *       (예: "26.05.10정산", "6월 정산")
 *   - 빈칸 OR '미정산' 포함                  = 정산 안 됨 → 정산행 없어야 함(제거 대상).
 *       (예: "(빈칸)", "6월 미정산" — jin: "6월 미정산은 진짜 미정산, 빈칸과 같다")
 * 진행상태(progress_status_cache)와 교차해 분류.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Settlement;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? '1. 헤이맨 수출차량현황표.xlsx';
if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:/', $path)) {
    $path = __DIR__.'/../'.$path;
}
if (! is_file($path)) {
    fwrite(STDERR, "❌ 파일 없음: {$path}\n");
    exit(1);
}

echo "xlsx: {$path}\n";
$reader = IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(false);
$book = $reader->load($path);
$sheet = $book->getSheetByName('수출차량매입-2026') ?? $book->getSheet(0);

$bare = fn ($p) => str_replace(' ', '', (string) $p);
$cell = function (string $col, int $row) use ($sheet) {
    try {
        $v = $sheet->getCell($col.$row)->getCalculatedValue();
    } catch (Throwable $e) {
        $v = $sheet->getCell($col.$row)->getValue();
    }

    return $v === null ? '' : trim((string) $v);
};

// xlsx 인덱스: bare plate → ['ck' => string, 'cg' => string, 'row' => int]
$xlsx = [];
$lastRow = $sheet->getHighestDataRow();
for ($r = 3; $r <= $lastRow; $r++) {
    $plate = $cell('D', $r);
    if ($plate === '') {
        continue;
    }
    $xlsx[$bare($plate)] = ['ck' => $cell('CK', $r), 'cg' => $cell('CG', $r), 'row' => $r, 'plate' => $plate];
}
// 정산됨 판정 — '정산' 포함 AND '미정산' 미포함
$isSettled = fn (string $ck) => $ck !== '' && str_contains($ck, '정산') && ! str_contains($ck, '미정산');

echo 'xlsx 차량번호 행: '.count($xlsx)."\n";
$ckSettled = count(array_filter($xlsx, fn ($x) => $isSettled($x['ck'])));
echo "  그중 정산됨(CK '정산'·'미정산'아님): {$ckSettled} / 미정산: ".(count($xlsx) - $ckSettled)."\n\n";

// 서버 import 정산 146건 대조
$settlements = Settlement::with('vehicle')->where('note', 'like', 'import — %')->get();
echo 'import 정산: '.$settlements->count()."건\n\n";

$g = ['G1_delete' => [], 'G2_keep' => [], 'G3_review_done_noCK' => [], 'G4_review_prog_CK' => [], 'G5_no_xlsx' => []];

foreach ($settlements as $s) {
    $v = $s->vehicle;
    if (! $v) {
        $g['G5_no_xlsx'][] = ['sid' => $s->id, 'plate' => '(차량없음 vid='.$s->vehicle_id.')'];

        continue;
    }
    $key = $bare($v->vehicle_number);
    $x = $xlsx[$key] ?? null;
    $prog = $v->progress_status_cache ?? '(null)';
    $done = $prog === '거래완료';

    if (! $x) {
        $g['G5_no_xlsx'][] = ['sid' => $s->id, 'plate' => $v->vehicle_number, 'prog' => $prog];

        continue;
    }
    $settled = $isSettled($x['ck']);

    $rec = ['sid' => $s->id, 'vid' => $v->id, 'plate' => $v->vehicle_number, 'prog' => $prog, 'ck' => $x['ck'], 'cg' => $x['cg'], 'payout' => $s->actual_payout];

    if (! $settled && ! $done) {
        $g['G1_delete'][] = $rec;          // 진행중 + 미정산 → 명백히 제거
    } elseif ($settled && $done) {
        $g['G2_keep'][] = $rec;            // 거래완료 + 정산됨 → 정상 유지
    } elseif (! $settled && $done) {
        $g['G3_review_done_noCK'][] = $rec; // 거래완료인데 미정산 → 검토
    } else {
        $g['G4_review_prog_CK'][] = $rec;   // 진행중인데 정산됨 → 검토(유지 후보)
    }
}

foreach ($g as $name => $rows) {
    echo "── {$name}: ".count($rows)."건\n";
}
echo "\n";

$show = function (string $name, array $rows, int $limit = 100) {
    if (! $rows) {
        return;
    }
    echo "════ {$name} ({{count}}건) ════\n";
    echo str_replace('{{count}}', (string) count($rows), '');
    $i = 0;
    foreach ($rows as $r) {
        if ($i++ >= $limit) {
            echo '   ... +'.(count($rows) - $limit)."건 더\n";
            break;
        }
        $ck = isset($r['ck']) ? mb_substr($r['ck'], 0, 20) : '';
        printf("   sid=%-5s vid=%-5s %-12s prog=%-8s CK=[%s] CG=%s\n",
            $r['sid'] ?? '?', $r['vid'] ?? '?', $r['plate'] ?? '?', $r['prog'] ?? '?', $ck, $r['cg'] ?? '');
    }
    echo "\n";
};

$show('G1 제거대상 (진행중 + CK없음)', $g['G1_delete']);
$show('G3 검토 (거래완료인데 CK없음)', $g['G3_review_done_noCK']);
$show('G4 검토 (진행중인데 CK있음)', $g['G4_review_prog_CK']);
$show('G5 xlsx에 차량번호 없음', $g['G5_no_xlsx']);
echo 'G2 정상유지(거래완료+CK있음)는 생략 — '.count($g['G2_keep'])."건\n";
