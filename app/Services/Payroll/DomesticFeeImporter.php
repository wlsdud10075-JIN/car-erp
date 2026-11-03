<?php

namespace App\Services\Payroll;

use App\Models\Salesman;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 내수직원 「평가수수료정산」 엑셀 읽기 (jin 2026-11-03 «내가 지정한 행의 정보만 나타날 수 있게 해서 월정산에»).
 *
 * 양식: 탭 「딜러수수료_2026년10월」 안의 요약표 —
 *   헤더 행 = 「평가사」 칸이 있는 줄(실측 G66) → 오른쪽으로 항목 이름(컨설팅비 … 총 회사수익, G66:P66)
 *   사람 행 = 그 아래 이름이 있는 줄들(G67:P73) · 끝 = 「합계」 줄(G74:P74)
 * 위치를 좌표로 박지 않고 「평가사」·「합계」 글자로 찾는다 — 달마다 위쪽 차량 목록 길이가 달라 행이 밀린다.
 *
 * 송금액 = 「총 평가사수수료」 열(jin 11-03). 나머지 항목은 카드에 표시만.
 * 🔒 사람 행들의 열 합 == 합계 행이 아니면 경고(파일을 고친 뒤 다시 올리게).
 * 🧠 값은 **엑셀이 저장해 둔 계산값**을 읽는다 — 수식을 다시 돌리면 다른 시트(평가사별 탭)까지 읽어야 하고,
 *    이 파일은 시트가 104만 행까지 서식이 칠해져 있다(2026-11-03 실측). 그래서 그 탭만·값만 읽는다(§8 명세서 메모리 건과 같은 원리).
 */
class DomesticFeeImporter
{
    public const SHEET_PATTERN = '/^딜러수수료_(\d{4})년\s*(\d{1,2})월/u';

    public const NAME_HEADER = '평가사';

    public const TOTAL_LABEL = '합계';

    public const PAYOUT_HEADER = '총 평가사수수료';

    /**
     * @return array{month:?string, headers:list<string>, people:list<array{row:int, name:string, values:list<int|float|null>, payout:int}>, total:list<int|float|null>, errors:list<string>, warnings:list<string>}
     */
    public function parse(string $path): array
    {
        $empty = ['month' => null, 'headers' => [], 'people' => [], 'total' => [], 'errors' => [], 'warnings' => []];
        $reader = IOFactory::createReaderForFile($path);
        $names = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [];
        $sheetName = null;
        $month = null;
        foreach ($names as $n) {
            if (preg_match(self::SHEET_PATTERN, trim($n), $m)) {
                $sheetName = $n;
                $month = sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
                break;
            }
        }
        if ($sheetName === null) {
            return ['errors' => ['「딜러수수료_YYYY년MM월」 탭을 찾지 못했습니다 — 평가수수료정산 파일인지 확인하세요.']] + $empty;
        }
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$sheetName]);
        $ws = $reader->load($path)->getSheetByName($sheetName);

        [$headerRow, $nameCol] = $this->findHeader($ws);
        if ($headerRow === null) {
            return ['month' => $month, 'errors' => ['「평가사」 머리글이 있는 줄을 찾지 못했습니다.']] + $empty;
        }
        // 항목 이름 — 「평가사」 오른쪽으로 빈 칸이 나올 때까지
        $headers = [];
        $cols = [];
        for ($c = $nameCol + 1; $c <= $nameCol + 30; $c++) {
            $label = trim((string) $this->value($ws->getCell([$c, $headerRow])));
            if ($label === '') {
                break;
            }
            $headers[] = $label;
            $cols[] = $c;
        }
        $payoutIdx = array_search(self::PAYOUT_HEADER, $headers, true);
        $errors = [];
        if ($payoutIdx === false) {
            $errors[] = '「'.self::PAYOUT_HEADER.'」 항목이 없습니다 — 송금액을 정할 수 없습니다.';
        }

        $people = [];
        $total = [];
        for ($r = $headerRow + 1; $r <= $headerRow + 200; $r++) {
            $name = trim((string) $this->value($ws->getCell([$nameCol, $r])));
            if ($name === '') {
                break;
            }
            $values = array_map(fn ($c) => $this->number($this->value($ws->getCell([$c, $r]))), $cols);
            if ($name === self::TOTAL_LABEL) {
                $total = $values;
                break;
            }
            $people[] = [
                'row' => $r,
                'name' => $name,
                'values' => $values,
                'payout' => $payoutIdx === false ? 0 : (int) round((float) ($values[$payoutIdx] ?? 0)),
            ];
        }

        $warnings = [];
        if ($total === []) {
            $warnings[] = '「합계」 줄이 없어 검산하지 못했습니다.';
        } else {
            foreach ($headers as $i => $h) {
                $sum = array_sum(array_map(fn ($p) => (float) ($p['values'][$i] ?? 0), $people));
                if (abs($sum - (float) ($total[$i] ?? 0)) > 0.5) {
                    $warnings[] = "「{$h}」 사람별 합(".number_format($sum).')이 합계 줄('.number_format((float) ($total[$i] ?? 0)).')과 다릅니다.';
                }
            }
        }

        return compact('month', 'headers', 'people', 'total', 'errors', 'warnings');
    }

    /**
     * 이름 → 내수직원 salesman_id(못 찾으면 null). 활성 내수직원 중 이름 완전일치(공백 무시), 동명이인이면 null.
     *
     * @param  list<array{row:int, name:string}>  $people
     * @return array<int, ?int> row => id
     */
    public function match(array $people): array
    {
        $norm = fn ($s) => preg_replace('/\s+/u', '', (string) $s);
        $byName = Salesman::query()->where('type', 'domestic_staff')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('left_at'))
            ->get(['id', 'name'])->groupBy(fn ($s) => $norm($s->name));
        $out = [];
        foreach ($people as $p) {
            $hits = $byName->get($norm($p['name']));
            $out[$p['row']] = ($hits && $hits->count() === 1) ? (int) $hits->first()->id : null;
        }

        return $out;
    }

    /** @return array{0:?int,1:?int} [헤더 행, 이름 열 번호] */
    private function findHeader(Worksheet $ws): array
    {
        foreach ($ws->getRowIterator() as $row) {
            $it = $row->getCellIterator();
            $it->setIterateOnlyExistingCells(true);
            foreach ($it as $cell) {
                if (trim((string) $this->value($cell)) === self::NAME_HEADER) {
                    return [$row->getRowIndex(), Coordinate::columnIndexFromString($cell->getColumn())];
                }
            }
        }

        return [null, null];
    }

    /** 수식 칸은 엑셀이 저장해 둔 계산값, 아니면 값 그대로. */
    private function value(Cell $cell): mixed
    {
        return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    private function number(mixed $v): int|float|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return $v + 0;
        }
        $s = str_replace([',', ' '], '', (string) $v);

        return is_numeric($s) ? $s + 0 : null;
    }
}
