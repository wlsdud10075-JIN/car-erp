<?php

namespace App\Services;

use App\Models\Settlement;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 정산 데이터 export(xlsx) — 귀속월 기준 + 영업담당자별 시트 (jin 2026-08-03).
 *
 * 왜 차량 export 로 안 되나: 차량목록 export 는 행이 **차량**이고 날짜축이 매입일·판매일·선적일뿐이라
 * "7월 귀속 정산분"을 뽑을 수 없다(정산 축 자체가 없음). 정산은 행이 **정산**이고 귀속월·배치·조정이
 * 붙는 별개 축이라 화면(정산관리)의 필터를 그대로 미러하는 전용 export 가 맞다.
 *
 * 시트 구성 (jin: "인원별로 탭을 나눠서 하나의 엑셀에 각자 정보"):
 *   [요약] 담당자별 대수·총마진·정산액·실지급액 — 화면 「영업담당자별 합계」 카드와 같은 단위
 *   [담당자명] × N  각 담당자의 정산 명세 + 하단 합계 행
 *
 * 안전 설계(VehicleExportService 와 동일 보증):
 *  - 고정 컬럼(선택 UI 없음) — 회계 민감값이라 화이트리스트 자체가 전량 고정.
 *  - 차량번호·차대번호는 **항상 맨 앞**(jin: "필히 들어가야 해"). 정산만 있고 식별자가 없으면 대조 불가.
 *  - 마진·정산액·실지급액은 전부 accessor 경유(§5/§13 단일출처, raw SQL 금지).
 *  - formula injection: 문자열 셀은 setCellValueExplicit(TYPE_STRING) → '=' 시작값도 수식 실행 안 됨.
 *  - PII 없음 — 소유자·RRN·주소는 컬럼에 아예 포함하지 않는다(정산 대조에 불필요).
 *
 * ⚠️ 실지급액은 **행 단위 현재 계산값**이다. pending 이면 확정 전 미리보기이고,
 *    월배치 조정(예: 2026-06 −729,250)은 **배치 단위**라 행에 표현되지 않는다 → 라벨에 (예정) 명시.
 */
class SettlementExportService
{
    /** 엑셀 시트명 제한 — 31자, 금지문자 \ / ? * [ ] : */
    private const SHEET_NAME_MAX = 31;

    /**
     * 고정 컬럼. [label, type(str|num|date), fn(Settlement)].
     *
     * @return array<string, array{0:string,1:string,2:callable}>
     */
    private function columns(): array
    {
        return [
            // 식별 — 항상 맨 앞. 차량번호는 재발급으로 바뀌므로 차대번호(VIN)까지 함께.
            'vehicle_number' => ['차량번호', 'str', fn (Settlement $s) => $s->vehicle?->vehicle_number],
            'chassis_number' => ['차대번호', 'str', fn (Settlement $s) => $s->vehicle?->nice_reg_vin],
            // 귀속·상태
            'attributed_month' => ['귀속월', 'str', fn (Settlement $s) => $s->attributed_month?->format('Y-m')],
            'settlement_status' => ['정산상태', 'str', fn (Settlement $s) => $s->settlement_status],
            'payout_batch' => ['월배치', 'str', fn (Settlement $s) => $s->payout_batch_id ? '#'.$s->payout_batch_id : ''],
            'confirmed_at' => ['확정일', 'date', fn (Settlement $s) => $s->confirmed_at],
            'paid_at' => ['지급일', 'date', fn (Settlement $s) => $s->paid_at],
            // 차량 회계 근거 — 마진이 왜 그 값인지 대조용
            'currency' => ['통화', 'str', fn (Settlement $s) => $s->vehicle?->currency],
            'exchange_rate' => ['환율', 'num', fn (Settlement $s) => $s->vehicle?->exchange_rate],
            'purchase_price' => ['구입금액', 'num', fn (Settlement $s) => $s->vehicle?->purchase_price],
            'sale_price' => ['판매금액', 'num', fn (Settlement $s) => $s->vehicle?->sale_price],
            'cost_total' => ['비용합계', 'num', fn (Settlement $s) => $s->vehicle?->cost_total],
            // 마진 (accessor)
            'sales_margin' => ['판매마진', 'num', fn (Settlement $s) => $s->sales_margin],
            'vat_margin' => ['부가세마진', 'num', fn (Settlement $s) => $s->vat_margin],
            'total_margin' => ['총마진', 'num', fn (Settlement $s) => $s->total_margin],
            // 정산
            'settlement_type' => ['정산방식', 'str', fn (Settlement $s) => $s->settlement_type === 'ratio' ? '프리랜서(비율)' : '사내직원(건당)'],
            'settlement_ratio' => ['정산비율(%)', 'num', fn (Settlement $s) => $s->settlement_type === 'ratio' ? $s->settlement_ratio : null],
            'per_unit_amount' => ['건당금액', 'num', fn (Settlement $s) => $s->settlement_type === 'per_unit' ? $s->per_unit_amount : null],
            'settlement_amount' => ['정산액', 'num', fn (Settlement $s) => $s->settlement_amount],
            'document_fee' => ['서류비', 'num', fn (Settlement $s) => $s->document_fee],
            'other_deduction' => ['기타공제', 'num', fn (Settlement $s) => $s->other_deduction],
            'exchange_difference_krw' => ['환차', 'num', fn (Settlement $s) => $s->exchange_difference_krw],
            'carryover_in_krw' => ['이월(받음)', 'num', fn (Settlement $s) => $s->carryover_in_krw],
            // ⚠️ pending 은 확정 전 미리보기 + 배치 조정 미반영 → 라벨에 (예정) 고정.
            'actual_payout' => ['실지급액(예정)', 'num', fn (Settlement $s) => $s->actual_payout],
        ];
    }

    /** 하단 합계 행에 금액을 더할 컬럼 key. */
    private const SUM_COLUMNS = ['total_margin', 'settlement_amount', 'actual_payout'];

    /** @return list<string> */
    public function columnLabels(): array
    {
        return array_map(fn ($def) => $def[0], array_values($this->columns()));
    }

    /**
     * 담당자별 시트로 나눈 워크북. 첫 시트는 담당자별 요약.
     *
     * @param  Collection<int,Settlement>  $settlements
     */
    public function build(Collection $settlements): Spreadsheet
    {
        // accessor(sales_margin·actual_payout …)가 $this->vehicle 을 참조 → 관계 주입으로 N+1 방지.
        foreach ($settlements as $s) {
            if ($s->relationLoaded('vehicle') && $s->vehicle) {
                $s->vehicle->setRelation('settlements', collect([$s]));
            }
        }

        // 담당자별 그룹 — 미지정은 마지막에 별도 시트.
        $groups = $settlements
            ->groupBy(fn (Settlement $s) => $s->salesman?->name ?: '미지정')
            ->sortKeys();

        $ss = new Spreadsheet;
        $this->buildSummarySheet($ss->getActiveSheet(), $groups);

        $used = ['요약'];
        foreach ($groups as $name => $rows) {
            $sheet = $ss->createSheet();
            $sheet->setTitle($this->safeSheetName((string) $name, $used));
            $this->buildDetailSheet($sheet, $rows);
        }

        $ss->setActiveSheetIndex(0);

        return $ss;
    }

    /** 요약 시트 — 화면 「영업담당자별 합계」 카드와 같은 단위(대수·총마진·정산액·실지급액). */
    private function buildSummarySheet(Worksheet $sheet, Collection $groups): void
    {
        $sheet->setTitle('요약');
        $head = ['영업담당자', '대수', '총마진', '정산액', '실지급액(예정)'];
        foreach ($head as $i => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).'1', $label, DataType::TYPE_STRING);
        }
        $this->styleHeader($sheet, count($head));

        $row = 2;
        foreach ($groups as $name => $rows) {
            $sheet->setCellValueExplicit("A{$row}", (string) $name, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $rows->count());
            $sheet->setCellValue("C{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->total_margin));
            $sheet->setCellValue("D{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->settlement_amount));
            $sheet->setCellValue("E{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->actual_payout));
            $row++;
        }

        // 전체 합계
        if ($row > 2) {
            $sheet->setCellValueExplicit("A{$row}", '합계', DataType::TYPE_STRING);
            foreach (['B', 'C', 'D', 'E'] as $col) {
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}".($row - 1).')');
            }
            $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
        }

        $sheet->getStyle('A1:E1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        for ($c = 1; $c <= count($head); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    /** 담당자 1명의 정산 명세 + 하단 합계 행. */
    private function buildDetailSheet(Worksheet $sheet, Collection $rows): void
    {
        $cols = $this->columns();

        $i = 1;
        foreach ($cols as $def) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i).'1', $def[0], DataType::TYPE_STRING);
            $i++;
        }
        $this->styleHeader($sheet, count($cols));

        $row = 2;
        foreach ($rows as $s) {
            $i = 1;
            foreach ($cols as $def) {
                $cell = Coordinate::stringFromColumnIndex($i).$row;
                $value = ($def[2])($s);
                if ($value !== null && $value !== '') {
                    if ($def[1] === 'num') {
                        $sheet->setCellValue($cell, $value);
                    } elseif ($def[1] === 'date') {
                        $sheet->setCellValueExplicit($cell, $value->format('Y-m-d'), DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                    }
                }
                $i++;
            }
            $row++;
        }

        // 합계 행 — 금액 컬럼만. SUM 범위는 실제 채운 구간이라 행 수와 무관하게 정확.
        if ($row > 2) {
            $sheet->setCellValueExplicit("A{$row}", '합계 '.$rows->count().'대', DataType::TYPE_STRING);
            $keys = array_keys($cols);
            foreach (self::SUM_COLUMNS as $key) {
                $idx = array_search($key, $keys, true);
                if ($idx === false) {
                    continue;
                }
                $col = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}".($row - 1).')');
            }
            $last = Coordinate::stringFromColumnIndex(count($cols));
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        }

        for ($c = 1; $c <= count($cols); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    private function styleHeader(Worksheet $sheet, int $colCount): void
    {
        $last = Coordinate::stringFromColumnIndex($colCount);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('ECE9F8');
    }

    /**
     * 엑셀 시트명 정규화 — 금지문자 제거·31자 컷·중복 회피(동명이인/컷 충돌).
     * 이름이 통째로 비면 '담당자'로 대체(빈 시트명은 PhpSpreadsheet 예외).
     *
     * @param  list<string>  $used  이미 쓴 이름(참조로 누적)
     */
    private function safeSheetName(string $name, array &$used): string
    {
        $clean = trim(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name) ?? '');
        if ($clean === '') {
            $clean = '담당자';
        }
        if (mb_strlen($clean) > self::SHEET_NAME_MAX) {
            $clean = mb_substr($clean, 0, self::SHEET_NAME_MAX);
        }

        $base = $clean;
        $n = 2;
        while (in_array($clean, $used, true)) {
            $suffix = '('.$n.')';
            $clean = mb_substr($base, 0, self::SHEET_NAME_MAX - mb_strlen($suffix)).$suffix;
            $n++;
        }
        $used[] = $clean;

        return $clean;
    }
}
