<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 급여대장 xls 읽기 (jin 2026-10-14 «엑셀 업로드를 통해 월급명세서를 올리면 해당인원에 맞게 기입»).
 *
 * 양식(회계 프로그램 출력본): 제목 「2026년 09월분 급여대장」 · 헤더 6·7·8행 · 사람마다 **3줄**
 *   1줄 = 사원번호·성명 + 6행 라벨의 지급 항목(기본급·상여·식대·…) + 공제(J~O, 버린다 — jin #1)
 *   2줄 = 입사일·직급 + 7행 라벨의 지급 항목(전월소급·연차수당·판매수당·…)
 *   3줄 = 퇴사일·부서 + I열 「지급합계」
 * 같은 열이 줄마다 **다른 항목**이다(E = 식대 / 판매수당). 라벨은 헤더에서 열 글자로 읽는다 — 열이 밀린 양식도 라벨이 잡는다.
 *
 * 🔒 사람마다 Σ지급항목 == 지급합계(I) 를 검산한다. 틀리면 그 줄은 기입 차단(미리보기 빨강).
 * 공제 항목·차인지급액은 읽지 않는다. 지급 라벨이 PayrollEntry::ITEMS 밖이면 직접 추가 행(is_custom).
 */
class PayrollStatementImporter
{
    /** 지급 항목 헤더가 있는 열 범위 — 공제(J~)는 제외. 라벨로 다시 거르므로 넉넉히. */
    private const PAY_COLUMNS = ['C', 'D', 'E', 'F', 'G', 'H', 'I'];

    /**
     * @return array{month:?string, people:list<array{row:int, employee_no:string, name:string, position:string, dept:string, items:array<string,int>, total:int, sheet_total:?int, ok:bool, error:?string}>, errors:list<string>, header_labels:array}
     */
    public function parse(string $path): array
    {
        $ws = IOFactory::load($path)->getSheet(0);
        $errors = [];

        $month = $this->month($ws);
        if ($month === null) {
            $errors[] = '귀속월을 제목에서 찾지 못했습니다 (예: 「2026년 09월분 급여대장」).';
        }

        [$headerRow, $labels1, $labels2] = $this->headerLabels($ws);
        if ($headerRow === null) {
            $errors[] = '헤더(「사원번호」 줄)를 찾지 못했습니다 — 급여대장 양식이 아닙니다.';

            return ['month' => $month, 'people' => [], 'errors' => $errors, 'header_labels' => []];
        }

        $people = [];
        $maxRow = $ws->getHighestRow();
        $r = $headerRow + 3;
        while ($r <= $maxRow) {
            $a = trim((string) $ws->getCell("A{$r}")->getFormattedValue());
            $name = trim((string) $ws->getCell("B{$r}")->getFormattedValue());
            if (str_starts_with($a, '합계')) {
                break;
            }
            if ($a === '' || ! preg_match('/^\d+$/', $a) || $name === '') {
                $r++;

                continue;
            }
            $items = [];
            foreach ($labels1 as $col => $label) {
                $this->addItem($items, $label, $this->num($ws, $col, $r));
            }
            foreach ($labels2 as $col => $label) {
                $this->addItem($items, $label, $this->num($ws, $col, $r + 1));
            }
            $total = (int) array_sum($items);
            $sheetTotal = $this->num($ws, 'I', $r + 2);
            $ok = $sheetTotal !== null && $sheetTotal === $total;
            $people[] = [
                'row' => $r,
                'employee_no' => $a,
                'name' => $name,
                'position' => trim((string) $ws->getCell('B'.($r + 1))->getFormattedValue()),
                'dept' => trim((string) $ws->getCell('B'.($r + 2))->getFormattedValue()),
                'items' => $items,
                'total' => $total,
                'sheet_total' => $sheetTotal,
                'ok' => $ok,
                'error' => $ok ? null : sprintf('검산 불일치 — 항목 합 %s ≠ 지급합계 %s', number_format($total), $sheetTotal === null ? '없음' : number_format($sheetTotal)),
            ];
            $r += 3;
        }
        if ($people === []) {
            $errors[] = '읽은 사람이 없습니다.';
        }

        return ['month' => $month, 'people' => $people, 'errors' => $errors, 'header_labels' => ['row1' => $labels1, 'row2' => $labels2]];
    }

    /**
     * 명부 매칭 — 사원번호(employee_no) → 이름 완전일치(활성, 전 유형) → 없음(null).
     *
     * @param  list<array>  $people  parse() 의 people
     * @return array<int, ?int> row → salesman_id
     */
    public function match(array $people): array
    {
        $byNo = Salesman::query()->whereNotNull('employee_no')->pluck('id', 'employee_no')->all();
        $byName = Salesman::query()->where('is_active', true)->get(['id', 'name'])->groupBy('name');
        $out = [];
        foreach ($people as $p) {
            $id = $byNo[$p['employee_no']] ?? null;
            if ($id === null) {
                $same = $byName->get($p['name']);
                $id = ($same && $same->count() === 1) ? (int) $same->first()->id : null;   // 동명이인이면 사람이 고른다
            }
            $out[$p['row']] = $id === null ? null : (int) $id;
        }

        return $out;
    }

    /** 귀속월 — 제목 「2026년 09월분」, 없으면 「[귀속:2026년 09월]」. */
    private function month(Worksheet $ws): ?string
    {
        foreach (['H1', 'A1', 'H3', 'A3'] as $coord) {
            $v = (string) $ws->getCell($coord)->getFormattedValue();
            if (preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월/u', $v, $m)) {
                return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
            }
        }
        foreach ($ws->getRowIterator(1, 4) as $row) {
            foreach ($row->getCellIterator() as $c) {
                if (preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월/u', (string) $c->getFormattedValue(), $m)) {
                    return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
                }
            }
        }

        return null;
    }

    /** @return array{0:?int, 1:array<string,string>, 2:array<string,string>} 헤더 행 번호 · 1줄째 라벨(열→라벨) · 2줄째 라벨 */
    private function headerLabels(Worksheet $ws): array
    {
        $max = min($ws->getHighestRow(), 15);
        for ($r = 1; $r <= $max; $r++) {
            if (trim((string) $ws->getCell("A{$r}")->getFormattedValue()) === '사원번호') {
                $l1 = [];
                $l2 = [];
                foreach (self::PAY_COLUMNS as $col) {
                    $a = $this->cleanLabel((string) $ws->getCell("{$col}{$r}")->getFormattedValue());
                    $b = $this->cleanLabel((string) $ws->getCell("{$col}".($r + 1))->getFormattedValue());
                    if ($a !== '' && $a !== '지급합계') {
                        $l1[$col] = $a;
                    }
                    if ($b !== '' && $b !== '지급합계') {
                        $l2[$col] = $b;
                    }
                }

                return [$r, $l1, $l2];
            }
        }

        return [null, [], []];
    }

    private function cleanLabel(string $raw): string
    {
        return preg_replace('/\s+/u', '', trim($raw)) ?? '';
    }

    private function addItem(array &$items, string $label, ?int $amount): void
    {
        if ($amount === null) {
            return;
        }
        $items[$label] = ($items[$label] ?? 0) + $amount;
    }

    /** 셀 → 정수(원). 빈칸·0 은 null(미입력) — 「없음」을 적은 0 과 구분할 필요가 없다(합계에 영향 없음). 문자열이면 콤마 제거. */
    private function num(Worksheet $ws, string $col, int $row): ?int
    {
        $v = $ws->getCell("{$col}{$row}")->getCalculatedValue();
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
            if (! is_numeric($v)) {
                return null;
            }
        }
        $n = (int) round((float) $v);

        return $n === 0 ? null : $n;
    }

    /** 미리보기 행 → PayrollEntry::replaceFor() 행 */
    public static function rowsFor(array $person): array
    {
        $rows = [];
        foreach ($person['items'] as $label => $amount) {
            $rows[] = ['label' => $label, 'amount' => (int) $amount, 'is_custom' => ! in_array($label, PayrollEntry::ITEMS, true)];
        }

        return $rows;
    }
}
