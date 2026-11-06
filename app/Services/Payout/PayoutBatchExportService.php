<?php

namespace App\Services\Payout;

use App\Models\ExportLog;
use App\Models\PayrollEntry;
use App\Models\Salesman;
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
            $this->payrollItemsSheet($ss->createSheet(), $people, $this->titleFor($batch, '급여 항목'), (string) $batch->month);
            $ss->setActiveSheetIndex(0);

            return $ss;
        }

        $settlements = $batch->settlements()
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])
            ->orderBy('salesman_id')->orderBy('id')
            ->get();
        $ss = $this->settlementExporter->build($settlements);   // 요약 · 담당자별 — 종전 엑셀 그대로
        // ⏸ 지급보류(2026-11-06) — 승인된 사내직원 월정산의 보류 장부를 「보류」 열·「실송금」 줄로 겹쳐 보여 준다(박제 무변경).
        $ledgerOn = $batch->kind === SettlementPayoutBatch::KIND_MONTH && $batch->status === SettlementPayoutBatch::STATUS_APPROVED;
        $holds = $ledgerOn ? $batch->heldAmountsBySalesman() : [];
        $extras = $ledgerOn ? $batch->extraAmountsBySalesman() : [];   // ➕ 지급추가(송금에 더함)
        $extraPaidOutside = $ledgerOn ? $batch->extraPaidOutsideTotal() : 0;   // (가) 월정산 밖 추가 지급 = 그 달 회사이익 차감
        // ✅ 최종 확정(jin 2026-11-06) — 확정본은 제목에 도장 + 사람별 「실제 송금」 열. 확정 전 승인 배치는 「확정 전」 표시(숫자가 바뀔 수 있다).
        $stamp = $batch->isFinalized()
            ? ' — 최종 확정 '.$batch->finalized_at?->format('Y-m-d').' '.($batch->finalizedBy?->name ?? '')
            : ($ledgerOn ? ' (확정 전 — 보류·추가가 바뀔 수 있음)' : '');
        $this->peopleSheet($ss->createSheet(0), $people, $bd['totals'] ?? [], (bool) ($bd['domestic_split'] ?? false), $holds, $extras,
            $this->titleFor($batch, '사람별 지급 내역').$stamp, (string) $batch->month, $ledgerOn, $extraPaidOutside);
        $this->payrollItemsSheet($ss->createSheet(1), $people, $this->titleFor($batch, '급여 항목'), (string) $batch->month);
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

    /** 시트 제목 — 「2026년 10월 사람별 지급 내역」(jin 수정본). 퇴사·내수 배치는 종류를 앞에 붙인다. */
    private function titleFor(SettlementPayoutBatch $batch, string $what): string
    {
        [$y, $m] = array_pad(explode('-', (string) $batch->month), 2, '');
        $prefix = $batch->isDomesticBatch() ? '내수직원 월정산 ' : ($batch->isLeaverBatch() ? '퇴사 정산 ' : '');

        return $prefix.((int) $y).'년 '.((int) $m).'월 '.$what;
    }

    public function filename(SettlementPayoutBatch $batch): string
    {
        $kind = $batch->isDomesticBatch() ? '내수직원월정산' : ($batch->isLeaverBatch() ? '퇴사정산' : '사내직원월정산');

        return $kind.'_'.$batch->month.'_배치'.$batch->id.'.xlsx';
    }

    /**
     * 사람별 지급 — jin 2026-11-06 수정본(`## 2026 수출정산_26.10_ERP_최종.xlsx`) 형식 그대로.
     * B1 제목 · B2 부제 · 4행 머리(305496 흰 글자) · 유형별로 사람 줄이 이어지고(묶음 사이 빈 줄 없음) B열 「구분」을 소계까지 세로 병합 ·
     * 소계(유형색 배경) · 합계(EDEDED, 위아래 굵은 선) · 요약(라벨 C:F 병합 / 값 G, 수식) · 각주.
     * 「지급보류분」·「⏸ 보류」 열은 그 배치에 보류가 있을 때만 끼운다(보통 달은 jin 파일과 글자 단위로 같다).
     */
    private function peopleSheet(Worksheet $sheet, array $people, array $totals, bool $domesticSplit, array $holds, array $extras, string $title, string $month, bool $ledgerOn = false, int $extraPaidOutside = 0): void
    {
        $sheet->setTitle('사람별 지급');
        $hasHeld = collect($people)->contains(fn ($p) => (int) ($p['adj_held'] ?? 0) !== 0);
        $hasExtra = collect($people)->contains(fn ($p) => (int) ($p['adj_extra'] ?? 0) !== 0);
        $cols = [
            ['구분', '__type', 10.66], ['No.', '__no', 5], ['이름', 'name', 14], ['정산 건수', 'count', 7.66],
            ['급여', 'payroll', 12], ['정산금', 'settlement_pay', null], ['헤이맨 정산금', 'heyman_pay', 10.55],
            ['인센티브', 'incentive', null], ['조정', 'adj_manual', null], ['이월', 'adj_carry', null], ['예치금 반환', 'adj_deposit', null],
        ];
        if ($hasHeld) {
            $cols[] = ['지급보류분', 'adj_held', 11];
        }
        if ($hasExtra) {
            $cols[] = ['지급추가분', 'adj_extra', 11];
        }
        $cols[] = ['퇴직연금 몫(따로)', 'pension', null];
        $cols[] = ['지급합계(송금)', 'payout', 14.89];
        if ($holds !== []) {
            $cols[] = ['⏸ 보류(송금 제외)', '__hold', 14];
        }
        if ($extras !== []) {
            $cols[] = ['➕ 추가(송금 더함)', '__extra', 14];
        }
        if ($ledgerOn) {   // 승인된 월정산은 사람별 실제 송금(지급합계 − 보류 + 추가) — 재무가 이 열로 송금한다
            $cols[] = ['실제 송금', '__net', 14.89];
        }
        $cols = [...$cols, ['총마진', 'total_margin', 13], ['발송비', 'shipping', 9], ['회사이익', 'company_contribution', 13]];

        $letter = [];   // 키 → 열 글자 (B 부터)
        foreach ($cols as $i => [, $key, $w]) {
            $letter[$key] = Coordinate::stringFromColumnIndex($i + 2);
            if ($w !== null) {
                $sheet->getColumnDimension($letter[$key])->setWidth($w);
            }
        }
        $sheet->getColumnDimension('A')->setWidth(2);
        $last = end($letter);
        $firstNum = $letter['count'];   // E — 숫자 열 시작

        $this->titleRows($sheet, $title, $month);
        $this->tableHeader($sheet, array_column($cols, 0), 4, 30);

        $row = 5;
        $subtotalRows = [];
        $laborSubtotals = [];   // 급여만 유형(검차·업무관리자·관리·재무)의 소계 행 — 공통 인건비 수식
        foreach ($this->groupByType($people) as $label => $group) {
            $first = $row;
            foreach ($group as $i => $p) {
                foreach ($cols as [, $key]) {
                    $cell = $letter[$key].$row;
                    if ($key === '__type') {
                        continue;
                    } elseif ($key === '__no') {
                        $sheet->setCellValue($cell, $i + 1);
                    } elseif ($key === '__hold') {
                        if (isset($holds[(int) ($p['salesman_id'] ?? 0)])) {
                            $sheet->setCellValue($cell, $holds[(int) $p['salesman_id']]);
                        }
                    } elseif ($key === '__extra') {
                        if (isset($extras[(int) ($p['salesman_id'] ?? 0)])) {
                            $sheet->setCellValue($cell, $extras[(int) $p['salesman_id']]);
                        }
                    } elseif ($key === '__net') {
                        $sid = (int) ($p['salesman_id'] ?? 0);
                        $sheet->setCellValue($cell, (int) ($p['payout'] ?? 0) - (int) ($holds[$sid] ?? 0) + (int) ($extras[$sid] ?? 0));
                    } elseif ($key === 'name') {
                        $sheet->setCellValueExplicit($cell, (string) ($p[$key] ?? ''), DataType::TYPE_STRING);
                    } elseif (($p[$key] ?? null) !== null) {
                        $sheet->setCellValue($cell, (int) $p[$key]);
                    }
                }
                $sheet->getRowDimension($row)->setRowHeight(18.9);
                $row++;
            }
            // 소계 — C:D 병합, 숫자 열 SUM, 유형색 배경
            $sheet->mergeCells("C{$row}:D{$row}");
            $sheet->setCellValueExplicit("C{$row}", $label.' 소계', DataType::TYPE_STRING);
            foreach ($cols as [, $key]) {
                if (in_array($key, ['__type', '__no', 'name'], true)) {
                    continue;
                }
                $c = $letter[$key];
                $sheet->setCellValue("{$c}{$row}", "=SUM({$c}{$first}:{$c}".($row - 1).')');
            }
            $fill = $this->typeFill($label);
            $this->fill($sheet, "B{$row}:{$last}{$row}", $fill);
            $sheet->getStyle("B{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal('center');
            $sheet->getRowDimension($row)->setRowHeight(21);
            // 구분 — 사람 줄 + 소계까지 세로 병합
            $sheet->mergeCells("B{$first}:B{$row}");
            $sheet->setCellValueExplicit("B{$first}", $label, DataType::TYPE_STRING);
            $this->fill($sheet, "B{$first}:B{$row}", $fill);
            $sheet->getStyle("B{$first}")->getFont()->setBold(true);
            $sheet->getStyle("B{$first}")->getAlignment()->setHorizontal('center')->setVertical('center');
            $subtotalRows[] = $row;
            if (in_array($group[0]['type'] ?? '', Salesman::PAYROLL_ONLY_TYPES, true)) {
                $laborSubtotals[] = $row;
            }
            $row++;
        }
        $bodyEnd = $row - 1;

        // 합계 = 소계들의 합
        $totalRow = $row;
        $sheet->mergeCells("B{$row}:D{$row}");
        $sheet->setCellValueExplicit("B{$row}", '합계', DataType::TYPE_STRING);
        foreach ($cols as [, $key]) {
            if (in_array($key, ['__type', '__no', 'name'], true)) {
                continue;
            }
            $c = $letter[$key];
            $sheet->setCellValue("{$c}{$row}", $subtotalRows === [] ? 0 : '='.implode('+', array_map(fn ($r) => $c.$r, $subtotalRows)));
        }
        // 표 서식 — 전체 격자·글꼴(10pt) 먼저, 그 위에 합계 행(11pt)·강조
        $this->grid($sheet, "B4:{$last}{$totalRow}");
        $this->totalRowStyle($sheet, "B{$row}:{$last}{$row}");
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal('center');
        $this->numberFormat($sheet, "{$firstNum}5:{$last}{$totalRow}", self::MONEY_FMT);
        $this->numberFormat($sheet, "{$firstNum}5:{$firstNum}{$totalRow}", self::COUNT_FMT);
        $sheet->getStyle("{$firstNum}5:{$last}{$totalRow}")->getAlignment()->setHorizontal('right');
        $sheet->getStyle("C5:C{$bodyEnd}")->getAlignment()->setHorizontal('center');
        $sheet->getStyle("D5:D{$bodyEnd}")->getAlignment()->setHorizontal('left');
        $pay = $letter['__net'] ?? $letter['payout'];   // 확정본은 「실제 송금」 열을 강조
        foreach ($subtotalRows as $k => $sr) {
            $from = $k === 0 ? 5 : $subtotalRows[$k - 1] + 1;
            if ($from <= $sr - 1) {
                $sheet->getStyle("{$pay}{$from}:{$pay}".($sr - 1))->getFont()->setBold(true);
                $this->fill($sheet, "{$pay}{$from}:{$pay}".($sr - 1), self::HILITE);
            }
        }
        $pay = $letter['payout'];

        // 요약 — 라벨 C:F 병합 / 값 G, 화면 「합계」 칸과 같은 식을 수식으로(jin 이 셀을 고치면 아래가 따라온다)
        $r = $totalRow + 2;
        $put = function (string $label, string|int $value, bool $bold = false) use ($sheet, &$r): int {
            $sheet->mergeCells("C{$r}:F{$r}");
            $sheet->setCellValueExplicit("C{$r}", $label, DataType::TYPE_STRING);
            $sheet->setCellValue("G{$r}", $value);
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal('left');
            $sheet->getStyle("G{$r}")->getAlignment()->setHorizontal('right');
            $sheet->getRowDimension($r)->setRowHeight(21);
            if ($bold) {
                $sheet->getStyle("C{$r}:G{$r}")->getFont()->setBold(true);
                $this->fill($sheet, "C{$r}:G{$r}", self::HILITE);
            }

            return $r++;
        };
        $summaryStart = $r;
        $transferRow = $put('송금 총액 (퇴직연금 몫 포함)', "={$pay}{$totalRow}+{$letter['pension']}{$totalRow}");
        if ($holds !== [] || $extras !== []) {   // ⏸ 보류를 빼고 ➕ 추가를 더한 실송금 — 화면 「실제 송금액」과 같은 식
            $net = "=G{$transferRow}";
            if ($holds !== []) {
                $holdRow = $put('⏸ 보류 합(송금 제외)', "={$letter['__hold']}{$totalRow}");
                $net .= "-G{$holdRow}";
            }
            if ($extras !== []) {
                $extraRow = $put('➕ 추가 합(송금 더함)', "={$letter['__extra']}{$totalRow}");
                $net .= "+G{$extraRow}";
            }
            $put('실송금(보류·추가 반영)', $net);
        }
        $contribRow = $put('회사 기여 합', "={$letter['company_contribution']}{$totalRow}");
        // 공통 인건비 = 급여만 유형 소계의 지급합계 − (있으면) 그 묶음의 앞 달 지급보류분(원래 달 인건비에 이미 들어갔다, BatchPayoutBreakdown::commonLabor)
        $laborTerms = array_map(fn ($sr) => $pay.$sr, $laborSubtotals);
        if ($hasHeld) {
            $laborTerms = [...$laborTerms, ...array_map(fn ($sr) => '-'.$letter['adj_held'].$sr, $laborSubtotals)];
        }
        $laborRow = $put('공통 인건비 (검차·업무관리·관리·재무)', $laborTerms === [] ? 0 : '='.ltrim(implode('+', $laborTerms), '+'));
        $domRow = $domesticSplit ? $put('내수직원 총 회사수익', (int) ($totals['domestic_profit'] ?? 0)) : null;
        $netRow = $put('회사 순이익', "=G{$contribRow}-G{$laborRow}".($domRow ? "+G{$domRow}" : ''), $extraPaidOutside === 0);
        if ($extraPaidOutside !== 0) {   // (가) 월정산 밖으로 준 추가금은 그 달 회사이익에서 뺀다
            $extraPaidRow = $put('추가 지급(월정산 밖, 회사이익 차감)', $extraPaidOutside);
            $put('회사 순이익(월정산 밖 추가 지급 반영)', "=G{$netRow}-G{$extraPaidRow}", true);
        }
        $this->grid($sheet, "C{$summaryStart}:G".($r - 1));
        $this->numberFormat($sheet, "G{$summaryStart}:G".($r - 1), self::MONEY_FMT);
        if ($domesticSplit) {
            $sheet->setCellValueExplicit("C{$r}", '※ 내수직원 총 회사수익은 월 정산표 입력값, 나머지는 위 표에서 계산', DataType::TYPE_STRING);
            $sheet->getStyle("C{$r}")->getFont()->setSize(9)->getColor()->setRGB('7F7F7F');
        }
        $this->chrome($sheet, "A1:{$last}{$r}", false);
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

    /** jin 수정본의 급여 항목 열 순서 — 이 순서 우선, 그 뒤 PayrollEntry::ITEMS 순, 그 뒤 직접 입력 항목(첫 등장 순). 그 달에 값이 있는 항목만. */
    private const PAYROLL_COLUMN_ORDER = ['기본급', '식대', '복리', '자가운전', '연차수당', '연장근로', '경력수당', '직책수당', '기타수당', '헤이딜러', 'ERP이용수당'];

    /**
     * 급여 항목 — 한 사람 = 한 줄(jin 2026-11-06 수정본, 구 카드형 폐기). 구분·No.·이름 · 항목 열(값 있는 것만) · 합계(=SUM) · 비고(급여 미입력).
     * 프리랜서는 급여가 없어 빠진다. 판매수당은 정산금과 같은 돈이라 「사람별 지급」 급여 열에서 빼는 것과 맞춰 표에 싣지 않는다(합계 = 급여 열).
     */
    private function payrollItemsSheet(Worksheet $sheet, array $people, string $title, string $month): void
    {
        $sheet->setTitle('급여 항목');
        $people = array_values(array_filter($people, fn ($p) => ($p['type'] ?? '') !== 'freelance'));

        $present = [];
        foreach ($people as $p) {
            foreach ($p['payroll_items'] ?? [] as $it) {
                $label = (string) ($it['label'] ?? '');
                if ($label !== '' && $label !== PayrollEntry::SALES_COMMISSION && ! in_array($label, $present, true)) {
                    $present[] = $label;
                }
            }
        }
        $items = [];
        foreach ([...self::PAYROLL_COLUMN_ORDER, ...PayrollEntry::ITEMS] as $label) {
            if (in_array($label, $present, true) && ! in_array($label, $items, true)) {
                $items[] = $label;
            }
        }
        foreach ($present as $label) {
            if (! in_array($label, $items, true)) {
                $items[] = $label;
            }
        }

        $labels = ['구분', 'No.', '이름', ...$items, '합계', '비고'];
        $n = count($labels);
        $col = fn (int $i) => Coordinate::stringFromColumnIndex($i + 2);   // 0 → B
        $last = $col($n - 1);
        $sumCol = $col($n - 2);
        $noteCol = $last;
        $firstItem = $items === [] ? null : $col(3);
        $lastItem = $items === [] ? null : $col(2 + count($items));
        $sheet->getColumnDimension('A')->setWidth(2);
        foreach (['B' => 11, 'C' => 5, 'D' => 14] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        if ($firstItem) {
            $sheet->getColumnDimension($firstItem)->setWidth(11);
        }
        $sheet->getColumnDimension($sumCol)->setWidth(13);
        $sheet->getColumnDimension($noteCol)->setWidth(14);

        $this->titleRows($sheet, $title, $month);
        $this->tableHeader($sheet, $labels, 4, 26.1);

        $row = 5;
        $subtotalRows = [];
        $noteRows = [];
        foreach ($this->groupByType($people) as $label => $group) {
            $first = $row;
            foreach ($group as $i => $p) {
                $sheet->setCellValue("C{$row}", $i + 1);
                $sheet->setCellValueExplicit("D{$row}", (string) ($p['name'] ?? ''), DataType::TYPE_STRING);
                $byLabel = [];
                foreach ($p['payroll_items'] ?? [] as $it) {
                    $byLabel[(string) ($it['label'] ?? '')] = ($byLabel[(string) ($it['label'] ?? '')] ?? 0) + (int) ($it['amount'] ?? 0);
                }
                foreach ($items as $k => $item) {
                    if (array_key_exists($item, $byLabel)) {
                        $sheet->setCellValue($col(3 + $k).$row, $byLabel[$item]);
                    }
                }
                $sheet->setCellValue("{$sumCol}{$row}", $firstItem ? "=SUM({$firstItem}{$row}:{$lastItem}{$row})" : 0);
                if (($p['payroll_items'] ?? []) === []) {
                    $sheet->setCellValueExplicit("{$noteCol}{$row}", '급여 미입력', DataType::TYPE_STRING);
                    $noteRows[] = $row;
                }
                $sheet->getRowDimension($row)->setRowHeight(18.9);
                $row++;
            }
            $sheet->mergeCells("C{$row}:D{$row}");
            $sheet->setCellValueExplicit("C{$row}", $label.' 소계', DataType::TYPE_STRING);
            for ($k = 3; $k <= $n - 2; $k++) {
                $c = $col($k);
                $sheet->setCellValue("{$c}{$row}", "=SUM({$c}{$first}:{$c}".($row - 1).')');
            }
            $fill = $this->typeFill($label);
            $this->fill($sheet, "B{$row}:{$last}{$row}", $fill);
            $sheet->getStyle("B{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal('center');
            $sheet->getRowDimension($row)->setRowHeight(21);
            $sheet->mergeCells("B{$first}:B{$row}");
            $sheet->setCellValueExplicit("B{$first}", $label, DataType::TYPE_STRING);
            $this->fill($sheet, "B{$first}:B{$row}", $fill);
            $sheet->getStyle("B{$first}")->getFont()->setBold(true);
            $sheet->getStyle("B{$first}")->getAlignment()->setHorizontal('center')->setVertical('center');
            $subtotalRows[] = $row;
            $row++;
        }
        $bodyEnd = $row - 1;

        $totalRow = $row;
        $sheet->mergeCells("B{$row}:D{$row}");
        $sheet->setCellValueExplicit("B{$row}", '합계', DataType::TYPE_STRING);
        for ($k = 3; $k <= $n - 2; $k++) {
            $c = $col($k);
            $sheet->setCellValue("{$c}{$row}", $subtotalRows === [] ? 0 : '='.implode('+', array_map(fn ($r) => $c.$r, $subtotalRows)));
        }
        $this->grid($sheet, "B4:{$last}{$totalRow}");
        $this->totalRowStyle($sheet, "B{$row}:{$last}{$row}");
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal('center');
        $numStart = $firstItem ?? $sumCol;
        $this->numberFormat($sheet, "{$numStart}5:{$sumCol}{$totalRow}", self::MONEY_FMT);
        $sheet->getStyle("{$numStart}5:{$sumCol}{$totalRow}")->getAlignment()->setHorizontal('right');
        $sheet->getStyle("C5:C{$bodyEnd}")->getAlignment()->setHorizontal('center');
        $sheet->getStyle("D5:D{$bodyEnd}")->getAlignment()->setHorizontal('left');
        $sheet->getStyle("{$noteCol}5:{$noteCol}{$bodyEnd}")->getAlignment()->setHorizontal('left');
        foreach ($noteRows as $nr) {
            $sheet->getStyle("{$noteCol}{$nr}")->getFont()->setSize(9)->getColor()->setRGB('7F7F7F');
        }
        foreach ($subtotalRows as $k => $sr) {
            $from = $k === 0 ? 5 : $subtotalRows[$k - 1] + 1;
            if ($from <= $sr - 1) {
                $sheet->getStyle("{$sumCol}{$from}:{$sumCol}".($sr - 1))->getFont()->setBold(true);
                $this->fill($sheet, "{$sumCol}{$from}:{$sumCol}".($sr - 1), self::HILITE);
            }
        }
        $this->chrome($sheet, "B1:{$last}{$totalRow}", true);
    }

    // ───────── jin 수정본 서식 (사람별 지급 · 급여 항목 공용) ─────────

    private const FONT = '맑은 고딕';

    private const MONEY_FMT = '#,##0;[Red]\-#,##0;"-"';

    private const COUNT_FMT = '#,##0;\-#,##0;"-"';

    private const HILITE = 'FFF9E5';

    /** 유형 → 구분 셀·소계 행 배경. jin 파일에 없는 유형은 회색. */
    private const TYPE_FILL = ['사내직원' => 'F2DCDB', '프리랜서' => 'DAEEF3', '검차직원' => 'C5D9F1', '업무관리자' => 'EBF1DE', '관리' => 'FFF2CC', '재무' => 'E4DFEC'];

    private function typeFill(string $label): string
    {
        return self::TYPE_FILL[$label] ?? 'F3F4F6';
    }

    private function titleRows(Worksheet $sheet, string $title, string $month): void
    {
        $sheet->setCellValueExplicit('B1', $title, DataType::TYPE_STRING);
        $sheet->getStyle('B1')->getFont()->setName(self::FONT)->setBold(true)->setSize(16);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->setCellValueExplicit('B2', "귀속월 {$month} · 단위: 원", DataType::TYPE_STRING);
        $sheet->getStyle('B2')->getFont()->setName(self::FONT)->setSize(9)->getColor()->setRGB('7F7F7F');
    }

    /** 머리줄 — B열부터, 305496 배경·흰 굵은 글자·가운데. */
    private function tableHeader(Worksheet $sheet, array $labels, int $row, float $height): void
    {
        foreach ($labels as $i => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 2).$row, $label, DataType::TYPE_STRING);
        }
        $last = Coordinate::stringFromColumnIndex(count($labels) + 1);
        $style = $sheet->getStyle("B{$row}:{$last}{$row}");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $this->fill($sheet, "B{$row}:{$last}{$row}", '305496');
        $style->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getRowDimension($row)->setRowHeight($height);
    }

    /** 표 전체 — 4면 thin BFBFBF, 맑은 고딕 10, 세로 중앙(글자 크기·굵기는 이미 준 것을 덮지 않는다). */
    private function grid(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('BFBFBF');
        $style->getFont()->setName(self::FONT)->setSize(10);
        $style->getAlignment()->setVertical('center');
    }

    /** 합계 행 — 11pt 굵게 · EDEDED · 위아래 medium 404040 · 높이 26.1. */
    private function totalRowStyle(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->setSize(11);
        $this->fill($sheet, $range, 'EDEDED');
        $style->getBorders()->getTop()->setBorderStyle('medium')->getColor()->setRGB('404040');
        $style->getBorders()->getBottom()->setBorderStyle('medium')->getColor()->setRGB('404040');
        $row = (int) preg_replace('/\D/', '', explode(':', $range)[0]);
        $sheet->getRowDimension($row)->setRowHeight(26.1);
    }

    private function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB($rgb);
    }

    private function numberFormat(Worksheet $sheet, string $range, string $format): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
    }

    /** 시트 설정 — 눈금선 끔 · 탭색 · 틀고정 E5 · 인쇄(머리줄 반복, 폭 맞춤). */
    private function chrome(Worksheet $sheet, string $printArea, bool $landscape): void
    {
        $sheet->setShowGridlines(false);
        $sheet->getTabColor()->setRGB('F4B084');
        $sheet->freezePane('E5');
        $setup = $sheet->getPageSetup();
        $setup->setOrientation($landscape ? 'landscape' : 'portrait');
        $setup->setPaperSize(9);   // A4
        $setup->setFitToWidth(1)->setFitToHeight(0);
        $setup->setPrintArea($printArea);
        $setup->setRowsToRepeatAtTopByStartAndEnd(4, 4);
        $margins = $sheet->getPageMargins();
        $margins->setLeft(0.4)->setRight(0.4)->setTop(1.0)->setBottom(1.0);
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
