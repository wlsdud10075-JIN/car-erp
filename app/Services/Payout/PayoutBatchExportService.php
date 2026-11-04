<?php

namespace App\Services\Payout;

use App\Models\ExportLog;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Services\SettlementExportService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 월정산 엑셀 (jin 2026-11-04 (나) «내수결재 트리 탄 거, 영업들 정산 트리 탄 거(검차 포함) 전부 엑셀로»).
 *
 * - 사내직원 월정산·퇴사 정산 = 「사람별 지급」(화면 카드와 같은 숫자, 검차·관리·업무관리자 포함) + 「급여 항목」
 *   + 종전 정산 엑셀(요약 · 담당자별 차량 명세) 그대로 뒤에.
 * - 내수직원 월정산 = 「내수직원」(평가수수료정산 항목 그대로 + 지급합계) + 「급여 항목」.
 *
 * 🔑 숫자는 전부 `breakdownForDisplay()` — 결재 중이면 지금 계산, 끝난 배치는 박제. 화면·승인 링크와 같은 출처라
 *    엑셀 합계 = 카드 지급합계다. 🚫 여기서 다시 계산하지 말 것(§8 #45).
 */
class PayoutBatchExportService
{
    public function __construct(private SettlementExportService $settlementExporter) {}

    public function build(SettlementPayoutBatch $batch): Spreadsheet
    {
        // 엑셀은 한국어 고정 — 승인 링크는 세션이 없어 접속자 언어가 아니라 기본 언어로 그려진다(유형 라벨 「Employee」 방지).
        $locale = app()->getLocale();
        app()->setLocale('ko');
        try {
            return $this->buildKo($batch);
        } finally {
            app()->setLocale($locale);
        }
    }

    private function buildKo(SettlementPayoutBatch $batch): Spreadsheet
    {
        $bd = $batch->breakdownForDisplay();
        $people = array_values($bd['people'] ?? []);

        if ($batch->isDomesticBatch()) {
            $ss = new Spreadsheet;
            $this->domesticSheet($ss->getActiveSheet(), $people, $bd['domestic_fee_total'] ?? null);
            $this->payrollItemsSheet($ss->createSheet(), $people);
            $ss->setActiveSheetIndex(0);

            return $ss;
        }

        $settlements = $batch->settlements()
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])
            ->orderBy('salesman_id')->orderBy('id')
            ->get();
        $ss = $this->settlementExporter->build($settlements);   // 요약 · 담당자별 — 종전 엑셀 그대로
        $this->peopleSheet($ss->createSheet(0), $people, $bd['totals'] ?? [], (bool) ($bd['domestic_split'] ?? false));
        $this->payrollItemsSheet($ss->createSheet(1), $people);
        $ss->setActiveSheetIndex(0);

        return $ss;
    }

    /** 내려받기 — ERP 월정산 화면·승인 링크 공용. 받은 기록은 내보내기 로그에(종전 승인 링크 엑셀과 같은 target·scope). */
    public function download(SettlementPayoutBatch $batch, ?User $user, ?string $ip): StreamedResponse
    {
        $spreadsheet = $this->build($batch);
        $sheets = array_map(fn (Worksheet $w) => $w->getTitle(), $spreadsheet->getAllSheets());

        ExportLog::create([
            'user_id' => $user?->id,
            'ip_address' => $ip,
            'target' => 'settlements',
            'scope' => 'payout_batch',
            'row_count' => count($batch->breakdownForDisplay()['people'] ?? []),
            'columns' => array_slice($sheets, 0, 3),
            'filters' => ['batch' => (string) $batch->id, 'month' => (string) $batch->month, 'kind' => (string) ($batch->kind ?? 'month')],
        ]);

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $this->filename($batch),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function filename(SettlementPayoutBatch $batch): string
    {
        $kind = $batch->isDomesticBatch() ? '내수직원월정산' : ($batch->isLeaverBatch() ? '퇴사정산' : '사내직원월정산');

        return $kind.'_'.$batch->month.'_배치'.$batch->id.'.xlsx';
    }

    /** 사람별 지급 — 카드 한 장 = 한 줄. */
    private function peopleSheet(Worksheet $sheet, array $people, array $totals, bool $domesticSplit): void
    {
        $sheet->setTitle('사람별 지급');
        $cols = [
            ['No.', null], ['이름', 'name'], ['유형', 'type_label'], ['정산 건수', 'count'],
            ['급여', 'payroll'], ['정산금', 'settlement_pay'], ['헤이맨 정산금', 'heyman_pay'],
            ['인센티브', 'incentive'], ['조정', 'adj_manual'], ['이월', 'adj_carry'], ['예치금 반환', 'adj_deposit'],
            ['퇴직연금 몫(따로)', 'pension'], ['지급합계(송금)', 'payout'],
            ['총마진', 'total_margin'], ['발송비', 'shipping'], ['회사이익', 'company_contribution'],
        ];
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $this->header($sheet, array_column($cols, 0));

        // 🗂️ 유형별 묶음(jin 2026-11-04 «유형별로 나누면 더 깔끔») — 머리줄 「■ 유형 (N명)」 → 사람 → 소계. 맨 아래 합계 = 소계들의 합.
        $row = 2;
        $subtotalRows = [];
        foreach ($this->groupByType($people) as $label => $group) {
            $sheet->setCellValueExplicit("A{$row}", '■ '.$label.' ('.count($group).'명)', DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F3F4F6');
            $row++;
            $first = $row;
            foreach ($group as $i => $p) {
                foreach ($cols as $c => [, $key]) {
                    $cell = Coordinate::stringFromColumnIndex($c + 1).$row;
                    if ($key === null) {
                        $sheet->setCellValue($cell, $i + 1);
                    } elseif (in_array($key, ['name', 'type_label'], true)) {
                        $sheet->setCellValueExplicit($cell, (string) ($p[$key] ?? ''), DataType::TYPE_STRING);
                    } elseif (($p[$key] ?? null) !== null) {
                        $sheet->setCellValue($cell, (int) $p[$key]);
                    }
                }
                $row++;
            }
            $sheet->setCellValueExplicit("B{$row}", $label.' 소계', DataType::TYPE_STRING);
            for ($c = 4; $c <= count($cols); $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}{$first}:{$col}".($row - 1).')');
            }
            $sheet->getStyle("B{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getBorders()->getTop()->setBorderStyle('thin');
            $subtotalRows[] = $row;
            $row += 2;   // 묶음 사이 한 줄
        }
        if ($subtotalRows !== []) {
            $sheet->setCellValueExplicit("B{$row}", '합계', DataType::TYPE_STRING);
            for ($c = 4; $c <= count($cols); $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $sheet->setCellValue("{$col}{$row}", '='.implode('+', array_map(fn ($r) => $col.$r, $subtotalRows)));
            }
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('ECE9F8');
        }
        $this->money($sheet, "E2:{$last}".max(2, $row));

        // 화면 「합계」 칸과 같은 숫자 — 회사 순이익 = 기여 합 − 공통 인건비(검차·관리) + 내수 총 회사수익
        $summary = [
            ['송금 총액(퇴직연금 몫 포함)', $totals['transfer_total'] ?? null],
            ['회사 기여 합', $totals['contribution_sum'] ?? null],
            ['공통 인건비(검차·관리)', $totals['common_labor'] ?? null],
        ];
        if ($domesticSplit) {
            $summary[] = ['내수직원 총 회사수익', $totals['domestic_profit'] ?? null];
        }
        $summary[] = ['회사 순이익', $totals['company_net'] ?? null];
        $r = $row + 2;
        foreach ($summary as [$label, $value]) {
            $sheet->setCellValueExplicit("B{$r}", $label, DataType::TYPE_STRING);
            if ($value !== null) {
                $sheet->setCellValue("E{$r}", (int) $value);
            }
            $r++;
        }
        $this->money($sheet, 'E'.($row + 2).":E{$r}");
        $this->autosize($sheet, count($cols));
    }

    /** 내수직원 — 평가수수료정산 엑셀의 항목 그대로 + 지급합계. 맨 아래 = 화면 합계 줄(엑셀 합계 행)과 같은 값. */
    private function domesticSheet(Worksheet $sheet, array $people, ?array $feeTotal): void
    {
        $sheet->setTitle('내수직원');
        $labels = [];
        foreach ($people as $p) {
            foreach ($p['domestic_fee'] ?? [] as $it) {
                if (! in_array($it['label'], $labels, true)) {
                    $labels[] = $it['label'];
                }
            }
        }
        $head = array_merge(['No.', '이름'], $labels, ['지급합계']);
        $last = Coordinate::stringFromColumnIndex(count($head));
        $this->header($sheet, $head);

        $row = 2;
        foreach ($people as $i => $p) {
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValueExplicit("B{$row}", (string) ($p['name'] ?? ''), DataType::TYPE_STRING);
            $byLabel = collect($p['domestic_fee'] ?? [])->pluck('value', 'label');
            foreach ($labels as $k => $label) {
                $v = $byLabel->get($label);
                if ($v !== null && $v !== '') {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($k + 3).$row, $v);
                }
            }
            $sheet->setCellValue("{$last}{$row}", (int) ($p['payout'] ?? 0));
            $row++;
        }
        if ($row > 2) {
            $sheet->setCellValueExplicit("B{$row}", '합계', DataType::TYPE_STRING);
            $totals = collect($feeTotal['items'] ?? [])->pluck('value', 'label');
            foreach ($labels as $k => $label) {
                $col = Coordinate::stringFromColumnIndex($k + 3);
                $sheet->setCellValue("{$col}{$row}", $totals->get($label) ?? "=SUM({$col}2:{$col}".($row - 1).')');
            }
            $sheet->setCellValue("{$last}{$row}", "=SUM({$last}2:{$last}".($row - 1).')');
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        }
        $this->money($sheet, "C2:{$last}".max(2, $row));
        $this->autosize($sheet, count($head));
    }

    /** 급여 항목 — 사람 × 기입된 항목(행 없는 항목은 안 실린다). 프리랜서는 급여가 없어 빠진다. */
    private function payrollItemsSheet(Worksheet $sheet, array $people): void
    {
        // 🪪 한 사람 = 카드 한 장(jin 2026-11-04) — 이름·유형 머리 → 항목·금액 → 합계, 테두리. 유형별로 한 줄에 CARDS_PER_ROW 장.
        $sheet->setTitle('급여 항목');
        $perRow = self::CARDS_PER_ROW;
        for ($k = 0; $k < $perRow; $k++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($k * 3 + 1))->setWidth(18);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($k * 3 + 2))->setWidth(14);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($k * 3 + 3))->setWidth(3);
        }
        $lastCol = Coordinate::stringFromColumnIndex($perRow * 3 - 1);

        $row = 1;
        $groups = $this->groupByType(array_values(array_filter($people, fn ($p) => ($p['type'] ?? '') !== 'freelance')));
        foreach ($groups as $label => $group) {
            $sheet->setCellValueExplicit("A{$row}", '■ '.$label.' ('.count($group).'명)', DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F3F4F6');
            $row += 2;
            foreach (array_chunk($group, $perRow) as $band) {
                $height = 0;
                foreach ($band as $k => $p) {
                    $height = max($height, $this->payrollCard($sheet, $k * 3 + 1, $row, $p));
                }
                $row += $height + 1;
            }
            $row++;
        }
    }

    private const CARDS_PER_ROW = 4;

    /** 카드 한 장을 (열, 행)에 그린다 — 그린 줄 수를 돌려준다. 항목이 없으면 「급여 미입력」. */
    private function payrollCard(Worksheet $sheet, int $col, int $top, array $p): int
    {
        $a = Coordinate::stringFromColumnIndex($col);
        $b = Coordinate::stringFromColumnIndex($col + 1);
        $sheet->mergeCells("{$a}{$top}:{$b}{$top}");
        $sheet->setCellValueExplicit("{$a}{$top}", (string) ($p['name'] ?? '').' · '.(string) ($p['type_label'] ?? ''), DataType::TYPE_STRING);
        $sheet->getStyle("{$a}{$top}")->getFont()->setBold(true);
        $sheet->getStyle("{$a}{$top}:{$b}{$top}")->getFill()->setFillType('solid')->getStartColor()->setRGB('ECE9F8');

        $r = $top + 1;
        $items = $p['payroll_items'] ?? [];
        if ($items === []) {
            $sheet->setCellValueExplicit("{$a}{$r}", '급여 미입력', DataType::TYPE_STRING);
            $sheet->getStyle("{$a}{$r}")->getFont()->getColor()->setRGB('9CA3AF');
            $r++;
        }
        foreach ($items as $it) {
            $sheet->setCellValueExplicit("{$a}{$r}", (string) ($it['label'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("{$b}{$r}", (int) ($it['amount'] ?? 0));
            $r++;
        }
        $sheet->setCellValueExplicit("{$a}{$r}", '합계', DataType::TYPE_STRING);
        $sheet->setCellValue("{$b}{$r}", $items === [] ? 0 : "=SUM({$b}".($top + 1).":{$b}".($r - 1).')');
        $sheet->getStyle("{$a}{$r}:{$b}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("{$a}{$r}:{$b}{$r}")->getBorders()->getTop()->setBorderStyle('thin');
        $sheet->getStyle("{$b}".($top + 1).":{$b}{$r}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("{$a}{$top}:{$b}{$r}")->getBorders()->getOutline()->setBorderStyle('thin');

        return $r - $top + 1;
    }

    /**
     * 유형별 묶음 — 화면 유형 라벨 기준, 이 순서로(그 밖의 라벨은 뒤에 나온 순서대로). 묶음 안 순서는 카드 순서 그대로.
     *
     * @return array<string, list<array>>
     */
    private function groupByType(array $people): array
    {
        $order = ['사내직원', '프리랜서', '검차직원', '업무관리자', '관리', '재무', '수출통관', '내수직원'];
        $groups = [];
        foreach ($people as $p) {
            $groups[(string) ($p['type_label'] ?? '기타')][] = $p;
        }
        uksort($groups, function ($x, $y) use ($order) {
            $ix = array_search($x, $order, true);
            $iy = array_search($y, $order, true);

            return ($ix === false ? 99 : $ix) <=> ($iy === false ? 99 : $iy);
        });

        return $groups;
    }

    private function header(Worksheet $sheet, array $labels): void
    {
        foreach ($labels as $i => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).'1', $label, DataType::TYPE_STRING);
        }
        $last = Coordinate::stringFromColumnIndex(count($labels));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('ECE9F8');
        $sheet->freezePane('C2');
    }

    private function money(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0');
    }

    private function autosize(Worksheet $sheet, int $n): void
    {
        for ($c = 1; $c <= $n; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }
}
