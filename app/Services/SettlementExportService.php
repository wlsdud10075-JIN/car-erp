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
            // 🔢 No. — 시트마다 1부터, 가운데 정렬 (jin 2026-09-18).
            //    🔑 가짜 컬럼이지만 **이 목록에 넣는 것이 핵심**이다 — 합계 행의 SUM 열 위치가
            //       `array_search` 로 잡히므로, 밖에서 한 칸 밀면 언젠가 합계가 엉뚱한 열에 박힌다.
            '_no' => ['No.', 'no', null],
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
            'exchange_rate' => ['환율', 'num4', fn (Settlement $s) => $s->vehicle?->exchange_rate],
            'purchase_price' => ['구입금액', 'num', fn (Settlement $s) => $s->vehicle?->purchase_price],
            'sale_price' => ['판매금액', 'num', fn (Settlement $s) => $s->vehicle?->sale_price],
            'cost_total' => ['비용합계', 'num', fn (Settlement $s) => $s->vehicle?->cost_total],
            // 마진 (accessor)
            'sales_margin' => ['판매마진', 'num', fn (Settlement $s) => $s->sales_margin],
            'vat_margin' => ['부가세마진', 'num', fn (Settlement $s) => $s->vat_margin],
            'total_margin' => ['총마진', 'num', fn (Settlement $s) => $s->total_margin],
            // 📊 마진율 — 비율로 쓰고 셀 서식으로 % 를 붙인다(엑셀에서 다시 계산 가능).
            //    내수·분모 0 은 null → 빈칸. 🚨 합계는 SUM 이 아니다(아래 SUM_COLUMNS 에 안 넣는다).
            'margin_rate' => ['마진율', 'rate', fn (Settlement $s) => $s->margin_rate],
            // 정산
            'settlement_type' => ['정산방식', 'str', fn (Settlement $s) => $s->settlement_type === 'ratio' ? '프리랜서(비율)' : '사내직원(건당)'],
            'settlement_ratio' => ['정산비율(%)', 'num', fn (Settlement $s) => $s->settlement_type === 'ratio' ? $s->settlement_ratio : null],
            'per_unit_amount' => ['건당금액', 'num', fn (Settlement $s) => $s->settlement_type === 'per_unit' ? $s->per_unit_amount : null],
            'settlement_amount' => ['정산액', 'num', fn (Settlement $s) => $s->settlement_amount],
            'document_fee' => ['서류비', 'num', fn (Settlement $s) => $s->document_fee],
            'other_deduction' => ['기타공제', 'num', fn (Settlement $s) => $s->other_deduction],
            // 💱 환차 — **1차 정산부터 채워진다**(jin 2026-09-18). 마감되면 저장값, 전이면 미리보기.
            //    🔑 화면과 **같은 단일 출처**를 부른다 — 식을 여기 옮겨 적으면
            //       「화면 3,000원 ↔ 엑셀 2,900원」이 된다(§8 #44·#45).
            'exchange_difference_krw' => ['환차', 'num', fn (Settlement $s) => $s->display_exchange_difference],
            'carryover_in_krw' => ['이월(받음)', 'num', fn (Settlement $s) => $s->carryover_in_krw],
            // ⚠️ pending 은 확정 전 미리보기 + 배치 조정 미반영 → 라벨에 (예정) 고정.
            'actual_payout' => ['실지급액(예정)', 'num', fn (Settlement $s) => $s->actual_payout],
        ];
    }

    /** 하단 합계 행에 금액을 더할 컬럼 key. 🚨 마진율은 여기 넣으면 안 된다(비율의 합은 무의미). */
    private const SUM_COLUMNS = ['total_margin', 'settlement_amount', 'actual_payout'];

    /** 마진율 셀 서식 — 화면 `Settlement::formatMarginRate` 와 같은 소수 1자리. */
    private const RATE_FORMAT = '0.0%';

    /**
     * 💰 금액 셀 — 천 단위 쉼표 (jin 2026-09-20 「그냥 숫자만 나온곳에 쉼표스타일」).
     *
     * 🚨 **`#,##0` 이 아니다.** 이 열들에는 외화 소수가 실제로 들어온다
     *    (판매금액 `decimal(15,2)`·환차·송금수수료). `#,##0` 으로 굳히면 10,434.54 가
     *    **「10,435」로 보여 없는 금액**이 된다 — jin 이 이미 겪은 그 사고다(§8 #91-D:
     *    「`number_format` 은 버리는 게 아니라 반올림한다」). `.##` 은 소수가 **있을 때만** 보여준다.
     */
    private const NUM_FORMAT = '#,##0.##';

    /** 환율 전용 — `decimal(15,4)` 라 4자리까지 살린다(JPY 8.6409 를 8.64 로 깎지 않는다). */
    private const NUM4_FORMAT = '#,##0.####';

    /**
     * 숫자 타입 → 셀 서식. 🚫 `str`·`date`·`no` 는 없다(쉼표를 붙일 값이 아니다 —
     * 특히 `no` 는 순번이라 네 자리가 넘어도 「1,024번」이 되면 안 된다).
     */
    private static function numberFormatFor(string $type): ?string
    {
        return match ($type) {
            'num' => self::NUM_FORMAT,
            'num4' => self::NUM4_FORMAT,
            'rate' => self::RATE_FORMAT,
            default => null,
        };
    }

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
        // 🔢 시트마다 1부터 — 요약 시트도 같다(jin 2026-09-18 「응 시트마다 그래야지」).
        $head = ['No.', '영업담당자', '대수', '총마진', '마진율', '정산액', '실지급액(예정)'];
        foreach ($head as $i => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).'1', $label, DataType::TYPE_STRING);
        }
        $this->styleHeader($sheet, count($head));

        $row = 2;
        $no = 0;
        foreach ($groups as $name => $rows) {
            $sheet->setCellValue("A{$row}", ++$no);
            $sheet->setCellValueExplicit("B{$row}", (string) $name, DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $rows->count());
            $sheet->setCellValue("D{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->total_margin));
            $rate = Settlement::marginRateOf($rows);
            if ($rate !== null) {
                $sheet->setCellValue("E{$row}", $rate);
            }
            $sheet->setCellValue("F{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->settlement_amount));
            $sheet->setCellValue("G{$row}", (int) $rows->sum(fn (Settlement $s) => (int) $s->actual_payout));
            $row++;
        }

        // 전체 합계
        if ($row > 2) {
            $sheet->setCellValueExplicit("B{$row}", '합계', DataType::TYPE_STRING);
            foreach (['C', 'D', 'F', 'G'] as $col) {
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}".($row - 1).')');
            }
            // 🚨 마진율만 SUM 이 아니다 — 전체 정산을 합친 가중 비율이다.
            $all = $groups->flatten(1);
            $rate = Settlement::marginRateOf($all);
            if ($rate !== null) {
                $sheet->setCellValue("E{$row}", $rate);
            }
            $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
        }

        // 💰 대수·총마진·정산액·실지급액은 쉼표, 마진율만 % (합계 행까지 같은 범위).
        $lastRow = max(2, $row);
        foreach (['C', 'D', 'F', 'G'] as $col) {
            $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode(self::NUM_FORMAT);
        }
        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode(self::RATE_FORMAT);

        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:A'.max(2, $row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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
        $no = 0;
        foreach ($rows as $s) {
            $i = 1;
            $no++;
            foreach ($cols as $def) {
                $cell = Coordinate::stringFromColumnIndex($i).$row;
                if ($def[1] === 'no') {
                    $sheet->setCellValue($cell, $no);
                    $i++;

                    continue;
                }
                $value = ($def[2])($s);
                if ($value !== null && $value !== '') {
                    if ($def[1] === 'rate') {
                        $sheet->setCellValue($cell, (float) $value);
                    } elseif ($def[1] === 'num' || $def[1] === 'num4') {
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
            // 🔢 A 열은 No. 다 — 합계 라벨은 그 다음 칸에 넣는다(번호 자리에 글자가 들어가면 엑셀 정렬이 깨진다).
            $sheet->setCellValueExplicit("B{$row}", '합계 '.$rows->count().'대', DataType::TYPE_STRING);
            $keys = array_keys($cols);
            foreach (self::SUM_COLUMNS as $key) {
                $idx = array_search($key, $keys, true);
                if ($idx === false) {
                    continue;
                }
                $col = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}".($row - 1).')');
            }
            // 🚨 마진율 합계는 **평균도 SUM 도 아니다** — Σ총마진 ÷ Σ판매금원화(금액 가중).
            //    엑셀 합계행이 `CH합/CC합` 인 것이 근거다. 화면과 같은 메서드를 부른다.
            $rateIdx = array_search('margin_rate', $keys, true);
            if ($rateIdx !== false) {
                $rate = Settlement::marginRateOf($rows);
                $col = Coordinate::stringFromColumnIndex($rateIdx + 1);
                if ($rate !== null) {
                    $sheet->setCellValue("{$col}{$row}", $rate);
                }
            }
            $last = Coordinate::stringFromColumnIndex(count($cols));
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        }

        // 💰 숫자 서식은 **열 단위로 한 번씩** 건다 — 합계 행까지 같은 범위에 들어간다.
        //    🚫 셀마다 걸지 말 것: ssancarerp 4,536건 × 숫자 15열 = 6만 번이라 내려받기가 눈에 띄게 느려진다.
        $lastRow = max(2, $row);
        $i = 1;
        foreach ($cols as $def) {
            $fmt = self::numberFormatFor($def[1]);
            if ($fmt !== null) {
                $col = Coordinate::stringFromColumnIndex($i);
                $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode($fmt);
            }
            $i++;
        }

        $sheet->getStyle('A1:A'.max(2, $row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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
